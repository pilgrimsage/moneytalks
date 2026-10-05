<?php

namespace App\Console\Commands;

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerService;
use App\Enums\AccountSubtype;
use App\Enums\EntityType;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\User;
use App\Models\UserAlias;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Console\Command;

class CreateAccount extends Command
{
    protected $signature = 'moneytalks:account:create
        {wa_id : Owner WhatsApp ID}
        {name : e.g. "HDFC Bank"}
        {subtype : cash|bank|wallet|credit_card|loan|investment|goal}
        {--opening= : Opening balance as a decimal, e.g. 52340.50 (credit cards/loans: amount owed)}
        {--alias=* : Extra names you will use for it, e.g. --alias=hdfc}
        {--default : Make this the default account}';

    protected $description = 'Create a ledger account (and optionally its opening balance)';

    public function handle(AccountService $accounts, LedgerService $ledger): int
    {
        $user = User::findByWaId((string) $this->argument('wa_id'));
        if (! $user) {
            $this->error('No such user. Run moneytalks:user:create first.');

            return self::FAILURE;
        }

        $subtype = AccountSubtype::tryFrom((string) $this->argument('subtype'));
        if (! $subtype) {
            $this->error('Unknown subtype.');

            return self::FAILURE;
        }

        try {
            $account = $accounts->create($user, (string) $this->argument('name'), $subtype);

            foreach (array_merge([$account->name], (array) $this->option('alias')) as $alias) {
                UserAlias::firstOrCreate(
                    ['user_id' => $user->id, 'entity_type' => EntityType::Account->value, 'alias' => Text::normalize($alias)],
                    ['entity_id' => $account->id, 'source' => 'user'],
                );
            }

            if ($this->option('default')) {
                $user->settings()->update(['default_account_id' => $account->id]);
            }

            if ($this->option('opening') !== null) {
                $ledger->post(new PostingCommand(
                    userId: $user->id,
                    type: TransactionType::OpeningBalance,
                    money: Money::parse((string) $this->option('opening'), $user->base_currency),
                    occurredOn: now($user->timezone),
                    accountId: $account->id,
                    idempotencyKey: 'opening:'.$account->id,
                    description: 'Opening balance',
                    source: TransactionSource::Manual,
                ));
            }
        } catch (LedgerException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Account '{$account->name}' ({$subtype->value}) created: {$accounts->balance($account)->format(true)}");

        return self::SUCCESS;
    }
}

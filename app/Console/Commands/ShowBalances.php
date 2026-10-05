<?php

namespace App\Console\Commands;

use App\Domain\Ledger\AccountService;
use App\Enums\AccountKind;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Console\Command;

class ShowBalances extends Command
{
    protected $signature = 'moneytalks:balances {wa_id}';

    protected $description = "Show a user's account balances and net worth";

    public function handle(AccountService $accounts): int
    {
        $user = User::findByWaId((string) $this->argument('wa_id'));
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $balances = $accounts->balances($user->id);
        $rows = [];
        $assets = Money::zero($user->base_currency);
        $liabilities = Money::zero($user->base_currency);

        foreach (LedgerAccount::where('user_id', $user->id)->orderBy('kind')->orderBy('name')->get() as $a) {
            $b = $balances[$a->id];
            $rows[] = [$a->kind->value, $a->subtype->value, $a->name, $b->format(true)];
            if ($a->kind === AccountKind::Asset) {
                $assets = $assets->add($b);
            } elseif ($a->kind === AccountKind::Liability) {
                $liabilities = $liabilities->add($b);
            }
        }

        $this->table(['Kind', 'Type', 'Account', 'Balance'], $rows);
        $this->line('Net worth (assets - liabilities): '.$assets->subtract($liabilities)->format(true));

        return self::SUCCESS;
    }
}

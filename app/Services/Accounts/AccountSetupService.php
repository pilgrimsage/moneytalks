<?php

namespace App\Services\Accounts;

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerService;
use App\Enums\AccountSubtype;
use App\Enums\EntityType;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\UserAlias;
use App\Services\Interpretation\AmountNormalizer;
use App\Services\Interpretation\EntityResolver;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Add the HDFC Bank account with 52340" and "my opening balance on HDFC is 52340", from chat.
 *
 * plan() validates (no writes) and returns either a plan or a question for the user; apply() runs only after the
 * user's Confirm tap. The opening balance is an ordinary ledger posting keyed per confirm tap, and plan()/apply() refuse a second live
 * opening balance on the same account (including one made by the console command).
 */
class AccountSetupService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly LedgerService $ledger,
        private readonly EntityResolver $resolver,
        private readonly AmountNormalizer $amounts,
    ) {}

    /**
     * @param  array<string, mixed>  $item  the model's validated item (account = the name, amount = the opening balance, if any)
     * @return array{plan: array{name: string, existing_id: ?string, subtype: string, opening_minor: ?int}}|array{reason: string, message: string}
     */
    public function plan(User $user, array $item, string $text): array
    {
        $name = $this->cleanName((string) ($item['account'] ?? $item['to_account'] ?? ''));
        if ($name === '') {
            return $this->ask('account_missing', 'Which account? For example "add HDFC Bank with 52340" or "opening balance on HDFC is 52340".');
        }

        $openingMinor = null;
        if (($item['amount'] ?? null) !== null) {
            try {
                $money = Money::parse((string) $item['amount'], $user->base_currency);
            } catch (InvalidArgumentException) {
                return $this->ask('amount_invalid', "I couldn't read the amount. What is the opening balance of {$name}?");
            }
            if ($money->minor < 0 || $money->minor > (int) config('ai.risk.max_amount_minor')) {
                return $this->ask('amount_invalid', "That amount does not look right. What is the opening balance of {$name}?");
            }
            // A balance sets the books for good, so it must be provably in the message.
            if ($money->minor > 0 && $this->amounts->matches((string) $item['amount'], $text) !== true) {
                return $this->ask('amount_not_in_text', "I read {$money->format()}, but I can't find that amount in your message. What is the opening balance of {$name}?");
            }
            $openingMinor = $money->minor > 0 ? $money->minor : null;
        }

        // Accounts only ever match exactly: a near miss is a question, never a silent pick (or a silent duplicate).
        $found = $this->resolver->resolve($user->id, EntityType::Account, $name);
        if ($found->isAmbiguous() || ($found->isResolved() && $found->matchType === 'fuzzy')) {
            $guess = $found->name ?? ($found->candidates[0]['name'] ?? null);

            return $this->ask('account_ambiguous', 'You already have a similar account'.($guess ? ": *{$guess}*" : '').'. Use its exact name to set its opening balance, or give the new account a different name.');
        }

        if ($found->isResolved()) {
            $account = LedgerAccount::where('user_id', $user->id)->find($found->entityId);
            if (! $account) {
                return $this->ask('account_unknown', "I couldn't find that account.");
            }
            if ($openingMinor === null) {
                return $this->ask('amount_missing', "What is the opening balance of {$account->name}?");
            }
            if ($this->openingExists($account)) {
                return $this->ask('opening_exists', "{$account->name} already has an opening balance. Undo that entry first if you want to change it, then tell me the new amount.");
            }

            return ['plan' => ['name' => $account->name, 'existing_id' => $account->id, 'subtype' => (string) $account->subtype->value, 'opening_minor' => $openingMinor]];
        }

        return ['plan' => ['name' => $name, 'existing_id' => null, 'subtype' => $this->subtypeFor($name, $item['payment_method'] ?? null)->value, 'opening_minor' => $openingMinor]];
    }

    /** Create the account if it is new and post its opening balance. Called after the Confirm tap. */
    public function apply(User $user, array $plan, string $key): string
    {
        $openingMinor = isset($plan['opening_minor']) ? (int) $plan['opening_minor'] : null;

        try {
            [$account, $created] = DB::transaction(function () use ($user, $plan, $openingMinor, $key) {
                $created = false;
                $account = ($plan['existing_id'] ?? null) ? LedgerAccount::where('user_id', $user->id)->find($plan['existing_id']) : null;
                if (! $account) {
                    $account = $this->accounts->create($user, (string) $plan['name'], AccountSubtype::from((string) $plan['subtype']));
                    foreach (array_unique([Text::normalize($account->name)]) as $alias) {
                        UserAlias::firstOrCreate(
                            ['user_id' => $user->id, 'entity_type' => EntityType::Account->value, 'alias' => $alias],
                            ['entity_id' => $account->id, 'source' => 'user'],
                        );
                    }
                    $created = true;
                }

                if ($openingMinor !== null) {
                    if (! $created && $this->openingExists($account)) {
                        throw new LedgerException("{$account->name} already has an opening balance.");
                    }
                    $this->ledger->post(new PostingCommand(
                        userId: $user->id,
                        type: TransactionType::OpeningBalance,
                        money: Money::ofMinor($openingMinor, $account->currency),
                        occurredOn: now($user->timezone)->format('Y-m-d'),
                        accountId: $account->id,
                        idempotencyKey: 'opening:'.$account->id.':'.$key, // one per confirm tap: a re-tap posts once, a re-set after an undo posts again
                        description: 'Opening balance',
                        source: TransactionSource::WhatsappText,
                    ));
                }

                return [$account, $created];
            });
        } catch (LedgerException|InvalidArgumentException $e) {
            return $e->getMessage(); // domain refusals are written for the user ("already exists", "reserved name")
        }

        $balance = $this->accounts->balance($account)->format();
        $kind = str_replace('_', ' ', (string) $account->subtype->value);

        return match (true) {
            $created && $openingMinor !== null => "🏦 Added *{$account->name}* ({$kind}) with an opening balance of {$balance}.",
            $created => "🏦 Added *{$account->name}* ({$kind}), no opening balance yet.",
            default => "🏦 Opening balance set on *{$account->name}*. Balance now {$balance}.",
        };
    }

    /** The Confirm prompt for a plan. */
    public function describe(User $user, array $plan): string
    {
        $amount = isset($plan['opening_minor']) ? Money::ofMinor((int) $plan['opening_minor'], $user->base_currency)->format() : null;
        $kind = str_replace('_', ' ', (string) $plan['subtype']);

        return ($plan['existing_id'] ?? null)
            ? "Set the opening balance of {$plan['name']} to {$amount}?"
            : "Add account {$plan['name']} ({$kind})".($amount ? " with an opening balance of {$amount}" : '').'?';
    }

    private function openingExists(LedgerAccount $account): bool
    {
        // 'opening:{id}' is the console command's key; 'opening:{id}:confirm:...' is the chat flow's. Account ids are fixed-length ULIDs.
        return LedgerTransaction::where('idempotency_key', 'like', 'opening:'.$account->id.'%')->whereNull('reversed_by_id')->exists();
    }

    private function subtypeFor(string $name, mixed $paymentMethod): AccountSubtype
    {
        $n = Text::normalize($name);

        return match (true) {
            $paymentMethod === 'credit_card', (bool) preg_match('/\b(credit card|cc)\b/', $n) => AccountSubtype::CreditCard,
            $paymentMethod === 'wallet', (bool) preg_match('/\b(wallet|paytm|phonepe|gpay|amazon pay)\b/', $n) => AccountSubtype::Wallet,
            $paymentMethod === 'cash', $n === 'cash' || (bool) preg_match('/\bcash\b/', $n) => AccountSubtype::Cash,
            default => AccountSubtype::Bank,
        };
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = trim(preg_replace('/\s+account$/iu', '', $name) ?? $name);
        if ($name === mb_strtolower($name, 'UTF-8')) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }

        return mb_substr($name, 0, 40, 'UTF-8');
    }

    /** @return array{reason: string, message: string} */
    private function ask(string $reason, string $message): array
    {
        return ['reason' => $reason, 'message' => $message];
    }
}

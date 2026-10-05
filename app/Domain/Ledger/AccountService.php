<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Enums\AccountKind;
use App\Enums\AccountSubtype;
use App\Models\Counterparty;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /** key => [name, kind]. Created per user; names are reserved. */
    public const SYSTEM = [
        'expenses' => ['Expenses', AccountKind::Expense],
        'income' => ['Income', AccountKind::Income],
        'opening' => ['Opening Balances', AccountKind::Equity],
        'adjustments' => ['Reconciliation Adjustments', AccountKind::Equity],
    ];

    /** @return array<string, LedgerAccount> keyed by self::SYSTEM keys */
    public function ensureSystemAccounts(User $user): array
    {
        $out = [];
        foreach (self::SYSTEM as $key => [$name, $kind]) {
            $out[$key] = LedgerAccount::firstOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                ['kind' => $kind->value, 'subtype' => AccountSubtype::System->value, 'currency' => $user->base_currency, 'is_system' => true],
            );
        }

        return $out;
    }

    public function create(User $user, string $name, AccountSubtype $subtype, ?string $currency = null): LedgerAccount
    {
        $name = trim($name);
        $kind = $subtype->kind();

        if ($name === '') {
            throw new LedgerException('Account name is required.');
        }
        if ($kind === null) {
            throw new LedgerException('System accounts cannot be created manually.');
        }
        if (in_array($subtype, [AccountSubtype::Receivable, AccountSubtype::Payable], true)) {
            throw new LedgerException('Receivable/payable accounts are created per person by the debt service.');
        }
        foreach (self::SYSTEM as [$reserved]) {
            if (mb_strtolower($reserved) === mb_strtolower($name)) {
                throw new LedgerException("'{$name}' is a reserved account name.");
            }
        }
        if (LedgerAccount::where('user_id', $user->id)->where('name', $name)->exists()) {
            throw new LedgerException("An account named '{$name}' already exists.");
        }

        return LedgerAccount::create([
            'user_id' => $user->id,
            'kind' => $kind->value,
            'subtype' => $subtype->value,
            'name' => $name,
            'currency' => Money::ofMinor(0, $currency ?? $user->base_currency)->currency,
        ]);
    }

    /**
     * The receivable ("they owe me") or payable ("I owe them") account for one person; created on first use.
     */
    public function personAccount(User $user, Counterparty $person, AccountSubtype $subtype): LedgerAccount
    {
        if (! in_array($subtype, [AccountSubtype::Receivable, AccountSubtype::Payable], true)) {
            throw new LedgerException('Only receivable or payable accounts are per person.');
        }
        if ($person->user_id !== $user->id) {
            throw new LedgerException('Unknown person.');
        }
        $kind = $subtype->kind();

        return LedgerAccount::firstOrCreate(
            ['user_id' => $user->id, 'counterparty_id' => $person->id, 'kind' => $kind->value],
            [
                'subtype' => $subtype->value, 'currency' => $user->base_currency,
                'name' => ($subtype === AccountSubtype::Receivable ? 'Receivable: ' : 'Payable: ').$person->name,
            ],
        );
    }

    /** Balance in the account's natural sign: assets/expenses = debits - credits, others = credits - debits. */
    public function balance(LedgerAccount $account): Money
    {
        return $this->balances($account->user_id, [$account->id])[$account->id]
            ?? Money::zero($account->currency);
    }

    /**
     * @param  list<string>|null  $accountIds  null = all of the user's accounts
     * @return array<string, Money> account id => balance (accounts with no entries yield zero)
     */
    public function balances(string $userId, ?array $accountIds = null): array
    {
        $accounts = LedgerAccount::where('user_id', $userId)
            ->when($accountIds !== null, fn ($q) => $q->whereIn('id', $accountIds))
            ->get()->keyBy('id');

        $net = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds))
            ->groupBy('account_id')
            ->selectRaw("account_id, SUM(CASE direction WHEN 'D' THEN amount_minor ELSE -amount_minor END) AS net")
            ->pluck('net', 'account_id');

        $out = [];
        foreach ($accounts as $id => $account) {
            $debitsMinusCredits = (int) ($net[$id] ?? 0);
            $minor = $account->kind->isDebitNormal() ? $debitsMinusCredits : -$debitsMinusCredits;
            $out[$id] = Money::ofMinor($minor, $account->currency);
        }

        return $out;
    }
}

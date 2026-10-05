<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Enums\AccountSubtype;
use App\Enums\Direction;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Support\Money;

beforeEach(function () {
    $this->user = ledgerUser();
    $this->cash = account($this->user, 'Cash');
    $this->accounts = app(AccountService::class);
    $this->bank = $this->accounts->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
    $this->card = $this->accounts->create($this->user, 'HDFC Credit Card', AccountSubtype::CreditCard);
});

it('records a cash expense as Dr Expenses [category] / Cr Cash', function () {
    $veg = category($this->user, 'Vegetables');
    $result = ledger()->post(command($this->user, TransactionType::Expense, '250', [
        'categoryId' => $veg->id, 'description' => 'Vegetables', 'paymentMethod' => PaymentMethod::Cash,
    ]));

    $entries = $result->transaction->entries;
    expect($result->replayed)->toBeFalse()
        ->and($entries)->toHaveCount(2)
        ->and($entries[0]->account->name)->toBe('Expenses')
        ->and($entries[0]->direction)->toBe(Direction::Debit)
        ->and($entries[0]->category_id)->toBe($veg->id)
        ->and($entries[1]->account_id)->toBe($this->cash->id)
        ->and($entries[1]->direction)->toBe(Direction::Credit)
        ->and($entries->sum('amount_minor'))->toBe(50000)
        ->and($result->transaction->debit_total_minor)->toBe($result->transaction->credit_total_minor)
        ->and($result->transaction->entries_hash)->toHaveLength(64)
        ->and(balanceOf($this->cash))->toBe(-25000)
        ->and(balanceOf(account($this->user, 'Expenses')))->toBe(25000);
});

it('records income as Dr account / Cr Income [category]', function () {
    ledger()->post(command($this->user, TransactionType::Income, '45000', [
        'accountId' => $this->bank->id, 'categoryId' => category($this->user, 'Salary')->id,
    ]));

    expect(balanceOf($this->bank))->toBe(4500000)
        ->and(balanceOf(account($this->user, 'Income')))->toBe(4500000);
});

it('moves money between own accounts without touching income or expense', function () {
    ledger()->post(command($this->user, TransactionType::OpeningBalance, '10000', ['accountId' => $this->bank->id]));
    ledger()->post(command($this->user, TransactionType::Transfer, '1000', [
        'accountId' => $this->bank->id, 'toAccountId' => $this->cash->id,
    ]));

    expect(balanceOf($this->bank))->toBe(900000)
        ->and(balanceOf($this->cash))->toBe(100000)
        ->and(balanceOf(account($this->user, 'Expenses')))->toBe(0)
        ->and(balanceOf(account($this->user, 'Income')))->toBe(0);
});

it('records a credit-card purchase as an expense plus card liability', function () {
    ledger()->post(command($this->user, TransactionType::Expense, '3000', [
        'accountId' => $this->card->id, 'categoryId' => category($this->user, 'Shopping')->id,
    ]));

    expect(balanceOf($this->card))->toBe(300000)              // owed to the bank
        ->and(balanceOf(account($this->user, 'Expenses')))->toBe(300000)
        ->and(balanceOf($this->bank))->toBe(0);
});

it('opens asset accounts with Dr account and liability accounts with Cr account', function () {
    ledger()->post(command($this->user, TransactionType::OpeningBalance, '52340.50', ['accountId' => $this->bank->id]));
    ledger()->post(command($this->user, TransactionType::OpeningBalance, '12450', ['accountId' => $this->card->id]));

    expect(balanceOf($this->bank))->toBe(5234050)
        ->and(balanceOf($this->card))->toBe(1245000);          // owed, shown positive
});

it('writes an audit record for every posting', function () {
    $tx = ledger()->post(command($this->user, TransactionType::Expense, '10'))->transaction;

    $log = AuditLog::where('subject_id', $tx->id)->first();
    expect($log->action)->toBe('transaction.posted')
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->after['amount_minor'])->toBe(1000);
});

it('is idempotent: the same key never posts twice', function () {
    $cmd = command($this->user, TransactionType::Expense, '500', ['idempotencyKey' => 'wamid.ABC:0']);

    $first = ledger()->post($cmd);
    $again = ledger()->post($cmd);
    $third = ledger()->post(command($this->user, TransactionType::Expense, '999', ['idempotencyKey' => 'wamid.ABC:0']));

    expect($first->replayed)->toBeFalse()
        ->and($again->replayed)->toBeTrue()
        ->and($again->transaction->id)->toBe($first->transaction->id)
        ->and($third->transaction->id)->toBe($first->transaction->id)   // content ignored on replay
        ->and(LedgerTransaction::count())->toBe(1)
        ->and(LedgerEntry::count())->toBe(2)
        ->and(balanceOf($this->cash))->toBe(-50000);
});

it('refuses to replay another user\'s idempotency key', function () {
    ledger()->post(command($this->user, TransactionType::Expense, '5', ['idempotencyKey' => 'shared-key']));
    $other = ledgerUser('919111111111');

    expect(fn () => ledger()->post(command($other, TransactionType::Expense, '5', ['idempotencyKey' => 'shared-key'])))
        ->toThrow(LedgerException::class);
});

it('rolls back everything if a write fails midway', function () {
    LedgerEntry::creating(fn (LedgerEntry $e) => $e->position === 1 ? throw new RuntimeException('disk full') : null);

    try {
        expect(fn () => ledger()->post(command($this->user, TransactionType::Expense, '77')))->toThrow(RuntimeException::class);
    } finally {
        LedgerEntry::getEventDispatcher()->forget('eloquent.creating: '.LedgerEntry::class);
    }

    expect(LedgerTransaction::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('rejects invalid requests and writes nothing', function (string $label, Closure $make) {
    expect(fn () => ledger()->post($make($this)))->toThrow(LedgerException::class);
    expect(LedgerTransaction::count())->toBe(0);
})->with([
    'zero amount' => ['zero', fn ($t) => command($t->user, TransactionType::Expense, '0')],
    'negative amount' => ['negative', fn ($t) => command($t->user, TransactionType::Expense, '-5')],
    'bad calendar date' => ['date', fn ($t) => command($t->user, TransactionType::Expense, '5', ['occurredOn' => '2026-02-31'])],
    'garbled date' => ['date2', fn ($t) => command($t->user, TransactionType::Expense, '5', ['occurredOn' => 'yesterday'])],
    'empty idempotency key' => ['key', fn ($t) => command($t->user, TransactionType::Expense, '5', ['idempotencyKey' => ''])],
    'unknown account' => ['acct', fn ($t) => command($t->user, TransactionType::Expense, '5', ['accountId' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])],
    'transfer to self' => ['self', fn ($t) => command($t->user, TransactionType::Transfer, '5', ['accountId' => $t->cash->id, 'toAccountId' => $t->cash->id])],
    'transfer without destination' => ['nodest', fn ($t) => command($t->user, TransactionType::Transfer, '5')],
    'destination on an expense' => ['dest', fn ($t) => command($t->user, TransactionType::Expense, '5', ['toAccountId' => $t->bank->id])],
    'income into a credit card' => ['inc-cc', fn ($t) => command($t->user, TransactionType::Income, '5', ['accountId' => $t->card->id])],
    'transfer into a credit card (M8)' => ['tr-cc', fn ($t) => command($t->user, TransactionType::Transfer, '5', ['accountId' => $t->cash->id, 'toAccountId' => $t->card->id])],
    'income category on an expense' => ['catkind', fn ($t) => command($t->user, TransactionType::Expense, '5', ['categoryId' => category($t->user, 'Salary')->id])],
    'expense category on income' => ['catkind2', fn ($t) => command($t->user, TransactionType::Income, '5', ['categoryId' => category($t->user, 'Fuel')->id])],
    'category on a transfer' => ['cattr', fn ($t) => command($t->user, TransactionType::Transfer, '5', ['accountId' => $t->cash->id, 'toAccountId' => $t->bank->id, 'categoryId' => category($t->user, 'Fuel')->id])],
    'unknown category' => ['cat', fn ($t) => command($t->user, TransactionType::Expense, '5', ['categoryId' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])],
    'other currency' => ['usd', fn ($t) => command($t->user, TransactionType::Expense, '5', ['money' => Money::parse('5', 'USD')])],
    'reversal posted directly' => ['rev', fn ($t) => command($t->user, TransactionType::Reversal, '5')],
    'opening balance on an expense account' => ['open', fn ($t) => command($t->user, TransactionType::OpeningBalance, '5', ['accountId' => account($t->user, 'Expenses')->id])],
]);

it('never lets one user touch another user\'s accounts or categories', function () {
    $other = ledgerUser('919111111111');

    expect(fn () => ledger()->post(command($this->user, TransactionType::Expense, '5', ['accountId' => account($other, 'Cash')->id])))
        ->toThrow(LedgerException::class)
        ->and(fn () => ledger()->post(command($this->user, TransactionType::Expense, '5', ['categoryId' => category($other, 'Fuel')->id])))
        ->toThrow(LedgerException::class);

    expect(LedgerTransaction::count())->toBe(0);
});

it('manages accounts with sensible rules', function () {
    expect(fn () => $this->accounts->create($this->user, 'cash', AccountSubtype::Cash))->toThrow(LedgerException::class)      // duplicate, case-insensitive collation
        ->and(fn () => $this->accounts->create($this->user, 'Expenses', AccountSubtype::Bank))->toThrow(LedgerException::class) // reserved
        ->and(fn () => $this->accounts->create($this->user, 'x', AccountSubtype::System))->toThrow(LedgerException::class)
        ->and(fn () => $this->accounts->create($this->user, 'Rahul', AccountSubtype::Receivable))->toThrow(LedgerException::class)
        ->and(fn () => $this->accounts->create($this->user, '  ', AccountSubtype::Bank))->toThrow(LedgerException::class);

    $before = LedgerAccount::count();
    $this->accounts->ensureSystemAccounts($this->user);
    expect(LedgerAccount::count())->toBe($before);
});

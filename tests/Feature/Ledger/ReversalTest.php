<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Enums\AccountSubtype;
use App\Enums\Direction;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;

beforeEach(function () {
    $this->user = ledgerUser();
    $this->cash = account($this->user, 'Cash');
    $this->bank = app(AccountService::class)->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
    $this->groceries = category($this->user, 'Groceries');
});

function postGroceries(string $amount = '500', array $o = [])
{
    return ledger()->post(command(test()->user, TransactionType::Expense, $amount, [
        'categoryId' => test()->groceries->id, 'occurredOn' => '2026-09-30',
    ] + $o))->transaction;
}

it('undoes a transaction by posting its mirror image', function () {
    $original = postGroceries('500');
    $result = ledger()->reverse($this->user->id, $original->id, 'user asked to undo');
    $reversal = $result->transaction;

    expect(balanceOf($this->cash))->toBe(0)
        ->and(balanceOf(account($this->user, 'Expenses')))->toBe(0)
        ->and($reversal->type)->toBe(TransactionType::Reversal)
        ->and($reversal->reversal_of_id)->toBe($original->id)
        ->and($reversal->occurred_on->format('Y-m-d'))->toBe('2026-09-30')   // same period as the original
        ->and($reversal->entries->map(fn ($e) => $e->direction)->all())->toBe([Direction::Credit, Direction::Debit])
        ->and($reversal->entries[0]->category_id)->toBe($this->groceries->id)
        ->and($original->fresh()->status)->toBe(TransactionStatus::Reversed)
        ->and($original->fresh()->reversed_by_id)->toBe($reversal->id);
});

it('keeps the full history: nothing is deleted', function () {
    $original = postGroceries();
    ledger()->reverse($this->user->id, $original->id, 'undo');

    expect(LedgerTransaction::count())->toBe(2)
        ->and(LedgerEntry::count())->toBe(4)
        ->and(AuditLog::where('action', 'transaction.reversed')->where('subject_id', $original->id)->exists())->toBeTrue();
});

it('reverses at most once', function () {
    $original = postGroceries();
    $first = ledger()->reverse($this->user->id, $original->id, 'undo');
    $second = ledger()->reverse($this->user->id, $original->id, 'undo again');   // idempotent replay

    expect($second->replayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and(LedgerTransaction::count())->toBe(2)
        ->and(balanceOf($this->cash))->toBe(0);
});

it('refuses to reverse a reversal or a missing or foreign transaction', function () {
    $original = postGroceries();
    $reversal = ledger()->reverse($this->user->id, $original->id, 'undo')->transaction;
    $other = ledgerUser('919111111111');

    expect(fn () => ledger()->reverse($this->user->id, $reversal->id, 'x'))->toThrow(LedgerException::class)
        ->and(fn () => ledger()->reverse($this->user->id, '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'x'))->toThrow(LedgerException::class)
        ->and(fn () => ledger()->reverse($other->id, $original->id, 'x'))->toThrow(LedgerException::class);
});

it('reverses transfers and opening balances too', function () {
    $open = ledger()->post(command($this->user, TransactionType::OpeningBalance, '1000', ['accountId' => $this->bank->id]))->transaction;
    $move = ledger()->post(command($this->user, TransactionType::Transfer, '400', ['accountId' => $this->bank->id, 'toAccountId' => $this->cash->id]))->transaction;

    ledger()->reverse($this->user->id, $move->id, 'undo');
    expect(balanceOf($this->bank))->toBe(100000)->and(balanceOf($this->cash))->toBe(0);

    ledger()->reverse($this->user->id, $open->id, 'undo');
    expect(balanceOf($this->bank))->toBe(0);
});

it('corrects a transaction: "actually that was 600, not 500"', function () {
    $wrong = postGroceries('500');

    $result = ledger()->correct($wrong->id, command($this->user, TransactionType::Expense, '600', [
        'categoryId' => $this->groceries->id, 'occurredOn' => '2026-09-30',
    ]), 'amount corrected');

    expect(balanceOf($this->cash))->toBe(-60000)
        ->and(balanceOf(account($this->user, 'Expenses')))->toBe(60000)
        ->and($result->transaction->corrects_id)->toBe($wrong->id)
        ->and($wrong->fresh()->status)->toBe(TransactionStatus::Reversed)
        ->and(LedgerTransaction::count())->toBe(3);   // original, reversal, replacement
});

it('can move a correction to another account or category', function () {
    $wrong = postGroceries('500');

    ledger()->correct($wrong->id, command($this->user, TransactionType::Expense, '500', [
        'accountId' => $this->bank->id, 'categoryId' => category($this->user, 'Restaurant')->id,
    ]), 'wrong account');

    expect(balanceOf($this->cash))->toBe(0)
        ->and(balanceOf($this->bank))->toBe(-50000);
});

it('is atomic: an invalid replacement leaves the original untouched', function () {
    $original = postGroceries('500');

    expect(fn () => ledger()->correct($original->id, command($this->user, TransactionType::Expense, '0'), 'bad'))
        ->toThrow(LedgerException::class);

    expect($original->fresh()->status)->toBe(TransactionStatus::Posted)
        ->and(LedgerTransaction::count())->toBe(1)
        ->and(balanceOf($this->cash))->toBe(-50000);
});

it('cannot correct a transaction twice', function () {
    $original = postGroceries('500');
    ledger()->correct($original->id, command($this->user, TransactionType::Expense, '600'), 'first');

    expect(fn () => ledger()->correct($original->id, command($this->user, TransactionType::Expense, '700'), 'second'))
        ->toThrow(LedgerException::class);
});

it('treats a retried correction as a replay, not a second correction', function () {
    $original = postGroceries('500');
    $replacement = command($this->user, TransactionType::Expense, '600', ['categoryId' => $this->groceries->id]);

    $first = ledger()->correct($original->id, $replacement, 'fix');
    $retry = ledger()->correct($original->id, $replacement, 'fix');

    expect($retry->replayed)->toBeTrue()
        ->and($retry->transaction->id)->toBe($first->transaction->id)
        ->and(LedgerTransaction::count())->toBe(3)
        ->and(balanceOf($this->cash))->toBe(-60000);
});

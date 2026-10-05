<?php

use App\Domain\Ledger\LedgerVerifier;
use App\Enums\TransactionType;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = ledgerUser();
    $this->tx = ledger()->post(command($this->user, TransactionType::Expense, '250', [
        'categoryId' => category($this->user, 'Vegetables')->id,
    ]))->transaction;
    ledger()->post(command($this->user, TransactionType::Income, '1000', ['categoryId' => category($this->user, 'Salary')->id]));
    $this->verifier = app(LedgerVerifier::class);
});

it('passes on a healthy ledger, including reversals and corrections', function () {
    $r = ledger()->reverse($this->user->id, $this->tx->id, 'undo')->transaction;
    ledger()->correct(
        ledger()->post(command($this->user, TransactionType::Expense, '40'))->transaction->id,
        command($this->user, TransactionType::Expense, '45'),
        'fix',
    );

    expect($this->verifier->verify())->toBe([])
        ->and($r->type)->toBe(TransactionType::Reversal);
});

it('detects an entry slipped in behind the ledger\'s back', function () {
    // INSERT is not blocked by the immutability triggers; the verifier is the safety net.
    $e = LedgerEntry::first();
    DB::table('ledger_entries')->insert([
        'transaction_id' => $this->tx->id, 'user_id' => $this->user->id, 'account_id' => $e->account_id,
        'direction' => 'D', 'amount_minor' => 999, 'currency' => 'INR', 'position' => 5,
    ]);

    $issues = implode("\n", $this->verifier->verify());
    expect($issues)->toContain($this->tx->id)
        ->and($issues)->toContain('disagree with header')
        ->and($issues)->toContain('hash mismatch');
});

it('detects an altered entry when the host has no triggers', function () {
    if (dbHasLedgerTriggers()) {
        $this->markTestSkipped('Triggers block this tampering outright (covered in ImmutabilityTest).');
    }

    DB::table('ledger_entries')->where('transaction_id', $this->tx->id)->where('position', 0)->update(['amount_minor' => 1]);

    expect(implode("\n", $this->verifier->verify()))->toContain('hash mismatch');
});

it('detects a transaction marked reversed without a real reversal', function () {
    DB::table('ledger_transactions')->where('id', $this->tx->id)->update(['status' => 'reversed']);

    expect(implode("\n", $this->verifier->verify()))->toContain('no matching reversal');
});

it('detects a forged reversal link', function () {
    $other = ledger()->post(command($this->user, TransactionType::Expense, '5'))->transaction;
    DB::table('ledger_transactions')->where('id', $this->tx->id)->update(['status' => 'reversed', 'reversed_by_id' => $other->id]);

    expect(implode("\n", $this->verifier->verify()))->toContain('no matching reversal');
});

it('can be scoped to a single user', function () {
    $other = ledgerUser('919111111111');
    ledger()->post(command($other, TransactionType::Expense, '5'));
    DB::table('ledger_transactions')->where('id', $this->tx->id)->update(['status' => 'reversed']);

    expect($this->verifier->verify($other->id))->toBe([])
        ->and($this->verifier->verify($this->user->id))->not->toBe([]);
});

it('exposes the check as an artisan command with an exit code', function () {
    $this->artisan('moneytalks:ledger:verify')->expectsOutput('Ledger OK.')->assertSuccessful();

    DB::table('ledger_transactions')->where('id', $this->tx->id)->update(['status' => 'reversed']);

    $this->artisan('moneytalks:ledger:verify')->assertFailed();
});

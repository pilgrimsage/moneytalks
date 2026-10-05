<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = ledgerUser();
    $this->tx = ledger()->post(command($this->user, TransactionType::Expense, '250', [
        'categoryId' => category($this->user, 'Vegetables')->id,
    ]))->transaction;
});

describe('application guards', function () {
    it('refuses to update or delete entries', function () {
        $entry = LedgerEntry::first();

        expect(function () use ($entry) {
            $entry->amount_minor = 1;
            $entry->save();
        })->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);
    });

    it('refuses to change posted transaction facts', function (string $column, mixed $value) {
        $tx = LedgerTransaction::find($this->tx->id);
        $tx->{$column} = $value;

        expect(fn () => $tx->save())->toThrow(LogicException::class);
    })->with([
        ['debit_total_minor', 1],
        ['occurred_on', '2020-01-01'],
        ['description', 'rewritten history'],
        ['idempotency_key', 'other'],
        ['entries_hash', str_repeat('0', 64)],
    ]);

    it('refuses to delete transactions', function () {
        expect(fn () => LedgerTransaction::find($this->tx->id)->delete())->toThrow(LogicException::class);
    });

    it('allows only the status transition fields to change', function () {
        $tx = LedgerTransaction::find($this->tx->id);
        $tx->status = TransactionStatus::Voided;
        $tx->save();

        expect($tx->fresh()->status)->toBe(TransactionStatus::Voided);
    });

    it('keeps audit logs append-only', function () {
        $log = AuditLog::first();

        expect(function () use ($log) {
            $log->reason = 'edited';
            $log->save();
        })->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class);
    });
});

describe('database constraints', function () {
    function rawTx(array $o = []): array
    {
        return $o + [
            'id' => '01HRAWRAWRAWRAWRAWRAWRAW01', 'user_id' => test()->user->id, 'type' => 'expense', 'status' => 'posted',
            'occurred_on' => '2026-10-04', 'occurred_at' => now(), 'source' => 'manual', 'idempotency_key' => 'raw-1',
            'currency' => 'INR', 'base_currency' => 'INR', 'base_amount_minor' => 100, 'exchange_rate' => 1,
            'debit_total_minor' => 100, 'credit_total_minor' => 100, 'entries_hash' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    it('rejects an unbalanced header (CHECK debit = credit)', function () {
        expect(fn () => DB::table('ledger_transactions')->insert(rawTx(['debit_total_minor' => 100, 'credit_total_minor' => 200])))
            ->toThrow(QueryException::class);
    });

    it('rejects a zero-value header', function () {
        expect(fn () => DB::table('ledger_transactions')->insert(rawTx(['debit_total_minor' => 0, 'credit_total_minor' => 0])))
            ->toThrow(QueryException::class);
    });

    it('rejects non-positive and mis-directed entries', function (int $amount, string $direction) {
        $e = LedgerEntry::first();
        $row = ['transaction_id' => $this->tx->id, 'user_id' => $this->user->id, 'account_id' => $e->account_id,
            'direction' => $direction, 'amount_minor' => $amount, 'currency' => 'INR', 'position' => 9];

        expect(fn () => DB::table('ledger_entries')->insert($row))->toThrow(QueryException::class);
    })->with([[0, 'D'], [-5, 'C'], [5, 'X']]);

    it('rejects an entry that points at another user\'s account (composite FK)', function () {
        $other = ledgerUser('919111111111');
        $row = ['transaction_id' => $this->tx->id, 'user_id' => $this->user->id, 'account_id' => account($other, 'Cash')->id,
            'direction' => 'D', 'amount_minor' => 5, 'currency' => 'INR', 'position' => 9];

        expect(fn () => DB::table('ledger_entries')->insert($row))->toThrow(QueryException::class);
    });

    it('rejects a duplicate idempotency key', function () {
        expect(fn () => DB::table('ledger_transactions')->insert(rawTx(['idempotency_key' => $this->tx->idempotency_key])))
            ->toThrow(QueryException::class);
    });

    it('blocks raw UPDATE/DELETE of entries when the host allowed triggers', function () {
        if (! dbHasLedgerTriggers()) {
            $this->markTestSkipped('This database user could not install triggers (expected on some shared hosts).');
        }

        expect(fn () => DB::table('ledger_entries')->update(['amount_minor' => 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('ledger_entries')->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('ledger_transactions')->update(['description' => 'x']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('ledger_transactions')->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('audit_logs')->update(['reason' => 'x']))->toThrow(QueryException::class);
    });
});

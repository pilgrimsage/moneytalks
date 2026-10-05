<?php

namespace App\Domain\Ledger;

use App\Enums\TransactionStatus;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Re-checks the whole ledger from the database. Run nightly (and after restores): it is the
 * safety net for anything that bypassed the application (raw SQL, restored backups, hosts that
 * would not install the immutability triggers).
 */
class LedgerVerifier
{
    /** @return list<string> human-readable problems; empty means the ledger is consistent */
    public function verify(?string $userId = null): array
    {
        $issues = [];
        $scope = fn ($q, string $col = 'user_id') => $userId ? $q->where($col, $userId) : $q;

        // 1. Headers must balance.
        $scope(DB::table('ledger_transactions')->whereColumn('debit_total_minor', '!=', 'credit_total_minor'))
            ->pluck('id')->each(fn ($id) => $issues[] = "Transaction {$id}: header debit != credit");

        // 2. Header totals must equal the sum of the entries, per direction.
        $rows = $scope(DB::table('ledger_transactions as t')
            ->leftJoin('ledger_entries as e', 'e.transaction_id', '=', 't.id')
            ->groupBy('t.id', 't.debit_total_minor', 't.credit_total_minor')
            ->selectRaw("t.id, t.debit_total_minor, t.credit_total_minor,
                COALESCE(SUM(CASE e.direction WHEN 'D' THEN e.amount_minor END), 0) AS d,
                COALESCE(SUM(CASE e.direction WHEN 'C' THEN e.amount_minor END), 0) AS c,
                COUNT(e.id) AS n"), 't.user_id')->get();

        foreach ($rows as $r) {
            if ($r->n === 0) {
                $issues[] = "Transaction {$r->id}: has no entries";
            } elseif ((int) $r->d !== (int) $r->debit_total_minor || (int) $r->c !== (int) $r->credit_total_minor) {
                $issues[] = "Transaction {$r->id}: entries (D {$r->d} / C {$r->c}) disagree with header (D {$r->debit_total_minor} / C {$r->credit_total_minor})";
            }
        }

        // 3. Tamper evidence: recompute each transaction's entries hash.
        $scope(LedgerTransaction::query())->orderBy('id')->chunk(500, function ($txs) use (&$issues) {
            $entries = LedgerEntry::whereIn('transaction_id', $txs->pluck('id'))->orderBy('position')->get()->groupBy('transaction_id');
            foreach ($txs as $tx) {
                if (LedgerHasher::forEntries($tx->user_id, $entries->get($tx->id, collect())) !== $tx->entries_hash) {
                    $issues[] = "Transaction {$tx->id}: entries hash mismatch (entries were altered)";
                }
            }
        });

        // 4. Reversal links must agree in both directions.
        $scope(DB::table('ledger_transactions')->where('status', TransactionStatus::Reversed->value))->get()->each(function ($t) use (&$issues) {
            /** @var object|null $rev */
            $rev = $t->reversed_by_id ? DB::table('ledger_transactions')->find($t->reversed_by_id) : null;
            if (! $rev || $rev->reversal_of_id !== $t->id) {
                $issues[] = "Transaction {$t->id}: marked reversed but has no matching reversal";
            }
        });
        $scope(DB::table('ledger_transactions')->where('type', 'reversal'))->get()->each(function ($t) use (&$issues) {
            /** @var object|null $orig */
            $orig = $t->reversal_of_id ? DB::table('ledger_transactions')->find($t->reversal_of_id) : null;
            if (! $orig || $orig->reversed_by_id !== $t->id || $orig->status !== TransactionStatus::Reversed->value) {
                $issues[] = "Transaction {$t->id}: reversal does not match its original";
            }
        });

        // 5. Globally (per user): total debits == total credits.
        $totals = $scope(DB::table('ledger_entries')->groupBy('user_id')
            ->selectRaw("user_id, SUM(CASE direction WHEN 'D' THEN amount_minor ELSE 0 END) AS d, SUM(CASE direction WHEN 'C' THEN amount_minor ELSE 0 END) AS c"))->get();
        foreach ($totals as $t) {
            if ((int) $t->d !== (int) $t->c) {
                $issues[] = "User {$t->user_id}: total debits {$t->d} != total credits {$t->c}";
            }
        }

        // 6. Debts: what each person owes (or is owed) in the ledger must equal their open debt records.
        $people = $scope(DB::table('ledger_accounts')->whereIn('subtype', ['receivable', 'payable']))->get();
        if ($people->isNotEmpty()) {
            $net = DB::table('ledger_entries')->whereIn('account_id', $people->pluck('id'))->groupBy('account_id')
                ->selectRaw("account_id, SUM(CASE direction WHEN 'D' THEN amount_minor ELSE -amount_minor END) AS net")->pluck('net', 'account_id');
            $recorded = DB::table('debt_records as r')
                ->leftJoin('debt_settlements as s', fn ($j) => $j->on('s.debt_record_id', '=', 'r.id')->whereNull('s.voided_at'))
                ->whereIn('r.status', ['open', 'partial', 'settled'])
                ->groupBy('r.id', 'r.user_id', 'r.counterparty_id', 'r.direction', 'r.original_minor')
                ->selectRaw('r.user_id, r.counterparty_id, r.direction, r.original_minor - COALESCE(SUM(s.amount_minor), 0) AS remaining')->get();
            $open = [];
            foreach ($recorded as $r) {
                $k = $r->user_id.'|'.$r->counterparty_id.'|'.$r->direction;
                $open[$k] = ($open[$k] ?? 0) + (int) $r->remaining;
            }
            foreach ($people as $a) {
                $balance = (int) ($net[$a->id] ?? 0) * ($a->subtype === 'receivable' ? 1 : -1);
                $want = $open[$a->user_id.'|'.$a->counterparty_id.'|'.$a->subtype] ?? 0;
                if ($balance !== $want) {
                    $issues[] = "Account {$a->id} ({$a->name}): ledger balance {$balance} != open debt records {$want}";
                }
            }
        }

        return $issues;
    }
}

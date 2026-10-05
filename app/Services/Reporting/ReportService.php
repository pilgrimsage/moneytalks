<?php

namespace App\Services\Reporting;

use App\Models\Category;
use App\Models\LedgerTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every number in every report comes from here: plain SQL over the ledger, scoped to one user, with a
 * fixed set of filters. No model output ever reaches a query, and no number is ever written by a model.
 *
 * Semantics: spending = debits minus credits on the user's expense accounts; income = credits minus debits on income
 * accounts. Transfers, lending, borrowing and card payments never touch those accounts, so they are excluded by
 * construction. Reversed transactions are included together with their reversal (dated the same day), so they net to
 * exactly zero; corrections therefore show only the corrected amount.
 */
class ReportService
{
    private const NET = "CASE e.direction WHEN 'D' THEN e.amount_minor ELSE -e.amount_minor END";

    /** @return array{expense_minor: int, income_minor: int, expense_count: int, income_count: int} */
    public function totals(string $userId, Period $period, ?ReportQuery $filters = null): array
    {
        $rows = $this->base($userId, $period, $filters)
            ->whereIn('a.kind', ['expense', 'income'])
            ->groupBy('a.kind')
            ->selectRaw('a.kind, SUM('.self::NET.") AS net, COUNT(DISTINCT CASE WHEN t.status = 'posted' AND t.type <> 'reversal' THEN t.id END) AS n")
            ->get()->keyBy('kind');

        return [
            'expense_minor' => (int) ($rows['expense']->net ?? 0),
            'income_minor' => -(int) ($rows['income']->net ?? 0),
            'expense_count' => (int) ($rows['expense']->n ?? 0),
            'income_count' => (int) ($rows['income']->n ?? 0),
        ];
    }

    /**
     * Spending (or income) split by a dimension.
     *
     * @return list<array{key: string, label: string, minor: int}> largest first (time dimensions: oldest first)
     */
    public function breakdown(string $userId, Period $period, string $side, string $groupBy, ?ReportQuery $filters = null, bool $rollup = true): array
    {
        $sign = $side === 'income' ? -1 : 1;
        $q = $this->base($userId, $period, $filters)->where('a.kind', $side);

        switch ($groupBy) {
            case 'category':
                $q->groupBy('e.category_id')->selectRaw('e.category_id AS k, SUM('.self::NET.') AS net');
                break;
            case 'merchant':
                $q->groupBy('t.merchant_id')->selectRaw('t.merchant_id AS k, SUM('.self::NET.') AS net');
                break;
            case 'payment_method':
                $q->groupBy('t.payment_method')->selectRaw('t.payment_method AS k, SUM('.self::NET.') AS net');
                break;
            case 'account': // the asset/liability account on the other side of the entry
                $q->join('ledger_entries as c', 'c.transaction_id', '=', 't.id')
                    ->join('ledger_accounts as ca', fn ($j) => $j->on('ca.id', '=', 'c.account_id')->whereIn('ca.kind', ['asset', 'liability']))
                    ->groupBy('ca.name')->selectRaw('ca.name AS k, SUM('.self::NET.') AS net');
                break;
            case 'day':
                $q->groupBy('t.occurred_on')->selectRaw('t.occurred_on AS k, SUM('.self::NET.') AS net');
                break;
            case 'week': // Monday of the week
                $q->groupByRaw('DATE_SUB(t.occurred_on, INTERVAL WEEKDAY(t.occurred_on) DAY)')
                    ->selectRaw('DATE_SUB(t.occurred_on, INTERVAL WEEKDAY(t.occurred_on) DAY) AS k, SUM('.self::NET.') AS net');
                break;
            case 'month':
                $q->groupByRaw("DATE_FORMAT(t.occurred_on, '%Y-%m')")->selectRaw("DATE_FORMAT(t.occurred_on, '%Y-%m') AS k, SUM(".self::NET.') AS net');
                break;
            default:
                throw new \InvalidArgumentException("Unsupported grouping: {$groupBy}");
        }

        $rows = $q->get()->map(fn ($r) => ['key' => (string) ($r->k ?? ''), 'minor' => $sign * (int) $r->net]);

        if ($groupBy === 'category' && $rollup) {
            $rows = $this->rollUpCategories($userId, $rows);
        }

        $rows = $rows->groupBy('key')->map(fn (Collection $g, string|int $k) => ['key' => (string) $k, 'minor' => (int) $g->sum('minor')])->values();
        $rows = $this->label($userId, $groupBy, $rows)->filter(fn ($r) => $r['minor'] !== 0);

        return in_array($groupBy, ['day', 'week', 'month'], true)
            ? $rows->sortBy('key')->values()->all()
            : $rows->sortByDesc('minor')->values()->all();
    }

    /**
     * Individual transactions, newest first. Only posted, non-reversal transactions.
     *
     * @return Collection<int, LedgerTransaction>
     */
    public function transactions(string $userId, Period $period, ?ReportQuery $filters, int $limit, string $orderBy = 'recent', ?string $type = null)
    {
        $q = LedgerTransaction::where('user_id', $userId)
            ->where('status', 'posted')->where('type', '!=', 'reversal')
            ->whereBetween('occurred_on', [$period->startDate(), $period->endDate()]);

        if ($type) {
            $q->where('type', $type);
        }
        if ($filters) {
            $this->applyTransactionFilters($q, $filters, $userId);
        }

        $orderBy === 'amount'
            ? $q->orderByDesc('debit_total_minor')->orderByDesc('occurred_on')
            : $q->orderByDesc('occurred_on')->orderByDesc('created_at')->orderByDesc('id');

        return $q->limit($limit)->get();
    }

    public function countTransactions(string $userId, Period $period, ?ReportQuery $filters, ?string $type = null): int
    {
        $q = LedgerTransaction::where('user_id', $userId)->where('status', 'posted')->where('type', '!=', 'reversal')
            ->whereBetween('occurred_on', [$period->startDate(), $period->endDate()]);
        if ($type) {
            $q->where('type', $type);
        }
        if ($filters) {
            $this->applyTransactionFilters($q, $filters, $userId);
        }

        return $q->count();
    }

    /** Entry-level query joined to transactions and accounts, restricted to one user, a date range and the filters. */
    private function base(string $userId, Period $period, ?ReportQuery $f): Builder
    {
        $q = DB::table('ledger_entries as e')
            ->join('ledger_transactions as t', 't.id', '=', 'e.transaction_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('t.user_id', $userId)
            ->whereBetween('t.occurred_on', [$period->startDate(), $period->endDate()]);

        if ($f) {
            if ($f->categoryIds) {
                $q->whereIn('e.category_id', $f->categoryIds);
            }
            if ($f->merchantIds) {
                $q->whereIn('t.merchant_id', $f->merchantIds);
            }
            if ($f->paymentMethod) {
                $q->where('t.payment_method', $f->paymentMethod);
            }
            if ($f->accountIds) {
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('ledger_entries as fe')->whereColumn('fe.transaction_id', 't.id')->whereIn('fe.account_id', $f->accountIds));
            }
            if ($f->searchText) {
                $like = '%'.addcslashes($f->searchText, '%_\\').'%';
                $q->where(fn ($w) => $w->where('t.description', 'like', $like)
                    ->orWhereIn('t.merchant_id', DB::table('merchants')->select('id')->where('user_id', $userId)->where('name', 'like', $like)));
            }
        }

        return $q;
    }

    private function applyTransactionFilters($q, ReportQuery $f, string $userId): void
    {
        if ($f->categoryIds) {
            $q->whereIn('id', DB::table('ledger_entries')->select('transaction_id')->whereIn('category_id', $f->categoryIds));
        }
        if ($f->merchantIds) {
            $q->whereIn('merchant_id', $f->merchantIds);
        }
        if ($f->paymentMethod) {
            $q->where('payment_method', $f->paymentMethod);
        }
        if ($f->accountIds) {
            $q->whereIn('id', DB::table('ledger_entries')->select('transaction_id')->whereIn('account_id', $f->accountIds));
        }
        if ($f->searchText) {
            $like = '%'.addcslashes($f->searchText, '%_\\').'%';
            $q->where(fn ($w) => $w->where('description', 'like', $like)
                ->orWhereIn('merchant_id', DB::table('merchants')->select('id')->where('user_id', $userId)->where('name', 'like', $like)));
        }
    }

    /** Fold sub-categories into their top-level parent ("Vegetables" -> "Food"). */
    private function rollUpCategories(string $userId, Collection $rows): Collection
    {
        $parents = Category::where('user_id', $userId)->pluck('parent_id', 'id');

        return $rows->map(function ($r) use ($parents) {
            $id = $r['key'];
            for ($i = 0; $id !== '' && ($parents[$id] ?? null) !== null && $i < 10; $i++) {
                $id = $parents[$id];
            }

            return ['key' => $id, 'minor' => $r['minor']];
        });
    }

    /** @param Collection<int, array{key: string, minor: int}> $rows */
    private function label(string $userId, string $groupBy, Collection $rows): Collection
    {
        $names = match ($groupBy) {
            'category' => Category::where('user_id', $userId)->pluck('name', 'id'),
            'merchant' => DB::table('merchants')->where('user_id', $userId)->pluck('name', 'id'),
            default => collect(),
        };

        return $rows->map(fn ($r) => $r + ['label' => match (true) {
            $groupBy === 'category' => $r['key'] === '' ? 'Uncategorised' : ($names[$r['key']] ?? 'Other'),
            $groupBy === 'merchant' => $r['key'] === '' ? 'No merchant' : ($names[$r['key']] ?? 'Other'),
            $groupBy === 'payment_method' => $r['key'] === '' ? 'Not specified' : ucfirst(str_replace('_', ' ', $r['key'])),
            default => $r['key'],
        }]);
    }
}

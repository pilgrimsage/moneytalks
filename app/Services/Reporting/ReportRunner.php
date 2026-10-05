<?php

namespace App\Services\Reporting;

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\DebtService;
use App\Models\Category;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use App\Services\Goals\GoalService;
use App\Services\Loans\LoanService;
use App\Services\Recurring\RecurringService;
use App\Services\WhatsApp\ReplyFormatter;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Runs a planned ReportQuery with SQL and formats the answer. No AI is involved anywhere on this path. */
class ReportRunner
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportFormatter $formatter,
        private readonly ReplyFormatter $replies,
        private readonly AccountService $accounts,
        private readonly DebtService $debts,
        private readonly BudgetService $budgets,
        private readonly RecurringService $recurring,
        private readonly GoalService $goals,
        private readonly LoanService $loans,
    ) {}

    public function run(User $user, ReportQuery $q): string
    {
        $f = $this->formatter->forCurrency($user->base_currency);
        $filters = $q->hasFilters() ? $q : null;

        return match ($q->metric) {
            'balance', 'net_worth' => $this->replies->balances($user, $this->accounts),
            'owed_to_me', 'i_owe' => $this->owed($user, $q, $f),
            'budget_status' => $f->budgets($this->budgets->status($user, CarbonImmutable::now('UTC'))),
            'loans' => $f->loans($this->loans->status($user)),
            'goals' => $f->goals($this->goals->status($user, CarbonImmutable::now('UTC'))),
            'subscriptions' => $f->subscriptions($this->recurring->subscriptions($user)),
            'upcoming_bills' => $f->upcoming($this->recurring->upcoming($user, CarbonImmutable::now('UTC'))),
            'summary' => $this->summary($user, $q, $f),
            'total_spend', 'total_income' => $f->total($q, $this->reports->totals($user->id, $q->period, $filters)),
            'breakdown' => $this->breakdown($user, $q, $f, $filters),
            'compare' => $f->compare($q, $this->reports->totals($user->id, $q->period, $filters), $this->reports->totals($user->id, $q->compare, $filters)),
            'biggest_expenses' => $this->list($user, $q, $f, 'amount', 'Biggest expenses', 'expense'),
            'list' => $this->list($user, $q, $f, 'recent', 'Transactions', null),
            default => 'I can\'t answer that kind of question yet.',
        };
    }

    private function owed(User $user, ReportQuery $q, ReportFormatter $f): string
    {
        $mine = $q->metric === 'owed_to_me';
        $rows = $this->debts->openByPerson($user->id, $mine ? DebtService::RECEIVABLE : DebtService::PAYABLE, $q->counterpartyId);

        return $f->owed($mine, $rows, $q->counterpartyName);
    }

    private function summary(User $user, ReportQuery $q, ReportFormatter $f): string
    {
        $t = $this->reports->totals($user->id, $q->period);
        $top = array_slice($this->reports->breakdown($user->id, $q->period, 'expense', 'category'), 0, 5);
        $big = $this->reports->transactions($user->id, $q->period, null, 1, 'amount', 'expense')->first();

        return $f->summary($q->period, $t, $top, $big, $big ? $this->line($user, $big, true) : null);
    }

    private function breakdown(User $user, ReportQuery $q, ReportFormatter $f, ?ReportQuery $filters): string
    {
        $rows = $this->reports->breakdown($user->id, $q->period, $q->side, (string) $q->groupBy, $filters);
        $t = $this->reports->totals($user->id, $q->period, $filters);

        return $f->breakdown($q, $rows, $q->side === 'income' ? $t['income_minor'] : $t['expense_minor']);
    }

    private function list(User $user, ReportQuery $q, ReportFormatter $f, string $order, string $title, ?string $type): string
    {
        $filters = $q->hasFilters() ? $q : null;
        $txs = $this->reports->transactions($user->id, $q->period, $filters, $q->limit, $order, $type);
        $total = $this->reports->countTransactions($user->id, $q->period, $filters, $type);

        return $f->transactions($q, $txs->map(fn ($t) => ['date' => $t->occurred_on->format('Y-m-d'), 'text' => $this->line($user, $t)])->values(), $total, $title);
    }

    /** "₹250 · Vegetables · 3 Oct" (the date is dropped when asked for the single biggest expense line). */
    public function line(User $user, LedgerTransaction $t, bool $withNote = false): string
    {
        $amount = Money::ofMinor((int) $t->debit_total_minor, $t->currency)->format();
        $catId = $t->entries()->whereNotNull('category_id')->value('category_id');
        $cat = $catId ? Category::where('user_id', $user->id)->where('id', $catId)->value('name') : null;
        $when = CarbonImmutable::parse($t->occurred_on)->format('j M');
        $label = $cat ?? ($t->type->value === 'transfer' ? 'Transfer' : 'Uncategorised');
        $note = $t->description && mb_strtolower($t->description) !== mb_strtolower((string) $label) ? ' ('.mb_substr($t->description, 0, 40, 'UTF-8').')' : '';

        return $withNote ? "{$amount} {$label}{$note}, {$when}" : "{$when} · {$amount} · {$label}{$note}";
    }
}

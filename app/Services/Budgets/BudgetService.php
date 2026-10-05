<?php

namespace App\Services\Budgets;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Category;
use App\Models\User;
use App\Services\Interpretation\AmountNormalizer;
use App\Services\Interpretation\EntityResolver;
use App\Services\Reporting\CategoryTree;
use App\Services\Reporting\PeriodResolver;
use App\Services\Reporting\ReportQuery;
use App\Services\Reporting\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Monthly spending limits and the heads-up when you approach or pass one. Spent amounts come from ReportService
 * (SQL over the ledger); the model only proposes the limit and the category, and the amount must appear in the text.
 */
class BudgetService
{
    public const THRESHOLDS = [80, 100];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly AmountNormalizer $amounts,
        private readonly ReportService $reports,
        private readonly PeriodResolver $periods,
        private readonly CategoryTree $tree,
    ) {}

    /** Handle a create_budget item (set, change or remove a budget). Returns the reply text. */
    public function apply(User $user, array $item, string $text, CarbonImmutable $now): string
    {
        $categoryId = null;
        $label = 'Overall';
        if (($named = trim((string) ($item['category'] ?? ''))) !== '') {
            $r = $this->resolver->resolve($user->id, EntityType::Category, $named, CategoryKind::Expense);
            if (! $r->isResolved() || $r->matchType === 'fuzzy') {
                return "I don't have an expense category called \"{$named}\", so I didn't set a budget. Try one of your categories, or leave it out for an overall budget.";
            }
            $categoryId = $r->entityId;
            $label = (string) $r->name;
        }
        $existing = $this->find($user, $categoryId);

        if (($item['action'] ?? null) === 'remove') {
            if (! $existing) {
                return "You don't have a {$label} budget.";
            }
            $existing->update(['status' => 'removed']);

            return "🗑️ Removed the {$label} budget.";
        }

        if (($item['amount'] ?? null) === null) {
            return "How much should the {$label} budget be per month? For example \"budget 5000 for food\".";
        }
        try {
            $money = Money::parse((string) $item['amount'], $user->base_currency);
        } catch (InvalidArgumentException) {
            return 'I couldn\'t read the budget amount. How much per month?';
        }
        if (! $money->isPositive() || $money->minor > (int) config('ai.risk.max_amount_minor')) {
            return 'That budget amount does not look right. How much per month?';
        }
        if ($this->amounts->matches((string) $item['amount'], $text) === false) {
            return "I read {$money->format()}, but I can't find that amount in your message. How much per month?";
        }

        if ($existing) {
            $existing->update(['amount_minor' => $money->minor]);
        } else {
            $existing = Budget::create(['user_id' => $user->id, 'category_id' => $categoryId, 'amount_minor' => $money->minor, 'currency' => $user->base_currency, 'status' => 'active']);
        }

        $row = $this->row($user, $existing, $now);

        return "✅ {$label} budget set to {$money->format()} per month.\nSo far this month: ".Money::ofMinor($row['spent'], $user->base_currency)->format().' ('.$row['pct'].'%).';
    }

    /**
     * @return list<array{name: string, limit: int, spent: int, pct: int, left: int}> biggest overshoot first
     */
    public function status(User $user, CarbonImmutable $now): array
    {
        $rows = Budget::where('user_id', $user->id)->where('status', 'active')->get()->map(fn (Budget $b) => $this->row($user, $b, $now))->all();
        usort($rows, fn ($a, $b) => [$b['pct'], $a['name']] <=> [$a['pct'], $b['name']]);

        return $rows;
    }

    /**
     * After an expense was recorded: the heads-up lines for budgets it just crossed (80%, 100%), once per month each.
     *
     * @return list<string>
     */
    public function alertsAfterExpense(User $user, ?string $categoryId, string $occurredOn, CarbonImmutable $now): array
    {
        $month = $now->setTimezone($user->timezone)->format('Y-m');
        if (substr($occurredOn, 0, 7) !== $month) {
            return []; // a backdated entry does not trigger this month's alerts
        }

        $covering = $categoryId ? $this->tree->withAncestors($user->id, $categoryId) : [];
        $budgets = Budget::where('user_id', $user->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('category_id')->when($covering !== [], fn ($w) => $w->orWhereIn('category_id', $covering)))->get();

        $lines = [];
        foreach ($budgets as $b) {
            $row = $this->row($user, $b, $now);
            $crossed = array_values(array_filter(self::THRESHOLDS, fn ($t) => $row['pct'] >= $t));
            $fresh = null;
            foreach ($crossed as $t) {
                try {
                    BudgetAlert::create(['user_id' => $user->id, 'budget_id' => $b->id, 'month' => $month, 'threshold' => $t]);
                    $fresh = $t; // the highest newly announced one wins
                } catch (UniqueConstraintViolationException) {
                    // already announced this month
                }
            }
            if ($fresh !== null) {
                $spent = Money::ofMinor($row['spent'], $user->base_currency)->format();
                $limit = Money::ofMinor($row['limit'], $user->base_currency)->format();
                $lines[] = $fresh >= 100
                    ? "🚨 {$row['name']} budget exceeded: {$spent} of {$limit} this month."
                    : "⚠️ {$row['name']} budget: {$spent} of {$limit} used ({$row['pct']}%), ".Money::ofMinor($row['left'], $user->base_currency)->format().' left.';
            }
        }

        return $lines;
    }

    private function find(User $user, ?string $categoryId): ?Budget
    {
        return Budget::where('user_id', $user->id)->where('status', 'active')
            ->when($categoryId === null, fn ($q) => $q->whereNull('category_id'), fn ($q) => $q->where('category_id', $categoryId))->first();
    }

    /** @return array{name: string, limit: int, spent: int, pct: int, left: int} */
    private function row(User $user, Budget $b, CarbonImmutable $now): array
    {
        $period = $this->periods->resolve(['kind' => 'this_month'], $user->timezone, $now);
        $filters = $b->category_id
            ? new ReportQuery('total_spend', $period, categoryIds: $this->tree->withDescendants($user->id, $b->category_id))
            : null;
        $spent = $this->reports->totals($user->id, $period, $filters)['expense_minor'];

        return [
            'name' => $b->category_id ? (string) Category::where('user_id', $user->id)->whereKey($b->category_id)->value('name') : 'Overall',
            'limit' => $b->amount_minor, 'spent' => $spent,
            'pct' => intdiv(max(0, $spent) * 100, $b->amount_minor), 'left' => max(0, $b->amount_minor - $spent),
        ];
    }
}

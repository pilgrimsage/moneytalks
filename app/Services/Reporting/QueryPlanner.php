<?php

namespace App\Services\Reporting;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Enums\PaymentMethod;
use App\Models\User;
use App\Services\AI\Prompts\TransactionParser as P;
use App\Services\Interpretation\Decision;
use App\Services\Interpretation\EntityResolver;
use Carbon\CarbonImmutable;

/**
 * Turns the model's query item into a whitelisted ReportQuery, or into a question / honest "not available" decision.
 * Names are resolved against the user's own records; the model never supplies ids, SQL or numbers.
 */
class QueryPlanner
{
    /** Metrics the model may name that need features that do not exist yet. */
    private const UNAVAILABLE = [
        'affordability' => 'affordability questions',
    ];

    public const MAX_LIMIT = 20;

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly PeriodResolver $periods,
        private readonly CategoryTree $tree,
    ) {}

    /** @return array{0: ?ReportQuery, 1: ?Decision} exactly one is non-null */
    public function plan(User $user, array $item, int $index, CarbonImmutable $now): array
    {
        $metric = $item['query_metric'] ?? null;
        $metric ??= ($item['intent'] ?? '') === 'report' ? 'summary' : null;

        if ($metric === null) {
            return [null, Decision::clarify($index, 'query_metric_missing', 'What would you like to know? For example "how much did I spend this month?" or "show my food spending".')];
        }
        if (! in_array($metric, P::QUERY_METRICS, true)) {
            return [null, Decision::unsupported($index, 'query_not_available', 'I can\'t answer that kind of question yet.')];
        }
        if (isset(self::UNAVAILABLE[$metric])) {
            return [null, Decision::unsupported($index, 'query_not_available', 'I can\'t answer questions about '.self::UNAVAILABLE[$metric].' yet. Nothing was changed.')];
        }

        $tz = $user->timezone;
        if (in_array($metric, ['owed_to_me', 'i_owe'], true)) {
            $personId = $personName = null;
            if (($named = $this->clean($item['counterparty'] ?? null)) !== null) {
                $r = $this->resolver->resolve($user->id, EntityType::Counterparty, $named);
                if (! $r->isResolved() || $r->matchType === 'fuzzy') {
                    return [null, Decision::clarify($index, 'person_unknown', "I don't have a person called \"{$named}\" in your records.")];
                }
                $personId = $r->entityId;
                $personName = $r->name;
            }

            return [new ReportQuery($metric, $this->periods->resolve(['kind' => 'today'], $tz, $now), counterpartyId: $personId, counterpartyName: $personName), null];
        }
        if (in_array($metric, ['balance', 'net_worth', 'budget_status', 'goals', 'loans', 'subscriptions', 'upcoming_bills'], true)) {
            $period = $this->periods->resolve(['kind' => 'today'], $tz, $now);

            return [new ReportQuery($metric, $period), null];
        }

        $period = $this->periods->resolve($item['period'] ?? null, $tz, $now);
        if ($period === null) {
            return [null, Decision::clarify($index, 'period_invalid', 'Which period do you mean? For example "this month", "last week" or "October".')];
        }

        $compare = null;
        if ($metric === 'compare') {
            $compare = isset($item['compare_period'])
                ? $this->periods->resolve($item['compare_period'], $tz, $now)
                : $this->previous($period);
            if ($compare === null) {
                return [null, Decision::clarify($index, 'period_invalid', 'Which two periods should I compare? For example "this month vs last month".')];
            }
        }

        $side = ($metric === 'total_income' || ($item['event_type'] ?? null) === 'income') ? 'income' : 'expense';
        $kind = $side === 'income' ? CategoryKind::Income : CategoryKind::Expense;
        $categoryIds = [];
        $categoryName = null;
        $merchantIds = [];
        $merchantName = null;
        $accountIds = [];
        $accountName = null;
        $search = $this->clean($item['search_text'] ?? null);

        if (($named = $this->clean($item['category'] ?? null)) !== null) {
            $r = $this->resolver->resolve($user->id, EntityType::Category, $named, $kind);
            if ($r->isResolved()) {
                $categoryIds = $this->withDescendants($user->id, $r->entityId);
                $categoryName = $r->name;
            } elseif ($r->isAmbiguous()) {
                return [null, Decision::clarify($index, 'category_ambiguous', 'Which category did you mean by "'.$named.'": '.implode(' or ', array_column($r->candidates, 'name')).'?')];
            } else {
                return [null, Decision::clarify($index, 'category_unknown', "I don't have a category called \"{$named}\", so I didn't want to guess. Try another name, or ask without a category.")];
            }
        }

        if (($named = $this->clean($item['merchant'] ?? null)) !== null) {
            $r = $this->resolver->resolve($user->id, EntityType::Merchant, $named);
            if ($r->isResolved()) {
                $merchantIds = [$r->entityId];
                $merchantName = $r->name;
            } else {
                $search ??= $named; // unknown shop: fall back to matching the text of descriptions
            }
        }

        if (($named = $this->clean($item['account'] ?? null)) !== null) {
            $r = $this->resolver->resolve($user->id, EntityType::Account, $named);
            if ($r->isResolved() && $r->matchType !== 'fuzzy') {
                $accountIds = [$r->entityId];
                $accountName = $r->name;
            } else {
                return [null, Decision::clarify($index, 'account_unknown', "I couldn't match \"{$named}\" to one of your accounts, so I didn't guess.")];
            }
        }

        $method = isset($item['payment_method']) ? PaymentMethod::tryFrom((string) $item['payment_method']) : null;
        $groupBy = in_array($item['group_by'] ?? null, P::GROUP_BYS, true) ? $item['group_by'] : null;
        $default = in_array($metric, ['list'], true) ? 10 : 5;
        $limit = max(1, min(self::MAX_LIMIT, (int) ($item['limit'] ?? 0) ?: $default));

        return [new ReportQuery(
            metric: $metric, period: $period, compare: $compare,
            categoryIds: $categoryIds, categoryName: $categoryName,
            merchantIds: $merchantIds, merchantName: $merchantName,
            accountIds: $accountIds, accountName: $accountName,
            paymentMethod: $method?->value, searchText: $search !== null ? mb_substr($search, 0, 60, 'UTF-8') : null,
            groupBy: $groupBy ?? 'category', limit: $limit, side: $side,
        ), null];
    }

    /** The period immediately before: previous month/year for calendar periods, otherwise the same length just before. */
    public function previous(Period $p): Period
    {
        $start = $p->start;
        if ($start->day === 1 && $p->end->isSameDay($start->endOfMonth()) || ($start->day === 1 && $start->month !== 1 && $p->end->month === $start->month && $p->partial)) {
            $prev = $start->subMonthNoOverflow();

            return new Period($prev, $prev->endOfMonth()->startOfDay(), $prev->format('F Y'));
        }
        if ($start->month === 1 && $start->day === 1 && $p->end->year === $start->year) {
            $prev = $start->subYear();

            return new Period($prev, $prev->endOfYear()->startOfDay(), $prev->format('Y'));
        }
        $days = $start->diffInDays($p->end) + 1;
        $pe = $start->subDay();
        $ps = $pe->subDays($days - 1);

        return new Period($ps, $pe, $ps->format('j M').' – '.$pe->format('j M Y'));
    }

    /** @return list<string> */
    private function withDescendants(string $userId, string $id): array
    {
        return $this->tree->withDescendants($userId, $id);
    }

    private function clean(mixed $v): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');

        return $s === '' ? null : $s;
    }
}

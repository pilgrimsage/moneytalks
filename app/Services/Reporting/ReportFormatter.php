<?php

namespace App\Services\Reporting;

use App\Models\LedgerTransaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic WhatsApp text for reports. Every figure is passed in from ReportService; nothing here
 * (and nothing from a model) computes or rewrites a number except display maths such as percentages.
 */
class ReportFormatter
{
    private const MAX_CHARS = 3800;

    public function __construct(private readonly string $currency = 'INR') {}

    public function forCurrency(string $currency): self
    {
        return new self($currency);
    }

    public function money(int $minor): string
    {
        return Money::ofMinor($minor, $this->currency)->format();
    }

    /**
     * @param  array{expense_minor: int, income_minor: int, expense_count: int, income_count: int}  $t
     * @param  list<array{key: string, label: string, minor: int}>  $top
     */
    public function summary(Period $p, array $t, array $top, ?LedgerTransaction $biggest, ?string $biggestText): string
    {
        if ($t['expense_count'] + $t['income_count'] === 0) {
            return $this->empty($p);
        }
        $saved = $t['income_minor'] - $t['expense_minor'];
        $lines = ["📊 *{$p->label}*", '',
            'Income: '.$this->money($t['income_minor']),
            'Expenses: '.$this->money($t['expense_minor']),
            ($saved >= 0 ? 'Saved: ' : 'Overspent by: ').$this->money(abs($saved)).($t['income_minor'] > 0 && $saved >= 0 ? ' ('.intdiv($saved * 100, $t['income_minor']).'% of income)' : ''),
        ];
        if ($top !== [] && $t['expense_minor'] > 0) {
            $lines[] = '';
            $lines[] = '*Top spending*';
            foreach ($top as $r) {
                $lines[] = "• {$r['label']}: ".$this->money($r['minor']).' ('.$this->percent($r['minor'], $t['expense_minor']).')';
            }
        }
        if ($biggestText !== null) {
            $lines[] = '';
            $lines[] = "Biggest expense: {$biggestText}";
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param array{expense_minor: int, income_minor: int, expense_count: int, income_count: int} $t */
    public function total(ReportQuery $q, array $t): string
    {
        $income = $q->side === 'income';
        $minor = $income ? $t['income_minor'] : $t['expense_minor'];
        $n = $income ? $t['income_count'] : $t['expense_count'];
        $what = $income ? 'income' : 'spending';
        $filter = $q->filterLabel();

        if ($n === 0) {
            return $this->empty($q->period, $filter);
        }

        return $this->cap(($income ? '💵 ' : '💸 ')."*{$q->period->label}*".($filter !== '' ? " · {$filter}" : '')."\n"
            .ucfirst($what).': '.$this->money($minor)."\n{$n} ".($n === 1 ? 'transaction' : 'transactions'));
    }

    /** @param list<array{key: string, label: string, minor: int}> $rows */
    public function breakdown(ReportQuery $q, array $rows, int $total): string
    {
        if ($rows === []) {
            return $this->empty($q->period, $q->filterLabel());
        }
        $by = str_replace('_', ' ', (string) $q->groupBy);
        $time = in_array($q->groupBy, ['day', 'week', 'month'], true);
        $lines = [($q->side === 'income' ? '💵' : '📊')." *{$q->period->label}* by {$by}".($q->filterLabel() !== '' ? " · {$q->filterLabel()}" : ''), ''];

        $shown = $time ? $rows : array_slice($rows, 0, max($q->limit, 5));
        foreach ($shown as $r) {
            $label = $q->groupBy === 'week' ? 'Week of '.$r['label'] : $r['label'];
            $lines[] = "• {$label}: ".$this->money($r['minor']).($time || $total <= 0 ? '' : ' ('.$this->percent($r['minor'], $total).')');
        }
        if (count($rows) > count($shown)) {
            $rest = array_sum(array_column(array_slice($rows, count($shown)), 'minor'));
            $lines[] = '• Others: '.$this->money($rest).($total > 0 ? ' ('.$this->percent($rest, $total).')' : '');
        }
        $lines[] = '';
        $lines[] = '*Total:* '.$this->money($total);

        return $this->cap(implode("\n", $lines));
    }

    /**
     * @param  array{expense_minor: int, income_minor: int, expense_count: int, income_count: int}  $a
     * @param  array{expense_minor: int, income_minor: int, expense_count: int, income_count: int}  $b
     */
    public function compare(ReportQuery $q, array $a, array $b): string
    {
        $income = $q->side === 'income';
        $x = $income ? $a['income_minor'] : $a['expense_minor'];
        $y = $income ? $b['income_minor'] : $b['expense_minor'];
        $filter = $q->filterLabel();
        if ($x === 0 && $y === 0) {
            return $this->empty($q->period, $filter);
        }

        $lines = ['⚖️ *'.($income ? 'Income' : 'Spending').'*'.($filter !== '' ? " · {$filter}" : ''), '',
            "{$q->period->shortLabel()}: ".$this->money($x),
            "{$q->compare->shortLabel()}: ".$this->money($y),
            '',
        ];
        $diff = $x - $y;
        if ($diff === 0) {
            $lines[] = 'No change.';
        } else {
            $dir = $diff > 0 ? 'more' : 'less';
            $pct = $y !== 0 ? ' ('.intdiv(abs($diff) * 100, abs($y)).'%)' : '';
            $lines[] = $this->money(abs($diff))." {$dir} in {$q->period->shortLabel()}{$pct}.";
        }
        if ($q->period->partial && ! $q->compare->partial) {
            $lines[] = "_{$q->period->shortLabel()} isn't over yet, so this isn't a like-for-like comparison._";
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param Collection<int, array{date: string, text: string}> $rows */
    public function transactions(ReportQuery $q, Collection $rows, int $total, string $title): string
    {
        if ($rows->isEmpty()) {
            return $this->empty($q->period, $q->filterLabel());
        }
        $filter = $q->filterLabel();
        $lines = ["🧾 *{$title}* · {$q->period->label}".($filter !== '' ? " · {$filter}" : ''), ''];
        foreach ($rows as $i => $r) {
            $lines[] = ($i + 1).". {$r['text']}";
        }
        if ($total > $rows->count()) {
            $lines[] = '';
            $lines[] = "Showing {$rows->count()} of {$total}.";
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param list<array{counterparty_id: string, name: string, minor: int, due_on: ?string}> $rows */
    public function owed(bool $owedToMe, array $rows, ?string $person): string
    {
        if ($rows === []) {
            return $person !== null
                ? ($owedToMe ? "{$person} doesn't owe you anything." : "You don't owe {$person} anything.")
                : ($owedToMe ? 'Nobody owes you anything right now.' : "You don't owe anyone anything right now.");
        }

        $lines = [$owedToMe ? '🤝 *Owed to you*' : '🤝 *You owe*', ''];
        $total = 0;
        foreach ($rows as $r) {
            $total += $r['minor'];
            $due = $r['due_on'] ? ' · due '.CarbonImmutable::parse($r['due_on'])->format('j M') : '';
            $lines[] = "• {$r['name']}: ".$this->money($r['minor']).$due;
        }
        if (count($rows) > 1) {
            $lines[] = '';
            $lines[] = '*Total:* '.$this->money($total);
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param list<array{name: string, limit: int, spent: int, pct: int, left: int}> $rows */
    public function budgets(array $rows): string
    {
        if ($rows === []) {
            return 'You have no budgets yet. Try "set a budget of 5000 for food" or "monthly budget 40000".';
        }
        $lines = ['🎯 *Budgets this month*', ''];
        foreach ($rows as $r) {
            $icon = $r['pct'] >= 100 ? '🚨' : ($r['pct'] >= 80 ? '⚠️' : '✅');
            $lines[] = "{$icon} {$r['name']}: ".$this->money($r['spent']).' of '.$this->money($r['limit'])." ({$r['pct']}%)";
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param list<array{name: string, minor: int, frequency: string, next_due_on: string, monthly_minor: int}> $rows */
    public function subscriptions(array $rows): string
    {
        if ($rows === []) {
            return 'You have no recurring payments yet. Try "Netflix 649 every month" or "rent 15000 on the 1st of every month".';
        }
        $lines = ['🔁 *Recurring payments*', ''];
        $monthly = 0;
        foreach ($rows as $r) {
            $monthly += $r['monthly_minor'];
            $lines[] = "• {$r['name']}: ".$this->money($r['minor']).' '.$r['frequency'].' · next '.CarbonImmutable::parse($r['next_due_on'])->format('j M');
        }
        $lines[] = '';
        $lines[] = '*About '.$this->money($monthly).' a month*';

        return $this->cap(implode("\n", $lines));
    }

    /** @param list<array{name: string, minor: int, due_on: string, type: string}> $rows */
    public function upcoming(array $rows): string
    {
        if ($rows === []) {
            return 'Nothing is due in the next 30 days.';
        }
        $lines = ['🗓️ *Due in the next 30 days*', ''];
        foreach ($rows as $r) {
            $lines[] = '• '.CarbonImmutable::parse($r['due_on'])->format('j M').' · '.$r['name'].': '.$this->money($r['minor']).($r['type'] === 'income' ? ' (incoming)' : '');
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param list<array{name: string, target: int, saved: int, pct: int, date: ?string, monthly_needed: ?int, status: string}> $rows */
    public function goals(array $rows): string
    {
        if ($rows === []) {
            return 'You have no savings goals yet. Try "I want to save 100000 for a bike by June".';
        }
        $lines = ['🎯 *Savings goals*', ''];
        foreach ($rows as $r) {
            $line = ($r['status'] === 'achieved' ? '🎉 ' : '• ')."{$r['name']}: ".$this->money($r['saved']).' of '.$this->money($r['target'])." ({$r['pct']}%)";
            if ($r['date']) {
                $line .= ' · by '.CarbonImmutable::parse($r['date'])->format('j M Y');
                $line .= $r['monthly_needed'] !== null ? ', needs '.$this->money($r['monthly_needed']).'/month' : '';
            }
            $lines[] = $line;
        }

        return $this->cap(implode("\n", $lines));
    }

    /** @param list<array{name: string, outstanding: int, rate_bp: int, emi: int, months_left: ?int}> $rows */
    public function loans(array $rows): string
    {
        if ($rows === []) {
            return 'You are not tracking any loans. Try "bike loan 120000 outstanding at 10% for 24 months".';
        }
        $lines = ['🏦 *Loans*', ''];
        $total = 0;
        foreach ($rows as $r) {
            $total += $r['outstanding'];
            $rate = rtrim(rtrim(number_format($r['rate_bp'] / 100, 2, '.', ''), '0'), '.');
            $lines[] = "• {$r['name']}: ".$this->money($r['outstanding']).' left · EMI '.$this->money($r['emi'])." at {$rate}%".($r['months_left'] ? ", about {$r['months_left']} months to go" : '');
        }
        if (count($rows) > 1) {
            $lines[] = '';
            $lines[] = '*Total outstanding:* '.$this->money($total);
        }

        return $this->cap(implode("\n", $lines));
    }

    public function empty(Period $p, string $filter = ''): string
    {
        return "No transactions in {$p->label}".($filter !== '' ? " for {$filter}" : '').'.';
    }

    private function percent(int $part, int $whole): string
    {
        return $whole > 0 ? intdiv($part * 100, $whole).'%' : '0%';
    }

    private function cap(string $text): string
    {
        return mb_strlen($text, 'UTF-8') <= self::MAX_CHARS ? $text : rtrim(mb_substr($text, 0, self::MAX_CHARS, 'UTF-8')).'…';
    }
}

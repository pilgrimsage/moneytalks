<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

/**
 * Turns the model's PERIOD SPEC into real dates in the user's timezone (the model never does calendar maths).
 * Weeks run Monday-Sunday. A named month without a year means the most recent such month (this year's, or last
 * year's if it has not happened yet this year).
 */
class PeriodResolver
{
    /** @param array<string, mixed>|null $spec */
    public function resolve(?array $spec, string $timezone, ?CarbonImmutable $now = null, string $default = 'this_month'): ?Period
    {
        $today = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($timezone)->startOfDay();
        $kind = $spec['kind'] ?? $default;

        return match ($kind) {
            'today' => new Period($today, $today, 'Today ('.$today->format('j M').')'),
            'yesterday' => new Period($today->subDay(), $today->subDay(), 'Yesterday ('.$today->subDay()->format('j M').')'),
            'this_week' => $this->week($today->startOfWeek(), $today),
            'last_week' => $this->week($today->startOfWeek()->subWeek(), $today),
            'this_month' => $this->month($today->startOfMonth(), $today),
            'last_month' => $this->month($today->startOfMonth()->subMonthNoOverflow(), $today),
            'this_year' => $this->year($today->startOfYear(), $today),
            'last_year' => $this->year($today->startOfYear()->subYear(), $today),
            'month' => $this->namedMonth($spec, $today),
            'last_n_days' => $this->lastDays($spec, $today),
            'range' => $this->range($spec, $timezone, $today),
            'all' => new Period(CarbonImmutable::create(2000, 1, 1, 0, 0, 0, $timezone), $today, 'All time'),
            default => null,
        };
    }

    private function week(CarbonImmutable $monday, CarbonImmutable $today): Period
    {
        $sunday = $monday->addDays(6);
        $name = $monday->isSameDay($today->startOfWeek()) ? 'This week' : 'Last week';

        return new Period($monday, $sunday, "{$name} (".$monday->format('j M').' – '.$sunday->format('j M').')', $sunday->greaterThan($today));
    }

    private function month(CarbonImmutable $first, CarbonImmutable $today): Period
    {
        $last = $first->endOfMonth()->startOfDay();

        return new Period($first, $last, $first->format('F Y').($last->greaterThan($today) ? ' (so far)' : ''), $last->greaterThan($today));
    }

    private function year(CarbonImmutable $first, CarbonImmutable $today): Period
    {
        $last = $first->endOfYear()->startOfDay();

        return new Period($first, $last, $first->format('Y').($last->greaterThan($today) ? ' (so far)' : ''), $last->greaterThan($today));
    }

    private function namedMonth(?array $spec, CarbonImmutable $today): ?Period
    {
        $month = $spec['month'] ?? null;
        if (! is_int($month) || $month < 1 || $month > 12) {
            return null;
        }
        $year = $spec['year'] ?? null;
        if (! is_int($year)) {
            $year = $month <= $today->month ? $today->year : $today->year - 1;
        }
        if ($year < 2000 || $year > $today->year + 5) {
            return null;
        }

        return $this->month(CarbonImmutable::create($year, $month, 1, 0, 0, 0, $today->timezone), $today);
    }

    private function lastDays(?array $spec, CarbonImmutable $today): ?Period
    {
        $days = $spec['days'] ?? null;
        if (! is_int($days) || $days < 1 || $days > 3660) {
            return null;
        }

        return new Period($today->subDays($days - 1), $today, "Last {$days} day".($days === 1 ? '' : 's'));
    }

    private function range(?array $spec, string $timezone, CarbonImmutable $today): ?Period
    {
        $dates = [];
        foreach (['start', 'end'] as $k) {
            if (! is_string($spec[$k] ?? null) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $spec[$k], $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000) {
                return null;
            }
            $dates[$k] = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, $timezone);
        }
        if ($dates['start']->greaterThan($dates['end']) || $dates['start']->diffInDays($dates['end']) > 3660) {
            return null;
        }

        return new Period($dates['start'], $dates['end'], $dates['start']->format('j M Y').' – '.$dates['end']->format('j M Y'), $dates['end']->greaterThan($today));
    }
}

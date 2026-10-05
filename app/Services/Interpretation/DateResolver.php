<?php

namespace App\Services\Interpretation;

use Carbon\CarbonImmutable;

/**
 * Turns the model's DATE SPEC into a real date in the user's timezone. The model never does
 * calendar maths (docs/decisions.md D3); this class does, deterministically.
 *
 * Conventions: "last Friday" = the most recent Friday strictly before today; "next Friday" = the first
 * Friday strictly after today; "this Friday" = the Friday of the current Monday-Sunday week;
 * "on the 5th" = the most recent 5th on or before today.
 */
class DateResolver
{
    private const WEEKDAY = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /** @param array<string, mixed> $spec */
    public function resolve(array $spec, string $timezone, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $today = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($timezone)->startOfDay();

        return match ($spec['kind'] ?? 'none') {
            'none', 'today' => $today,
            'relative_days' => is_int($spec['offset_days'] ?? null) && abs($spec['offset_days']) <= 3660 ? $today->addDays($spec['offset_days']) : null,
            'weekday' => $this->weekday($spec, $today),
            'day_of_month' => $this->dayOfMonth($spec, $today),
            'iso' => $this->iso($spec['iso'] ?? null, $timezone),
            default => null,
        };
    }

    private function weekday(array $spec, CarbonImmutable $today): ?CarbonImmutable
    {
        $target = self::WEEKDAY[$spec['weekday'] ?? ''] ?? null;
        if ($target === null) {
            return null;
        }
        $iso = $today->isoWeekday();

        return match ($spec['which'] ?? 'last') {
            'last' => $today->subDays(($iso - $target + 7) % 7 ?: 7),
            'next' => $today->addDays(($target - $iso + 7) % 7 ?: 7),
            'this' => $today->startOfWeek()->addDays($target - 1),
            default => null,
        };
    }

    private function dayOfMonth(array $spec, CarbonImmutable $today): ?CarbonImmutable
    {
        $day = $spec['day'] ?? null;
        if (! is_int($day) || $day < 1 || $day > 31) {
            return null;
        }

        $month = $spec['month'] ?? null;
        $year = $spec['year'] ?? null;

        if (is_int($month)) {
            $year = is_int($year) ? $year : $today->year;

            return $this->validDate($year, $month, $day, $today->timezoneName);
        }

        // No month: the most recent date on or before today with that day-of-month.
        $cursor = $today->startOfMonth();
        for ($i = 0; $i < 13; $i++) {
            $candidate = $this->validDate($cursor->year, $cursor->month, $day, $today->timezoneName);
            if ($candidate && $candidate->lessThanOrEqualTo($today)) {
                return $candidate;
            }
            $cursor = $cursor->subMonthNoOverflow();
        }

        return null;
    }

    private function iso(mixed $iso, string $timezone): ?CarbonImmutable
    {
        if (! is_string($iso) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return null;
        }

        return $this->validDate((int) $m[1], (int) $m[2], (int) $m[3], $timezone);
    }

    private function validDate(int $year, int $month, int $day, string $timezone): ?CarbonImmutable
    {
        if ($year < 1970 || $year > 2200 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);
    }
}

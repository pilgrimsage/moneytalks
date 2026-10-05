<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

/** An inclusive range of local calendar dates, with a human label ("October 2026", "This week (28 Sep – 4 Oct)"). */
final class Period
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
        /** True when the period has not finished yet (so comparisons against a full period can mislead). */
        public readonly bool $partial = false,
    ) {}

    public function startDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->end->format('Y-m-d');
    }

    /** Short name for tables: "Oct 2026", "Sep 2026", or the label itself. */
    public function shortLabel(): string
    {
        $isMonth = $this->start->day === 1 && $this->end->isSameDay($this->start->endOfMonth());

        return $isMonth ? $this->start->format('M Y') : $this->label;
    }
}

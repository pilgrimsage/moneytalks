<?php

use App\Services\Reporting\PeriodResolver;
use Carbon\CarbonImmutable;

// Sunday 4 Oct 2026, 10:00 in Kolkata
beforeEach(function () {
    $this->r = new PeriodResolver;
    $this->now = CarbonImmutable::create(2026, 10, 4, 10, 0, 0, 'Asia/Kolkata')->utc();
    $this->at = fn (array $spec, string $tz = 'Asia/Kolkata', ?CarbonImmutable $now = null) => $this->r->resolve($spec + [
        'kind' => 'this_month', 'month' => null, 'year' => null, 'days' => null, 'start' => null, 'end' => null,
    ], $tz, $now ?? $this->now);
    $this->range = fn (array $spec) => ($p = ($this->at)($spec)) ? [$p->startDate(), $p->endDate(), $p->label, $p->partial] : null;
});

it('resolves the simple periods', function (array $spec, array $expected) {
    expect(($this->range)($spec))->toBe($expected);
})->with([
    'today' => [['kind' => 'today'], ['2026-10-04', '2026-10-04', 'Today (4 Oct)', false]],
    'yesterday' => [['kind' => 'yesterday'], ['2026-10-03', '2026-10-03', 'Yesterday (3 Oct)', false]],
    'this week (Mon-Sun)' => [['kind' => 'this_week'], ['2026-09-28', '2026-10-04', 'This week (28 Sep – 4 Oct)', false]],
    'last week' => [['kind' => 'last_week'], ['2026-09-21', '2026-09-27', 'Last week (21 Sep – 27 Sep)', false]],
    'this month' => [['kind' => 'this_month'], ['2026-10-01', '2026-10-31', 'October 2026 (so far)', true]],
    'last month' => [['kind' => 'last_month'], ['2026-09-01', '2026-09-30', 'September 2026', false]],
    'this year' => [['kind' => 'this_year'], ['2026-01-01', '2026-12-31', '2026 (so far)', true]],
    'last year' => [['kind' => 'last_year'], ['2025-01-01', '2025-12-31', '2025', false]],
    'last 30 days' => [['kind' => 'last_n_days', 'days' => 30], ['2026-09-05', '2026-10-04', 'Last 30 days', false]],
    'last 1 day' => [['kind' => 'last_n_days', 'days' => 1], ['2026-10-04', '2026-10-04', 'Last 1 day', false]],
]);

it('picks the most recent named month when no year is given', function (int $month, string $start) {
    expect(($this->at)(['kind' => 'month', 'month' => $month])->startDate())->toBe($start);
})->with([
    'september (past this year)' => [9, '2026-09-01'],
    'october (current)' => [10, '2026-10-01'],
    'december (not yet: last year)' => [12, '2025-12-01'],
    'january' => [1, '2026-01-01'],
]);

it('respects an explicit year, and handles leap February', function () {
    expect(($this->at)(['kind' => 'month', 'month' => 9, 'year' => 2025])->startDate())->toBe('2025-09-01')
        ->and(($this->at)(['kind' => 'month', 'month' => 2, 'year' => 2028])->endDate())->toBe('2028-02-29')
        ->and(($this->at)(['kind' => 'month', 'month' => 2, 'year' => 2027])->endDate())->toBe('2027-02-28');
});

it('resolves explicit ranges and "all time"', function () {
    expect(($this->range)(['kind' => 'range', 'start' => '2026-09-10', 'end' => '2026-09-20']))->toBe(['2026-09-10', '2026-09-20', '10 Sep 2026 – 20 Sep 2026', false])
        ->and(($this->at)(['kind' => 'all'])->label)->toBe('All time')->and(($this->at)(['kind' => 'all'])->endDate())->toBe('2026-10-04');
});

it('falls back to the default period only when none was given', function () {
    expect($this->r->resolve(null, 'Asia/Kolkata', $this->now)->label)->toBe('October 2026 (so far)')
        ->and($this->r->resolve(null, 'Asia/Kolkata', $this->now, 'today')->label)->toBe('Today (4 Oct)');
});

it('returns null instead of guessing for malformed periods', function (array $spec) {
    expect(($this->at)($spec))->toBeNull();
})->with([
    'unknown kind' => [['kind' => 'sometime']],
    'month 13' => [['kind' => 'month', 'month' => 13]],
    'month missing' => [['kind' => 'month']],
    'absurd year' => [['kind' => 'month', 'month' => 3, 'year' => 1850]],
    'zero days' => [['kind' => 'last_n_days', 'days' => 0]],
    'too many days' => [['kind' => 'last_n_days', 'days' => 99999]],
    'bad range date' => [['kind' => 'range', 'start' => '2026-02-30', 'end' => '2026-03-05']],
    'reversed range' => [['kind' => 'range', 'start' => '2026-10-10', 'end' => '2026-10-01']],
    'range missing end' => [['kind' => 'range', 'start' => '2026-10-01']],
]);

it('uses the user\'s local date near midnight', function () {
    // 20:30 UTC on 30 Sep is already 1 October in India, but still 30 September in New York.
    $now = CarbonImmutable::create(2026, 9, 30, 20, 30, 0, 'UTC');

    expect(($this->at)(['kind' => 'this_month'], 'Asia/Kolkata', $now)->startDate())->toBe('2026-10-01')
        ->and(($this->at)(['kind' => 'this_month'], 'America/New_York', $now)->startDate())->toBe('2026-09-01')
        ->and(($this->at)(['kind' => 'today'], 'Asia/Kolkata', $now)->startDate())->toBe('2026-10-01');
});

it('rolls over year boundaries', function () {
    $jan = CarbonImmutable::create(2027, 1, 3, 9, 0, 0, 'Asia/Kolkata');

    expect(($this->at)(['kind' => 'last_month'], 'Asia/Kolkata', $jan)->startDate())->toBe('2026-12-01')
        ->and(($this->at)(['kind' => 'last_week'], 'Asia/Kolkata', $jan)->startDate())->toBe('2026-12-21')   // 3 Jan 2027 is a Sunday
        ->and(($this->at)(['kind' => 'month', 'month' => 12], 'Asia/Kolkata', $jan)->startDate())->toBe('2026-12-01');
});

it('gives short labels for whole months', function () {
    expect(($this->at)(['kind' => 'last_month'])->shortLabel())->toBe('Sep 2026')->and(($this->at)(['kind' => 'today'])->shortLabel())->toBe('Today (4 Oct)');
});

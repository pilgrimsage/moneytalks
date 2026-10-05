<?php

use App\Services\Interpretation\DateResolver;
use Carbon\CarbonImmutable;

// Sunday 4 Oct 2026, 10:00 in Kolkata
beforeEach(function () {
    $this->r = new DateResolver;
    $this->now = CarbonImmutable::create(2026, 10, 4, 10, 0, 0, 'Asia/Kolkata')->utc();
    $this->at = fn (array $spec, string $tz = 'Asia/Kolkata') => $this->r->resolve($spec + [
        'kind' => 'none', 'offset_days' => null, 'weekday' => null, 'which' => null, 'day' => null, 'month' => null, 'year' => null, 'iso' => null,
    ], $tz, $this->now)?->format('Y-m-d');
});

it('resolves today and relative days', function () {
    expect(($this->at)(['kind' => 'none']))->toBe('2026-10-04')
        ->and(($this->at)(['kind' => 'today']))->toBe('2026-10-04')
        ->and(($this->at)(['kind' => 'relative_days', 'offset_days' => -1]))->toBe('2026-10-03')
        ->and(($this->at)(['kind' => 'relative_days', 'offset_days' => -2]))->toBe('2026-10-02')
        ->and(($this->at)(['kind' => 'relative_days', 'offset_days' => 1]))->toBe('2026-10-05')
        ->and(($this->at)(['kind' => 'relative_days', 'offset_days' => -30]))->toBe('2026-09-04');
});

it('resolves weekdays (today is Sunday)', function (string $weekday, string $which, string $expected) {
    expect(($this->at)(['kind' => 'weekday', 'weekday' => $weekday, 'which' => $which]))->toBe($expected);
})->with([
    'last friday' => ['fri', 'last', '2026-10-02'],
    'last sunday is a week ago, never today' => ['sun', 'last', '2026-09-27'],
    'last monday' => ['mon', 'last', '2026-09-28'],
    'next monday' => ['mon', 'next', '2026-10-05'],
    'next sunday is a week ahead, never today' => ['sun', 'next', '2026-10-11'],
    'this monday (Mon-Sun week)' => ['mon', 'this', '2026-09-28'],
    'this sunday' => ['sun', 'this', '2026-10-04'],
]);

it('resolves "on the 5th" to the most recent such day', function () {
    expect(($this->at)(['kind' => 'day_of_month', 'day' => 3]))->toBe('2026-10-03')
        ->and(($this->at)(['kind' => 'day_of_month', 'day' => 4]))->toBe('2026-10-04')
        ->and(($this->at)(['kind' => 'day_of_month', 'day' => 5]))->toBe('2026-09-05')   // not yet this month
        ->and(($this->at)(['kind' => 'day_of_month', 'day' => 31]))->toBe('2026-08-31')  // Sept has no 31st; Oct 31 is ahead
        ->and(($this->at)(['kind' => 'day_of_month', 'day' => 12, 'month' => 9]))->toBe('2026-09-12')
        ->and(($this->at)(['kind' => 'day_of_month', 'day' => 12, 'month' => 9, 'year' => 2025]))->toBe('2025-09-12');
});

it('resolves explicit ISO dates strictly', function () {
    expect(($this->at)(['kind' => 'iso', 'iso' => '2026-09-12']))->toBe('2026-09-12')
        ->and(($this->at)(['kind' => 'iso', 'iso' => '2026-02-30']))->toBeNull()
        ->and(($this->at)(['kind' => 'iso', 'iso' => '12/09/2026']))->toBeNull()
        ->and(($this->at)(['kind' => 'iso', 'iso' => null]))->toBeNull();
});

it('returns null instead of guessing for malformed specs', function (array $spec) {
    expect(($this->at)($spec))->toBeNull();
})->with([
    'unknown kind' => [['kind' => 'someday']],
    'weekday without name' => [['kind' => 'weekday']],
    'day out of range' => [['kind' => 'day_of_month', 'day' => 32]],
    'relative without offset' => [['kind' => 'relative_days']],
    'absurd offset' => [['kind' => 'relative_days', 'offset_days' => 99999]],
    'february 30th' => [['kind' => 'day_of_month', 'day' => 30, 'month' => 2]],
]);

describe('timezone boundaries', function () {
    it('uses the user\'s local date, not the server\'s', function () {
        // 20:00 UTC on 3 Oct is 01:30 on 4 Oct in Kolkata but still 3 Oct in New York.
        $now = CarbonImmutable::create(2026, 10, 3, 20, 0, 0, 'UTC');

        expect($this->r->resolve(['kind' => 'today'], 'Asia/Kolkata', $now)->format('Y-m-d'))->toBe('2026-10-04')
            ->and($this->r->resolve(['kind' => 'today'], 'America/New_York', $now)->format('Y-m-d'))->toBe('2026-10-03')
            ->and($this->r->resolve(['kind' => 'relative_days', 'offset_days' => -1], 'Asia/Kolkata', $now)->format('Y-m-d'))->toBe('2026-10-03');
    });

    it('handles the last minute and first minute of the day in IST', function () {
        $beforeMidnight = CarbonImmutable::create(2026, 10, 4, 23, 59, 0, 'Asia/Kolkata');
        $afterMidnight = CarbonImmutable::create(2026, 10, 5, 0, 1, 0, 'Asia/Kolkata');

        expect($this->r->resolve(['kind' => 'today'], 'Asia/Kolkata', $beforeMidnight)->format('Y-m-d'))->toBe('2026-10-04')
            ->and($this->r->resolve(['kind' => 'today'], 'Asia/Kolkata', $afterMidnight)->format('Y-m-d'))->toBe('2026-10-05')
            ->and($this->r->resolve(['kind' => 'relative_days', 'offset_days' => -1], 'Asia/Kolkata', $afterMidnight)->format('Y-m-d'))->toBe('2026-10-04');
    });

    it('crosses month, year and leap-day boundaries', function () {
        $newYear = CarbonImmutable::create(2027, 1, 1, 9, 0, 0, 'Asia/Kolkata');
        $leap = CarbonImmutable::create(2028, 3, 1, 9, 0, 0, 'Asia/Kolkata');

        expect($this->r->resolve(['kind' => 'relative_days', 'offset_days' => -1], 'Asia/Kolkata', $newYear)->format('Y-m-d'))->toBe('2026-12-31')
            ->and($this->r->resolve(['kind' => 'relative_days', 'offset_days' => -1], 'Asia/Kolkata', $leap)->format('Y-m-d'))->toBe('2028-02-29')
            ->and($this->r->resolve(['kind' => 'day_of_month', 'day' => 31], 'Asia/Kolkata', $newYear)->format('Y-m-d'))->toBe('2026-12-31');
    });
});

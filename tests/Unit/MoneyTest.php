<?php

use App\Support\Money;

it('parses decimal strings into minor units without floats', function (string $in, int $minor) {
    expect(Money::parse($in, 'INR')->minor)->toBe($minor);
})->with([
    ['250', 25000],
    ['250.5', 25050],
    ['250.50', 25050],
    ['0.05', 5],
    ['1,20,000', 12000000],
    ['1,20,000.75', 12000075],
    ['-12.34', -1234],
    ['250.000', 25000], // trailing zeros beyond precision are harmless
]);

it('rejects values that would need rounding or are malformed', function (string $in) {
    expect(fn () => Money::parse($in, 'INR'))->toThrow(InvalidArgumentException::class);
})->with(['250.555', 'abc', '', '12.3.4', '1e5', '99999999999999999999']);

it('respects currency exponents', function () {
    expect(Money::parse('500', 'JPY')->minor)->toBe(500)
        ->and(Money::parse('1.234', 'KWD')->minor)->toBe(1234)
        ->and(fn () => Money::parse('1.5', 'JPY'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('1', 'XXX'))->toThrow(InvalidArgumentException::class);
});

it('round-trips through toDecimal', function (string $in) {
    expect(Money::parse($in, 'INR')->toDecimal())->toBe($in);
})->with(['250.00', '0.05', '-12.34', '1200000.75']);

it('adds and subtracts exactly (0.10 + 0.20 == 0.30)', function () {
    $sum = Money::parse('0.10', 'INR')->add(Money::parse('0.20', 'INR'));
    expect($sum->equals(Money::parse('0.30', 'INR')))->toBeTrue();
});

it('refuses to mix currencies', function () {
    expect(fn () => Money::parse('1', 'INR')->add(Money::parse('1', 'USD')))
        ->toThrow(InvalidArgumentException::class);
});

it('formats INR with Indian grouping and hides zero decimals', function (string $in, string $out) {
    expect(Money::parse($in, 'INR')->format())->toBe($out);
})->with([
    ['250', '₹250'],
    ['1842', '₹1,842'],
    ['12000', '₹12,000'],
    ['120000', '₹1,20,000'],
    ['4500000', '₹45,00,000'],
    ['250.50', '₹250.50'],
    ['-500', '-₹500'],
]);

it('formats other currencies with western grouping', function () {
    expect(Money::parse('1234567.5', 'USD')->format())->toBe('$1,234,567.50');
});

it('splits equally with remainders handed out deterministically', function () {
    $parts = Money::parse('2400', 'INR')->splitEqually(3);
    expect(array_map(fn ($m) => $m->minor, $parts))->toBe([80000, 80000, 80000]);

    $parts = Money::parse('100', 'INR')->splitEqually(3); // 10000 / 3
    expect(array_map(fn ($m) => $m->minor, $parts))->toBe([3334, 3333, 3333]);
});

it('always allocates to exactly the total, for any amount and weights', function () {
    mt_srand(42);
    for ($i = 0; $i < 300; $i++) {
        $total = mt_rand(-5_000_000, 5_000_000);
        $weights = array_map(fn () => mt_rand(0, 9), range(1, mt_rand(1, 7)));
        if (array_sum($weights) === 0) {
            $weights[0] = 1;
        }
        $parts = Money::ofMinor($total, 'INR')->allocate($weights);
        expect(array_sum(array_map(fn ($m) => $m->minor, $parts)))->toBe($total);
    }
});

it('gives zero-weight parts nothing', function () {
    $parts = Money::ofMinor(100, 'INR')->allocate([1, 0, 1]);
    expect(array_map(fn ($m) => $m->minor, $parts))->toBe([50, 0, 50]);
});

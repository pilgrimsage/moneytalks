<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Immutable money amount in integer minor units (paise, cents, ...). Never uses floats.
 */
final class Money
{
    /** Minor-unit exponent per currency (decimal places). */
    private const EXPONENTS = [
        'INR' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'AED' => 2, 'SGD' => 2,
        'JPY' => 0, 'KWD' => 3,
    ];

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    /**
     * Parse a plain decimal string such as "250", "250.5", "1,20,000.00" or "-12.34".
     * Strict: more fractional digits than the currency allows is an error (no silent rounding).
     */
    public static function parse(string $amount, string $currency): self
    {
        $currency = self::normalizeCurrency($currency);
        $exp = self::exponent($currency);
        $clean = str_replace([',', ' ', '_'], '', trim($amount));

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Invalid money amount: '{$amount}'");
        }

        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $exp) {
            if (rtrim($fraction, '0') !== '' && strlen(rtrim($fraction, '0')) > $exp) {
                throw new InvalidArgumentException("Too many decimal places for {$currency}: '{$amount}'");
            }
            $fraction = substr($fraction, 0, $exp);
        }

        $digits = ltrim($m[2].str_pad($fraction, $exp, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) > 18 || (strlen($digits) === 19 && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException("Amount too large: '{$amount}'");
        }

        $minor = (int) $digits;

        return new self($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);

        return self::EXPONENTS[$currency]
            ?? throw new InvalidArgumentException("Unsupported currency: {$currency}");
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        $sum = $this->minor + $other->minor;
        if (is_float($sum)) { // PHP promotes int overflow to float
            throw new InvalidArgumentException('Money overflow');
        }

        return new self($sum, $this->currency);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    /**
     * Split into parts proportional to integer weights. The parts always sum to the total:
     * remainder minor units are handed out one at a time starting from the first part.
     *
     * @param  list<int>  $weights  non-negative integers, at least one > 0
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === [] || array_sum($weights) <= 0 || min($weights) < 0) {
            throw new InvalidArgumentException('Weights must be non-negative with a positive sum');
        }

        $total = array_sum($weights);
        $sign = $this->minor < 0 ? -1 : 1;
        $abs = abs($this->minor);

        $shares = [];
        $allocated = 0;
        foreach ($weights as $w) {
            $product = $abs * $w;
            if (is_float($product)) {
                throw new InvalidArgumentException('Money overflow');
            }
            $share = intdiv($product, $total);
            $shares[] = $share;
            $allocated += $share;
        }

        $remainder = $abs - $allocated;
        for ($i = 0; $remainder > 0; $i = ($i + 1) % count($shares)) {
            if ($weights[$i] > 0) {
                $shares[$i]++;
                $remainder--;
            }
        }

        return array_map(fn (int $s) => new self($sign * $s, $this->currency), $shares);
    }

    /** @return list<self> */
    public function splitEqually(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be at least 1');
        }

        return $this->allocate(array_fill(0, $parts, 1));
    }

    /** Plain decimal string in major units, e.g. "250.00". */
    public function toDecimal(): string
    {
        $exp = self::exponent($this->currency);
        $abs = (string) abs($this->minor);
        $sign = $this->minor < 0 ? '-' : '';

        if ($exp === 0) {
            return $sign.$abs;
        }

        $abs = str_pad($abs, $exp + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$exp).'.'.substr($abs, -$exp);
    }

    /** Human format for WhatsApp, e.g. "₹1,20,000" (Indian grouping for INR) or "$1,234.50". */
    public function format(bool $alwaysShowDecimals = false): string
    {
        $exp = self::exponent($this->currency);
        [$whole, $frac] = array_pad(explode('.', ltrim($this->toDecimal(), '-')), 2, '');

        $grouped = $this->currency === 'INR' ? self::groupIndian($whole) : self::groupWestern($whole);
        $showFrac = $exp > 0 && ($alwaysShowDecimals || (int) $frac !== 0);

        $symbol = match ($this->currency) {
            'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
            default => $this->currency.' ',
        };

        return ($this->minor < 0 ? '-' : '').$symbol.$grouped.($showFrac ? '.'.$frac : '');
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }

    private static function groupIndian(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3;
    }

    private static function groupWestern(string $whole): string
    {
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        self::exponent($currency);

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}

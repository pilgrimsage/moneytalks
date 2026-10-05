<?php

namespace App\Services\Interpretation;

/**
 * Finds the numbers a user actually typed ("2k", "1.5 lakh", "₹1,20,000", "४५००") so the model's
 * amount can be cross-checked against the raw text. All maths is on strings (no floats).
 */
class AmountNormalizer
{
    /** suffix => decimal places to shift (all multipliers are powers of ten) */
    private const SUFFIX = [
        'k' => 3, 'thousand' => 3, 'hazar' => 3, 'hazaar' => 3, 'hajar' => 3,
        'l' => 5, 'lac' => 5, 'lacs' => 5, 'lakh' => 5, 'lakhs' => 5,
        'cr' => 7, 'crore' => 7, 'crores' => 7,
    ];

    /** @return list<string> canonical decimal strings in major units, e.g. ["2000", "1200.5"] */
    public function extract(string $text): array
    {
        $text = $this->asciiDigits(mb_strtolower($text, 'UTF-8'));
        $out = [];

        $pattern = '/(?<![\w.,])(\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*(k|thousand|hazaa?r|hajar|lacs?|lakhs?|l|crores?|cr)?(?![\p{L}\d])/u';
        if (preg_match_all($pattern, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $number = str_replace(',', '', $match[1]);
                $shift = ! empty($match[2]) ? $this->shiftFor($match[2]) : 0;
                $out[] = $this->canonical($shift > 0 ? $this->shift($number, $shift) : $number);
            }
        }

        return array_values(array_unique($out));
    }

    /** True/false when the text contains numbers; null when it has none (spoken numbers, can't verify). */
    public function matches(string $amount, string $text): ?bool
    {
        $found = $this->extract($text);
        if ($found === []) {
            return null;
        }

        return in_array($this->canonical($amount), $found, true);
    }

    public function canonical(string $number): string
    {
        $number = trim($number);
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $number, $m)) {
            return $number;
        }
        $int = ltrim($m[1], '0');
        $int = $int === '' ? '0' : $int;
        $frac = rtrim($m[2] ?? '', '0');

        return $frac === '' ? $int : "{$int}.{$frac}";
    }

    private function shift(string $number, int $places): string
    {
        [$int, $frac] = array_pad(explode('.', $number, 2), 2, '');
        $frac = str_pad($frac, $places, '0');

        return $int.substr($frac, 0, $places).(strlen($frac) > $places ? '.'.substr($frac, $places) : '');
    }

    private function shiftFor(string $suffix): int
    {
        return self::SUFFIX[$suffix] ?? (str_starts_with($suffix, 'hazaa') ? 3 : 0);
    }

    private function asciiDigits(string $text): string
    {
        // Devanagari U+0966-096F and Arabic-Indic U+06F0-06F9 / U+0660-0669 digits.
        return strtr($text, [
            '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}

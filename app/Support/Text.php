<?php

namespace App\Support;

/**
 * Normalisation for alias / entity matching. Keeps Devanagari and other scripts intact;
 * only case, width/compatibility forms, punctuation and whitespace are folded.
 */
final class Text
{
    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        $text = mb_strtolower($text, 'UTF-8');
        // Keep letters, combining marks (matras), digits, and inner spaces; drop everything else.
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Multibyte Levenshtein distance (PHP's levenshtein() is byte-based). */
    public static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a, 1, 'UTF-8');
        $y = mb_str_split($b, 1, 'UTF-8');
        $m = count($x);
        $n = count($y);

        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }

        $prev = range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $n; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return $prev[$n];
    }

    /** 1.0 = identical, 0.0 = nothing in common. */
    public static function similarity(string $a, string $b): float
    {
        $len = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));

        return $len === 0 ? 1.0 : 1.0 - self::distance($a, $b) / $len;
    }
}

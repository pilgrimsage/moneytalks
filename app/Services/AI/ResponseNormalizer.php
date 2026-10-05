<?php

namespace App\Services\AI;

/**
 * The model's answer uses explicit empty values instead of nulls (Anthropic's structured outputs cap union types, see
 * TransactionParser::schema()). This turns them back into the nulls the rest of the application expects, BEFORE the
 * answer is validated. Idempotent: an answer that already uses nulls (replayed eval fixtures) passes through unchanged.
 */
final class ResponseNormalizer
{
    private const TEXT = ['amount', 'currency', 'category', 'merchant', 'account', 'to_account', 'counterparty', 'description', 'interest_rate',
        'emi_amount', 'target_amount', 'target_text', 'search_text', 'clarification_question'];

    private const CHOICES = ['event_type', 'action', 'recurrence', 'payment_method', 'target_kind', 'query_metric', 'group_by', 'export_format'];

    private const NUMBERS = ['limit', 'tenure_months'];

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalize(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_map(fn ($item) => is_array($item) ? self::item($item) : $item, $data['items']);
        }

        return $data;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function item(array $item): array
    {
        foreach (self::TEXT as $f) {
            if (array_key_exists($f, $item) && is_string($item[$f]) && trim($item[$f]) === '') {
                $item[$f] = null;
            }
        }
        foreach (self::CHOICES as $f) {
            if (($item[$f] ?? null) === 'none') {
                $item[$f] = null;
            }
        }
        foreach (self::NUMBERS as $f) {
            if (array_key_exists($f, $item) && $item[$f] === 0) {
                $item[$f] = null;
            }
        }

        if (isset($item['date']) && is_array($item['date'])) {
            $item['date'] = self::date($item['date']);
        }
        if (isset($item['due_date'])) {
            $due = is_array($item['due_date']) ? self::date($item['due_date']) : null;
            $item['due_date'] = ($due['kind'] ?? 'none') === 'none' ? null : $due;
        }
        foreach (['period', 'compare_period'] as $f) {
            if (array_key_exists($f, $item)) {
                $item[$f] = is_array($item[$f]) ? self::period($item[$f]) : null;
            }
        }

        if (array_key_exists('participants', $item)) {
            $p = is_array($item['participants']) ? $item['participants'] : [];
            $item['participants'] = $p === [] ? null : array_map(
                fn ($x) => is_array($x) ? ['amount' => (isset($x['amount']) && trim((string) $x['amount']) === '') ? null : ($x['amount'] ?? null)] + $x : $x,
                $p,
            );
        }

        return $item;
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private static function date(array $d): array
    {
        foreach (['weekday', 'which'] as $f) {
            if (($d[$f] ?? null) === 'none') {
                $d[$f] = null;
            }
        }
        foreach (['day', 'month', 'year'] as $f) {
            if (array_key_exists($f, $d) && $d[$f] === 0) {
                $d[$f] = null;
            }
        }
        if (array_key_exists('iso', $d) && is_string($d['iso']) && trim($d['iso']) === '') {
            $d['iso'] = null;
        }
        if (($d['kind'] ?? null) !== 'relative_days' && array_key_exists('offset_days', $d) && $d['offset_days'] === 0) {
            $d['offset_days'] = null; // only meaningful for relative_days
        }

        return $d;
    }

    /** @param array<string, mixed> $p @return array<string, mixed>|null */
    private static function period(array $p): ?array
    {
        if (($p['kind'] ?? null) === 'none') {
            return null;
        }
        foreach (['month', 'year', 'days'] as $f) {
            if (array_key_exists($f, $p) && $p[$f] === 0) {
                $p[$f] = null;
            }
        }
        foreach (['start', 'end'] as $f) {
            if (array_key_exists($f, $p) && is_string($p[$f]) && trim($p[$f]) === '') {
                $p[$f] = null;
            }
        }

        return $p;
    }
}

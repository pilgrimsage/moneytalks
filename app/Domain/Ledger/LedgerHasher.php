<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\DTO\EntryLine;
use App\Models\LedgerEntry;

/** Tamper-evidence: a hash over a transaction's entries, stored on the header and re-checked nightly. */
final class LedgerHasher
{
    /** @param iterable<EntryLine> $lines in position order */
    public static function forLines(string $userId, iterable $lines): string
    {
        $parts = [];
        $i = 0;
        foreach ($lines as $l) {
            $parts[] = self::row($i++, $l->accountId, $l->direction->value, $l->amount->minor, $l->amount->currency, $l->categoryId);
        }

        return self::finish($userId, $parts);
    }

    /** @param iterable<LedgerEntry> $entries in position order */
    public static function forEntries(string $userId, iterable $entries): string
    {
        $parts = [];
        $i = 0;
        foreach ($entries as $e) {
            $parts[] = self::row($i++, $e->account_id, $e->direction->value, (int) $e->amount_minor, $e->currency, $e->category_id);
        }

        return self::finish($userId, $parts);
    }

    private static function row(int $pos, string $account, string $dir, int $minor, string $currency, ?string $category): string
    {
        return implode('|', [$pos, $account, $dir, $minor, $currency, $category ?? '-']);
    }

    private static function finish(string $userId, array $parts): string
    {
        return hash('sha256', $userId."\n".implode("\n", $parts));
    }
}

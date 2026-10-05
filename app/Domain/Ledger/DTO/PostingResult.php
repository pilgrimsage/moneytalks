<?php

namespace App\Domain\Ledger\DTO;

use App\Models\LedgerTransaction;

final class PostingResult
{
    public function __construct(
        public readonly LedgerTransaction $transaction,
        /** True when the idempotency key had already been used and nothing new was written. */
        public readonly bool $replayed = false,
    ) {}
}

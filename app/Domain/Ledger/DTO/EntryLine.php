<?php

namespace App\Domain\Ledger\DTO;

use App\Enums\Direction;
use App\Support\Money;

/** One planned debit/credit line, before it is written. */
final class EntryLine
{
    public function __construct(
        public readonly string $accountId,
        public readonly Direction $direction,
        public readonly Money $amount,
        public readonly ?string $categoryId = null,
    ) {}
}

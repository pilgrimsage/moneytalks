<?php

namespace App\Services\Reporting;

/**
 * A validated, whitelisted description of what to compute. Built only by QueryPlanner from resolved names;
 * ReportService never accepts free-form SQL or unresolved text.
 */
final class ReportQuery
{
    public function __construct(
        public readonly string $metric,
        public readonly Period $period,
        public readonly ?Period $compare = null,
        /** @var list<string> category ids including descendants */
        public readonly array $categoryIds = [],
        public readonly ?string $categoryName = null,
        /** @var list<string> */
        public readonly array $merchantIds = [],
        public readonly ?string $merchantName = null,
        /** @var list<string> */
        public readonly array $accountIds = [],
        public readonly ?string $accountName = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $searchText = null,
        public readonly ?string $groupBy = null,
        public readonly int $limit = 5,
        /** Which side of the books: expense (default) or income. */
        public readonly string $side = 'expense',
        public readonly ?string $counterpartyId = null,
        public readonly ?string $counterpartyName = null,
    ) {}

    public function hasFilters(): bool
    {
        return $this->categoryIds !== [] || $this->merchantIds !== [] || $this->accountIds !== [] || $this->paymentMethod !== null || $this->searchText !== null;
    }

    /** A human description of the filters, e.g. "Food, using Cash". */
    public function filterLabel(): string
    {
        return implode(', ', array_filter([
            $this->categoryName, $this->merchantName ? "at {$this->merchantName}" : null,
            $this->accountName ? "using {$this->accountName}" : null,
            $this->paymentMethod ? 'by '.str_replace('_', ' ', $this->paymentMethod) : null,
            $this->searchText ? "matching \"{$this->searchText}\"" : null,
        ]));
    }
}

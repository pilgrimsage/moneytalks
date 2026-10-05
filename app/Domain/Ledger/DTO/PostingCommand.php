<?php

namespace App\Domain\Ledger\DTO;

use App\Enums\PaymentMethod;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * A validated request to record a financial event. This is what business logic hands the
 * ledger; the LLM never builds one directly. Account roles by type:
 *   Expense        accountId = paid from
 *   Income         accountId = received into
 *   Transfer       accountId = from, toAccountId = to
 *   OpeningBalance accountId = the account being opened
 *   CcPayment      accountId = paid from (asset), toAccountId = the credit card
 *   Lend/Repayment accountId = money account, counterpartyId = the person
 *   Borrow/RepayOut   (same)
 *   EmiPayment     accountId = paid from, toAccountId = the loan, interestMinor = the interest part, categoryId = the interest category
 *   SplitExpense   accountId = paid from, shares = what each OTHER person owes; the payer's own share is the rest
 */
final class PostingCommand
{
    public readonly string $occurredOn;

    public function __construct(
        public readonly string $userId,
        public readonly TransactionType $type,
        public readonly Money $money,
        CarbonInterface|string $occurredOn,
        public readonly string $accountId,
        public readonly string $idempotencyKey,
        public readonly ?string $toAccountId = null,
        public readonly ?string $categoryId = null,
        public readonly ?string $merchantId = null,
        public readonly ?string $counterpartyId = null,
        public readonly ?string $description = null,
        public readonly ?PaymentMethod $paymentMethod = null,
        public readonly TransactionSource $source = TransactionSource::Manual,
        public readonly ?string $waMessageId = null,
        public readonly ?float $confidence = null,
        public readonly ?string $correctsId = null,
        public readonly string $actorType = 'user',
        public readonly ?string $dueOn = null,
        /** @var list<array{counterpartyId: string, minor: int}> */
        public readonly array $shares = [],
        public readonly int $interestMinor = 0,
    ) {
        $this->occurredOn = $occurredOn instanceof CarbonInterface ? $occurredOn->format('Y-m-d') : $occurredOn;
    }

    public function withCorrects(string $originalId): self
    {
        return new self(
            $this->userId, $this->type, $this->money, $this->occurredOn, $this->accountId, $this->idempotencyKey,
            $this->toAccountId, $this->categoryId, $this->merchantId, $this->counterpartyId, $this->description,
            $this->paymentMethod, $this->source, $this->waMessageId, $this->confidence, $originalId, $this->actorType,
            $this->dueOn, $this->shares, $this->interestMinor,
        );
    }
}

<?php

namespace App\Services\Interpretation;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** A fully validated, resolved proposal, ready to become a PostingCommand. Holds ids resolved from the user's own data. */
final class ProposedPosting
{
    public function __construct(
        public readonly TransactionType $type,
        public readonly Money $money,
        public readonly string $occurredOn,
        public readonly string $accountId,
        public readonly string $accountName,
        public readonly ?string $toAccountId,
        public readonly ?string $toAccountName,
        public readonly ?string $categoryId,
        public readonly ?string $categoryName,
        public readonly ?string $merchantId,
        public readonly string $description,
        public readonly ?PaymentMethod $paymentMethod,
        public readonly float $confidence,
        public readonly bool $isToday,
        public readonly ?string $counterpartyId = null,
        public readonly ?string $counterpartyName = null,
        /** The person is not in the user's records yet; they are created when the posting is confirmed/recorded. */
        public readonly bool $newPerson = false,
        public readonly ?string $dueOn = null,
        /** @var list<array{id: ?string, name: string, minor: int}> what each OTHER person owes (id null = new person) */
        public readonly array $shares = [],
        public readonly int $interestMinor = 0,
        /** Where it came from: whatsapp_text | whatsapp_voice | whatsapp_image (voice and photos are always confirmed). */
        public readonly string $source = 'whatsapp_text',
    ) {}

    /** One-line human description used in confirmation prompts, e.g. "₹250 expense under Fuel, from Cash, today". */
    public function describe(): string
    {
        $when = $this->isToday ? 'today' : 'on '.CarbonImmutable::parse($this->occurredOn)->format('j M (D)');

        return match ($this->type) {
            TransactionType::Transfer => "a transfer of {$this->money->format()} from {$this->accountName} to {$this->toAccountName}, {$when}",
            TransactionType::Income => "{$this->money->format()} income under {$this->categoryName}, into {$this->accountName}, {$when}",
            TransactionType::CcPayment => "a card payment of {$this->money->format()} from {$this->accountName} to {$this->toAccountName}, {$when}",
            TransactionType::EmiPayment => "an EMI of {$this->money->format()} to {$this->toAccountName} (principal ".Money::ofMinor($this->money->minor - $this->interestMinor, $this->money->currency)->format().', interest '.Money::ofMinor($this->interestMinor, $this->money->currency)->format()."), {$when}",
            TransactionType::Lend => "{$this->money->format()} lent to {$this->counterpartyName}, {$when}".($this->dueOn ? ', to be returned by '.CarbonImmutable::parse($this->dueOn)->format('j M') : ''),
            TransactionType::Borrow => "{$this->money->format()} borrowed from {$this->counterpartyName}, {$when}".($this->dueOn ? ', to be returned by '.CarbonImmutable::parse($this->dueOn)->format('j M') : ''),
            TransactionType::RepaymentIn => "{$this->money->format()} received back from {$this->counterpartyName}, {$when}",
            TransactionType::RepaymentOut => "{$this->money->format()} paid back to {$this->counterpartyName}, {$when}",
            TransactionType::SplitExpense => "{$this->money->format()} {$this->categoryName} paid from {$this->accountName}, {$when}, split: ".$this->splitSummary(),
            default => "{$this->money->format()} expense under {$this->categoryName}, from {$this->accountName}, {$when}",
        };
    }

    public function withSource(string $source): self
    {
        return self::fromArray(['source' => $source] + $this->toArray());
    }

    /** "you ₹800, Rahul ₹800, Amit ₹800" */
    public function splitSummary(): string
    {
        $others = array_sum(array_column($this->shares, 'minor'));
        $parts = [];
        if ($this->money->minor - $others > 0) {
            $parts[] = 'you '.Money::ofMinor($this->money->minor - $others, $this->money->currency)->format();
        }
        foreach ($this->shares as $s) {
            $parts[] = $s['name'].' '.Money::ofMinor($s['minor'], $this->money->currency)->format();
        }

        return implode(', ', $parts);
    }

    /** Plain array for the encrypted conversation state. */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value, 'minor' => $this->money->minor, 'currency' => $this->money->currency,
            'occurred_on' => $this->occurredOn, 'account_id' => $this->accountId, 'account_name' => $this->accountName,
            'to_account_id' => $this->toAccountId, 'to_account_name' => $this->toAccountName,
            'category_id' => $this->categoryId, 'category_name' => $this->categoryName, 'merchant_id' => $this->merchantId,
            'description' => $this->description, 'payment_method' => $this->paymentMethod?->value,
            'confidence' => $this->confidence, 'is_today' => $this->isToday,
            'counterparty_id' => $this->counterpartyId, 'counterparty_name' => $this->counterpartyName, 'new_person' => $this->newPerson,
            'due_on' => $this->dueOn, 'shares' => $this->shares, 'interest_minor' => $this->interestMinor, 'source' => $this->source,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            TransactionType::from($a['type']), Money::ofMinor((int) $a['minor'], $a['currency']), $a['occurred_on'],
            $a['account_id'], $a['account_name'], $a['to_account_id'], $a['to_account_name'],
            $a['category_id'], $a['category_name'], $a['merchant_id'], $a['description'],
            $a['payment_method'] ? PaymentMethod::from($a['payment_method']) : null, (float) $a['confidence'], (bool) $a['is_today'],
            $a['counterparty_id'] ?? null, $a['counterparty_name'] ?? null, (bool) ($a['new_person'] ?? false), $a['due_on'] ?? null, $a['shares'] ?? [], (int) ($a['interest_minor'] ?? 0), (string) ($a['source'] ?? 'whatsapp_text'),
        );
    }
}

<?php

namespace App\Services\Interpretation;

use App\Domain\Ledger\CounterpartyService;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Enums\TransactionSource;
use App\Models\LedgerTransaction;
use App\Models\User;

/**
 * The one place a validated ProposedPosting becomes a PostingCommand. People the user has not mentioned before are
 * created here, at the moment of posting (after any confirmation), never earlier.
 */
class CommandBuilder
{
    public function __construct(private readonly CounterpartyService $people) {}

    public function build(User $user, ProposedPosting $p, string $key, ?string $waMessageId, ?LedgerTransaction $original = null): PostingCommand
    {
        $counterpartyId = $p->counterpartyId;
        if ($counterpartyId === null && $p->counterpartyName !== null) {
            $counterpartyId = $this->people->findOrCreate($user, $p->counterpartyName)->id;
        }

        $shares = array_map(fn (array $s) => [
            'counterpartyId' => $s['id'] ?? $this->people->findOrCreate($user, $s['name'])->id,
            'minor' => (int) $s['minor'],
        ], $p->shares);

        return new PostingCommand(
            userId: $user->id, type: $p->type, money: $p->money, occurredOn: $p->occurredOn, accountId: $p->accountId,
            idempotencyKey: $key, toAccountId: $p->toAccountId, categoryId: $p->categoryId,
            merchantId: $p->merchantId ?? $original?->merchant_id, counterpartyId: $counterpartyId ?? $original?->counterparty_id,
            description: $p->description !== '' ? $p->description : null, paymentMethod: $p->paymentMethod,
            source: TransactionSource::tryFrom($p->source) ?? TransactionSource::WhatsappText, waMessageId: $waMessageId, confidence: round($p->confidence, 3),
            dueOn: $p->dueOn, shares: $shares, interestMinor: $p->interestMinor,
        );
    }
}

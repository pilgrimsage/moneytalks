<?php

namespace App\Services\Interpretation;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;

/**
 * "paid 500 groceries" sent twice by accident (docs section 32). Same type, amount, date and category (or the
 * same accounts for a transfer) within a short window => ask before recording again. This is about *content*;
 * webhook redelivery is handled separately by idempotency keys and never reaches here.
 */
class DuplicateDetector
{
    public function find(User $user, ProposedPosting $p, string $idempotencyKey): ?LedgerTransaction
    {
        if (LedgerTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
            return null; // this exact message was already recorded: a retry, not a duplicate
        }

        $window = (int) ($user->settings?->duplicate_window_seconds ?: config('moneytalks.conversation.duplicate_window_seconds'));

        $candidates = LedgerTransaction::where('user_id', $user->id)
            ->where('status', TransactionStatus::Posted->value)
            ->where('type', $p->type->value)
            ->where('debit_total_minor', $p->money->minor)
            ->where('occurred_on', $p->occurredOn)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->orderByDesc('created_at')->limit(5)->get();

        foreach ($candidates as $tx) {
            $entries = LedgerEntry::where('transaction_id', $tx->id)->get();
            $same = match ($p->type) {
                TransactionType::Transfer, TransactionType::CcPayment => $entries->contains('account_id', $p->accountId) && $entries->contains('account_id', $p->toAccountId),
                TransactionType::Lend, TransactionType::Borrow, TransactionType::RepaymentIn, TransactionType::RepaymentOut => $p->counterpartyId !== null && $tx->counterparty_id === $p->counterpartyId && $entries->contains('account_id', $p->accountId),
                default => $entries->contains('category_id', $p->categoryId) && $entries->contains('account_id', $p->accountId),
            };
            if ($same) {
                return $tx;
            }
        }

        return null;
    }
}

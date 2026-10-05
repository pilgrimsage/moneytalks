<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\DTO\EntryLine;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\DTO\PostingResult;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Exceptions\LedgerImbalanceException;
use App\Enums\AccountSubtype;
use App\Enums\CategoryKind;
use App\Enums\Direction;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way financial data is written. Every write is ONE database transaction that:
 *   1. locks the user row (serialises that user's writes),
 *   2. replays safely on a repeated idempotency key,
 *   3. validates everything it was given,
 *   4. writes header + entries,
 *   5. re-reads what it wrote and rolls back unless debits == credits (verify-before-commit),
 *   6. writes an audit record.
 * Nothing is ever updated or deleted afterwards: undo = reversal, correction = reversal + repost.
 */
class LedgerService
{
    public function __construct(
        private readonly PostingRules $rules,
        private readonly AccountService $accounts,
        private readonly AuditLogger $audit,
        private readonly DebtService $debts,
    ) {}

    public function post(PostingCommand $c): PostingResult
    {
        try {
            return DB::transaction(fn () => $this->doPost($c));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same idempotency key won the race: return its result.
            $existing = LedgerTransaction::where('idempotency_key', $c->idempotencyKey)->where('user_id', $c->userId)->first();
            if ($existing) {
                return new PostingResult($existing, replayed: true);
            }
            throw $e;
        }
    }

    /**
     * Undo: post an opposite transaction and mark the original reversed. At most once per original.
     */
    public function reverse(string $userId, string $transactionId, string $reason, TransactionSource $source = TransactionSource::Manual, ?string $waMessageId = null): PostingResult
    {
        $key = 'reverse:'.$transactionId;

        try {
            return DB::transaction(fn () => $this->doReverse($userId, $transactionId, $reason, $source, $waMessageId, $key));
        } catch (UniqueConstraintViolationException $e) {
            $existing = LedgerTransaction::where('idempotency_key', $key)->where('user_id', $userId)->first();
            if ($existing) {
                return new PostingResult($existing, replayed: true);
            }
            throw $e;
        }
    }

    /**
     * Correction: reverse the original and post a replacement linked to it, atomically.
     * A retry of the same correction (same replacement idempotency key) is a harmless replay;
     * a *new* correction of something already reversed or corrected is refused.
     */
    public function correct(string $transactionId, PostingCommand $replacement, string $reason): PostingResult
    {
        return DB::transaction(function () use ($transactionId, $replacement, $reason) {
            $done = LedgerTransaction::where('user_id', $replacement->userId)
                ->where('idempotency_key', $replacement->idempotencyKey)->first();
            if ($done) {
                return new PostingResult($done, replayed: true);
            }

            $reversal = $this->reverse($replacement->userId, $transactionId, $reason, $replacement->source, $replacement->waMessageId);
            if ($reversal->replayed) {
                throw new LedgerException('That transaction was already reversed or corrected.');
            }

            return $this->post($replacement->withCorrects($transactionId));
        });
    }

    private function doPost(PostingCommand $c): PostingResult
    {
        $user = User::whereKey($c->userId)->lockForUpdate()->firstOrFail();

        if ($c->idempotencyKey === '' || strlen($c->idempotencyKey) > 191) {
            throw new LedgerException('A valid idempotency key (1-191 chars) is required.');
        }

        $existing = LedgerTransaction::where('idempotency_key', $c->idempotencyKey)->first();
        if ($existing) {
            if ($existing->user_id !== $user->id) {
                throw new LedgerException('Idempotency key belongs to another user.');
            }

            return new PostingResult($existing, replayed: true);
        }

        $this->validate($c, $user);

        $account = $this->ownedAccount($user, $c->accountId);
        $to = $c->toAccountId ? $this->ownedAccount($user, $c->toAccountId) : null;
        $system = $this->accounts->ensureSystemAccounts($user);
        $people = $this->peopleAccounts($user, $c);
        $this->debts->assertCanSettle($c);

        $lines = $this->rules->lines($c, $account, $to, $system, $people);

        $known = [];
        foreach ([$account, $to, ...array_values($system), ...array_values($people)] as $candidate) {
            if ($candidate !== null) {
                $known[$candidate->id] = $candidate;
            }
        }
        foreach ($lines as $line) {
            $acct = $known[$line->accountId];
            if ($acct->currency !== $c->money->currency) {
                throw new LedgerException("Account '{$acct->name}' is in {$acct->currency}, not {$c->money->currency}.");
            }
        }

        $tx = $this->write($user, $lines, [
            'type' => $c->type->value,
            'occurred_on' => $c->occurredOn,
            'description' => $c->description,
            'payment_method' => $c->paymentMethod?->value,
            'source' => $c->source->value,
            'merchant_id' => $c->merchantId,
            'counterparty_id' => $c->counterpartyId,
            'wa_message_id' => $c->waMessageId,
            'idempotency_key' => $c->idempotencyKey,
            'confidence' => $c->confidence,
            'corrects_id' => $c->correctsId,
            'currency' => $c->money->currency,
        ]);

        $this->debts->afterPosted($tx, $c);

        $this->audit->record('transaction.posted', $user->id, 'ledger_transaction', $tx->id, null, [
            'type' => $c->type->value, 'amount_minor' => $c->money->minor, 'currency' => $c->money->currency,
        ], null, $c->actorType);

        return new PostingResult($tx->load('entries'));
    }

    private function doReverse(string $userId, string $transactionId, string $reason, TransactionSource $source, ?string $waMessageId, string $key): PostingResult
    {
        $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();

        $existing = LedgerTransaction::where('idempotency_key', $key)->where('user_id', $user->id)->first();
        if ($existing) {
            return new PostingResult($existing, replayed: true);
        }

        $original = LedgerTransaction::where('user_id', $user->id)->whereKey($transactionId)->lockForUpdate()->first()
            ?? throw new LedgerException('Transaction not found.');

        if ($original->type === TransactionType::Reversal) {
            throw new LedgerException('A reversal cannot itself be reversed; post a new transaction instead.');
        }
        if ($original->status !== TransactionStatus::Posted) {
            throw new LedgerException("Transaction is already {$original->status->value}.");
        }

        $this->debts->beforeReversed($original);

        $lines = $original->entries->map(fn (LedgerEntry $e) => new EntryLine(
            $e->account_id,
            $e->direction->opposite(),
            Money::ofMinor((int) $e->amount_minor, $e->currency),
            $e->category_id,
        ))->all();

        $reversal = $this->write($user, $lines, [
            'type' => TransactionType::Reversal->value,
            // Dated like the original so the mistaken entry disappears from the period it polluted.
            'occurred_on' => $original->occurred_on->format('Y-m-d'),
            'description' => 'Reversal: '.($original->description ?? $original->type->value),
            'source' => $source->value,
            'merchant_id' => $original->merchant_id,
            'counterparty_id' => $original->counterparty_id,
            'wa_message_id' => $waMessageId,
            'idempotency_key' => $key,
            'reversal_of_id' => $original->id,
            'currency' => $original->currency,
        ]);

        $original->status = TransactionStatus::Reversed;
        $original->reversed_by_id = $reversal->id;
        $original->save();

        $this->audit->record('transaction.reversed', $user->id, 'ledger_transaction', $original->id,
            ['status' => 'posted'], ['status' => 'reversed', 'reversed_by_id' => $reversal->id], $reason);

        return new PostingResult($reversal->load('entries'));
    }

    /**
     * Insert header + entries, then re-read and verify before the surrounding transaction commits.
     *
     * @param  list<EntryLine>  $lines
     * @param  array<string, mixed>  $attributes
     */
    private function write(User $user, array $lines, array $attributes): LedgerTransaction
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            if (! $l->amount->isPositive()) {
                throw new LedgerException('Every ledger line must be a positive amount.');
            }
            if ($l->direction === Direction::Debit) {
                $debit += $l->amount->minor;
            } else {
                $credit += $l->amount->minor;
            }
            if (is_float($debit) || is_float($credit)) { // PHP promotes int overflow to float
                throw new LedgerException('Amount too large.');
            }
        }
        if (count($lines) < 2 || $debit !== $credit) {
            throw new LedgerImbalanceException("Refusing unbalanced transaction (debit {$debit}, credit {$credit}).");
        }

        $tx = LedgerTransaction::create($attributes + [
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'base_currency' => $user->base_currency,
            // M3 posts in the user's base currency only, so base amount == amount and the rate is 1.
            'base_amount_minor' => $debit,
            'exchange_rate' => 1,
            'debit_total_minor' => $debit,
            'credit_total_minor' => $credit,
            'entries_hash' => LedgerHasher::forLines($user->id, $lines),
        ]);

        foreach ($lines as $position => $l) {
            LedgerEntry::create([
                'transaction_id' => $tx->id,
                'user_id' => $user->id,
                'account_id' => $l->accountId,
                'direction' => $l->direction->value,
                'amount_minor' => $l->amount->minor,
                'currency' => $l->amount->currency,
                'category_id' => $l->categoryId,
                'position' => $position,
            ]);
        }

        $this->verifyWritten($tx, count($lines));

        return $tx;
    }

    /** Verify-before-commit: what is now in the database must balance and match the header. */
    private function verifyWritten(LedgerTransaction $tx, int $expectedLines): void
    {
        $rows = DB::table('ledger_entries')->where('transaction_id', $tx->id)->orderBy('position')->get();

        $debit = (int) $rows->where('direction', 'D')->sum('amount_minor');
        $credit = (int) $rows->where('direction', 'C')->sum('amount_minor');

        if ($rows->count() !== $expectedLines || $debit !== $credit || $debit !== (int) $tx->debit_total_minor) {
            throw new LedgerImbalanceException("Ledger verification failed for {$tx->id}: rolled back.");
        }

        $entries = LedgerEntry::where('transaction_id', $tx->id)->orderBy('position')->get();
        if (LedgerHasher::forEntries($tx->user_id, $entries) !== $tx->entries_hash) {
            throw new LedgerImbalanceException("Ledger hash mismatch for {$tx->id}: rolled back.");
        }
    }

    private function validate(PostingCommand $c, User $user): void
    {
        if (! $c->money->isPositive()) {
            throw new LedgerException('Amount must be greater than zero.');
        }
        if ($c->money->currency !== $user->base_currency) {
            throw new LedgerException('Multi-currency posting is not enabled yet.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $c->occurredOn) || ! checkdate((int) substr($c->occurredOn, 5, 2), (int) substr($c->occurredOn, 8, 2), (int) substr($c->occurredOn, 0, 4))) {
            throw new LedgerException("Invalid date '{$c->occurredOn}'.");
        }

        if ($c->categoryId !== null) {
            $category = Category::where('user_id', $user->id)->whereKey($c->categoryId)->first()
                ?? throw new LedgerException('Unknown category.');
            $expected = match ($c->type) {
                TransactionType::Income => CategoryKind::Income,
                TransactionType::Expense, TransactionType::SplitExpense, TransactionType::EmiPayment => CategoryKind::Expense,
                default => null,
            };
            if ($expected === null) {
                throw new LedgerException("A {$c->type->value} cannot carry a category.");
            }
            if ($category->kind !== $expected) {
                throw new LedgerException("Category '{$category->name}' is not an {$expected->value} category.");
            }
        }
        if ($c->merchantId !== null && ! Merchant::where('user_id', $user->id)->whereKey($c->merchantId)->exists()) {
            throw new LedgerException('Unknown merchant.');
        }
        if ($c->counterpartyId !== null && ! Counterparty::where('user_id', $user->id)->whereKey($c->counterpartyId)->exists()) {
            throw new LedgerException('Unknown counterparty.');
        }
        if ($c->toAccountId !== null && ! in_array($c->type, [TransactionType::Transfer, TransactionType::CcPayment, TransactionType::EmiPayment], true)) {
            throw new LedgerException('Only transfers, card payments and EMI payments have a destination account.');
        }
        $needsPerson = in_array($c->type, [TransactionType::Lend, TransactionType::Borrow, TransactionType::RepaymentIn, TransactionType::RepaymentOut], true);
        if ($needsPerson && $c->counterpartyId === null) {
            throw new LedgerException("A {$c->type->value} needs a person.");
        }
        if ($c->interestMinor !== 0 && $c->type !== TransactionType::EmiPayment) {
            throw new LedgerException('Only an EMI payment has an interest part.');
        }
        if ($c->shares !== [] && $c->type !== TransactionType::SplitExpense) {
            throw new LedgerException('Only a split expense has shares.');
        }
        foreach ($c->shares as $share) {
            if (! is_int($share['minor']) || ! Counterparty::where('user_id', $user->id)->whereKey($share['counterpartyId'])->exists()) {
                throw new LedgerException('A split person is unknown.');
            }
        }
        if ($c->dueOn !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $c->dueOn) || ! checkdate((int) substr($c->dueOn, 5, 2), (int) substr($c->dueOn, 8, 2), (int) substr($c->dueOn, 0, 4)))) {
            throw new LedgerException("Invalid due date '{$c->dueOn}'.");
        }
    }

    /**
     * The receivable/payable account of every person a command involves (created on first use).
     *
     * @return array<string, LedgerAccount> counterparty id => account
     */
    private function peopleAccounts(User $user, PostingCommand $c): array
    {
        $subtype = match ($c->type) {
            TransactionType::Lend, TransactionType::RepaymentIn, TransactionType::SplitExpense => AccountSubtype::Receivable,
            TransactionType::Borrow, TransactionType::RepaymentOut => AccountSubtype::Payable,
            default => null,
        };
        if ($subtype === null) {
            return [];
        }
        $ids = $c->type === TransactionType::SplitExpense ? array_column($c->shares, 'counterpartyId') : array_filter([$c->counterpartyId]);

        $out = [];
        foreach ($ids as $id) {
            $person = Counterparty::where('user_id', $user->id)->whereKey($id)->first() ?? throw new LedgerException('Unknown person.');
            $out[$id] = $this->accounts->personAccount($user, $person, $subtype);
        }

        return $out;
    }

    private function ownedAccount(User $user, string $accountId): LedgerAccount
    {
        $account = LedgerAccount::where('user_id', $user->id)->whereKey($accountId)->first()
            ?? throw new LedgerException('Unknown account.');

        if ($account->status !== 'active') {
            throw new LedgerException("Account '{$account->name}' is closed.");
        }

        return $account;
    }
}

<?php

namespace App\Services\Interpretation;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Finds the existing transaction a user means ("that", "the 500 grocery one") and describes it.
 * Only the user's own, still-posted, non-reversal transactions are ever candidates.
 */
class TransactionLocator
{
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly DateResolver $dates,
    ) {}

    /** The most recent transaction that can still be undone or corrected. */
    public function last(User $user): ?LedgerTransaction
    {
        return $this->base($user)->first();
    }

    /**
     * @param  array<string, mixed>  $item  the model's undo/correct item (target_* fields)
     * @return Collection<int, LedgerTransaction> newest first, at most 5
     */
    public function find(User $user, array $item, CarbonImmutable $now): Collection
    {
        $kind = $item['target_kind'] ?? 'last';
        if ($kind === 'last') {
            return collect(array_filter([$this->last($user)]));
        }

        $q = $this->base($user);
        $text = trim((string) ($item['target_text'] ?? ''));

        if ($kind === 'by_amount') {
            try {
                $money = Money::parse((string) ($item['target_amount'] ?? ''), $user->base_currency);
            } catch (InvalidArgumentException) {
                return collect();
            }
            $q->where('debit_total_minor', $money->minor);
        } elseif ($kind === 'by_date') {
            $date = $this->dates->resolve($item['date'] ?? ['kind' => 'none'], $user->timezone, $now);
            if (! $date) {
                return collect();
            }
            $q->where('occurred_on', $date->format('Y-m-d'));
        } elseif ($text === '') {
            return collect(); // by_text without any text
        }

        if ($text !== '') {
            $categoryIds = $this->categoryIds($user, $text);
            $like = '%'.addcslashes($text, '%_\\').'%';
            $q->where(function ($w) use ($categoryIds, $like) {
                $w->where('description', 'like', $like);
                if ($categoryIds !== []) {
                    $w->orWhereIn('id', LedgerEntry::whereIn('category_id', $categoryIds)->select('transaction_id'));
                }
            });
        }

        return $q->limit(5)->get();
    }

    /**
     * What the transaction was, reconstructed from its entries.
     *
     * @return array{type: TransactionType, minor: int, currency: string, occurred_on: string, category_id: ?string, category_name: ?string,
     *               account_id: ?string, account_name: ?string, to_account_id: ?string, to_account_name: ?string, description: ?string,
     *               merchant_id: ?string, payment_method: ?string}
     */
    public function facts(LedgerTransaction $tx): array
    {
        $entries = $tx->entries()->with('account')->get();
        $debit = $entries->firstWhere('direction.value', 'D');
        $credit = $entries->firstWhere('direction.value', 'C');
        $categoryEntry = $entries->first(fn ($e) => $e->category_id !== null);
        $category = $categoryEntry ? Category::find($categoryEntry->category_id) : null;

        [$from, $to] = match ($tx->type) {
            TransactionType::Expense => [$credit?->account, null],
            TransactionType::Income => [$debit?->account, null],
            default => [$credit?->account, $debit?->account], // transfer: money leaves the credited account
        };

        return [
            'type' => $tx->type, 'minor' => (int) $tx->debit_total_minor, 'currency' => $tx->currency,
            'occurred_on' => $tx->occurred_on->format('Y-m-d'),
            'category_id' => $category?->id, 'category_name' => $category?->name,
            'account_id' => $from?->id, 'account_name' => $from?->name,
            'to_account_id' => $to?->id, 'to_account_name' => $to?->name,
            'description' => $tx->description, 'merchant_id' => $tx->merchant_id,
            'payment_method' => $tx->payment_method,
        ];
    }

    public function describe(LedgerTransaction $tx): string
    {
        $f = $this->facts($tx);
        $amount = Money::ofMinor($f['minor'], $f['currency'])->format();
        $when = CarbonImmutable::parse($f['occurred_on'])->format('j M (D)');

        return match ($f['type']) {
            TransactionType::Transfer => "a transfer of {$amount} from {$f['account_name']} to {$f['to_account_name']} on {$when}",
            TransactionType::Income => "{$amount} income under {$f['category_name']}, into {$f['account_name']}, on {$when}",
            TransactionType::OpeningBalance => "the opening balance of {$amount} on {$when}",
            TransactionType::CcPayment => "a card payment of {$amount} from {$f['account_name']} to {$f['to_account_name']} on {$when}",
            TransactionType::EmiPayment => "an EMI of {$amount} to {$f['to_account_name']} on {$when}",
            TransactionType::Lend => "{$amount} lent to {$this->person($tx)} on {$when}",
            TransactionType::Borrow => "{$amount} borrowed from {$this->person($tx)} on {$when}",
            TransactionType::RepaymentIn => "{$amount} received back from {$this->person($tx)} on {$when}",
            TransactionType::RepaymentOut => "{$amount} paid back to {$this->person($tx)} on {$when}",
            TransactionType::SplitExpense => "a split expense of {$amount}".($f['description'] ? " ({$f['description']})" : '')." on {$when}",
            default => "{$amount} expense under ".($f['category_name'] ?? 'no category').", from {$f['account_name']}, on {$when}",
        };
    }

    private function person(LedgerTransaction $tx): string
    {
        return (string) (Counterparty::where('user_id', $tx->user_id)->whereKey($tx->counterparty_id)->value('name') ?? 'someone');
    }

    /** @return list<string> */
    private function categoryIds(User $user, string $text): array
    {
        $ids = [];
        foreach (['expense', 'income'] as $kind) {
            $r = $this->resolver->resolve($user->id, EntityType::Category, $text, CategoryKind::from($kind));
            if ($r->isResolved()) {
                $ids[] = $r->entityId;
            }
        }

        return $ids;
    }

    private function base(User $user)
    {
        return LedgerTransaction::where('user_id', $user->id)
            ->where('status', TransactionStatus::Posted->value)
            ->where('type', '!=', TransactionType::Reversal->value)
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}

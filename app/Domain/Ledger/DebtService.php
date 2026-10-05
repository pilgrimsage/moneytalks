<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Enums\TransactionType;
use App\Models\DebtRecord;
use App\Models\DebtSettlement;
use App\Models\LedgerTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Who owes whom, and which loan a repayment settles (docs/ledger.md 3.6-3.10). Called by LedgerService INSIDE its
 * database transaction, so debt bookkeeping and the ledger entries commit or roll back together.
 * Repayments settle the oldest open debt first (FIFO). Money you are owed is never guessed: a repayment larger than
 * what is open, or with nothing open, is refused so the caller can ask.
 */
class DebtService
{
    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    /** Direction of the debt a transaction type creates (lend/split -> receivable, borrow -> payable). */
    private const OPENS = [
        'lend' => self::RECEIVABLE, 'split_expense' => self::RECEIVABLE, 'borrow' => self::PAYABLE,
    ];

    private const SETTLES = ['repayment_in' => self::RECEIVABLE, 'repayment_out' => self::PAYABLE];

    /** Refuse a repayment that does not match what is actually open. Call BEFORE writing the ledger. */
    public function assertCanSettle(PostingCommand $c): void
    {
        $direction = self::SETTLES[$c->type->value] ?? null;
        if ($direction === null) {
            return;
        }
        $open = $this->openMinor($c->userId, (string) $c->counterpartyId, $direction);
        if ($open === 0) {
            throw new LedgerException($direction === self::RECEIVABLE ? 'Nothing is owed to you by that person.' : 'You do not owe that person anything.');
        }
        if ($c->money->minor > $open) {
            throw new LedgerException(($direction === self::RECEIVABLE ? 'They only owe you ' : 'You only owe them ').Money::ofMinor($open, $c->money->currency)->format().'.');
        }
    }

    public function afterPosted(LedgerTransaction $tx, PostingCommand $c): void
    {
        $type = $c->type->value;

        if (isset(self::OPENS[$type])) {
            $shares = $c->type === TransactionType::SplitExpense
                ? $c->shares
                : [['counterpartyId' => (string) $c->counterpartyId, 'minor' => $c->money->minor]];
            foreach ($shares as $s) {
                DebtRecord::create([
                    'user_id' => $c->userId, 'counterparty_id' => $s['counterpartyId'], 'direction' => self::OPENS[$type],
                    'opened_transaction_id' => $tx->id, 'original_minor' => $s['minor'],
                    'due_on' => $c->type === TransactionType::SplitExpense ? null : $c->dueOn, 'status' => 'open',
                ]);
            }

            return;
        }

        if (isset(self::SETTLES[$type])) {
            $this->settle($c, $tx, self::SETTLES[$type]);
        }
    }

    /**
     * Called when a transaction is about to be reversed. Undoing a repayment re-opens the debt; undoing the
     * lending/borrowing itself is refused while repayments are recorded against it (undo those first).
     */
    public function beforeReversed(LedgerTransaction $original): void
    {
        $opened = DebtRecord::where('user_id', $original->user_id)->where('opened_transaction_id', $original->id)->get();
        foreach ($opened as $record) {
            if ($this->settledMinor($record->id) > 0) {
                throw new LedgerException('Repayments are recorded against that. Undo those first.');
            }
            $record->update(['status' => 'voided']);
        }

        $settlements = DebtSettlement::where('user_id', $original->user_id)->where('transaction_id', $original->id)->whereNull('voided_at')->get();
        foreach ($settlements as $s) {
            $s->update(['voided_at' => now()]);
            $this->refreshStatus(DebtRecord::findOrFail($s->debt_record_id));
        }
    }

    /** Open (unsettled) amount owed between the user and one person, in one direction. */
    public function openMinor(string $userId, string $counterpartyId, string $direction): int
    {
        return (int) DebtRecord::where('user_id', $userId)->where('counterparty_id', $counterpartyId)
            ->where('direction', $direction)->whereIn('status', ['open', 'partial'])
            ->get()->sum(fn (DebtRecord $r) => $r->original_minor - $this->settledMinor($r->id));
    }

    /**
     * Who owes what right now, largest first.
     *
     * @return list<array{counterparty_id: string, name: string, minor: int, due_on: ?string}> due_on = the earliest due date among open debts
     */
    public function openByPerson(string $userId, string $direction, ?string $counterpartyId = null): array
    {
        $records = DebtRecord::where('user_id', $userId)->where('direction', $direction)->whereIn('status', ['open', 'partial'])
            ->when($counterpartyId, fn ($q) => $q->where('counterparty_id', $counterpartyId))->get();
        $names = DB::table('counterparties')->where('user_id', $userId)->pluck('name', 'id');

        $out = [];
        foreach ($records as $r) {
            $left = $r->original_minor - $this->settledMinor($r->id);
            if ($left <= 0) {
                continue;
            }
            $o = $out[$r->counterparty_id] ?? ['counterparty_id' => $r->counterparty_id, 'name' => (string) ($names[$r->counterparty_id] ?? 'Someone'), 'minor' => 0, 'due_on' => null];
            $o['minor'] += $left;
            $due = $r->due_on?->format('Y-m-d');
            if ($due !== null && ($o['due_on'] === null || $due < $o['due_on'])) {
                $o['due_on'] = $due;
            }
            $out[$r->counterparty_id] = $o;
        }
        usort($out, fn ($a, $b) => [$b['minor'], $a['name']] <=> [$a['minor'], $b['name']]);

        return $out;
    }

    private function settle(PostingCommand $c, LedgerTransaction $tx, string $direction): void
    {
        $left = $c->money->minor;
        $records = DebtRecord::where('user_id', $c->userId)->where('counterparty_id', $c->counterpartyId)
            ->where('direction', $direction)->whereIn('status', ['open', 'partial'])->orderBy('id')->lockForUpdate()->get();

        foreach ($records as $r) {
            if ($left === 0) {
                break;
            }
            $take = min($left, $r->original_minor - $this->settledMinor($r->id));
            if ($take <= 0) {
                continue;
            }
            DebtSettlement::create(['user_id' => $c->userId, 'debt_record_id' => $r->id, 'transaction_id' => $tx->id, 'amount_minor' => $take]);
            $left -= $take;
            $this->refreshStatus($r);
        }

        if ($left !== 0) { // assertCanSettle() should have refused this; never write a half-allocated repayment
            throw new LedgerException('The repayment could not be matched to what is owed.');
        }
    }

    private function refreshStatus(DebtRecord $r): void
    {
        $settled = $this->settledMinor($r->id);
        $r->update(['status' => $settled >= $r->original_minor ? 'settled' : ($settled > 0 ? 'partial' : 'open')]);
    }

    private function settledMinor(string $recordId): int
    {
        return (int) DB::table('debt_settlements')->where('debt_record_id', $recordId)->whereNull('voided_at')->sum('amount_minor');
    }
}

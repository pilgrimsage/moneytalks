<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\DTO\EntryLine;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Enums\AccountKind;
use App\Enums\AccountSubtype;
use App\Enums\Direction;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Support\Money;

/**
 * Deterministic mapping from a financial event to debit/credit lines (docs/ledger.md section 3).
 * Pure: it never touches the database. The accounting semantics live here and nowhere else.
 */
final class PostingRules
{
    /**
     * @param  array<string, LedgerAccount>  $system  keys: expenses, income, opening
     * @param  array<string, LedgerAccount>  $people  counterparty id => that person's receivable/payable account
     * @return list<EntryLine>
     */
    public function lines(PostingCommand $c, LedgerAccount $account, ?LedgerAccount $toAccount, array $system, array $people = []): array
    {
        return match ($c->type) {
            // Expense: Dr Expenses [category] / Cr paid-from account
            TransactionType::Expense => $this->expense($c, $account, $system),
            // Income: Dr received-into account / Cr Income [category]
            TransactionType::Income => $this->income($c, $account, $system),
            // Transfer between own accounts: Dr to / Cr from. Never income or expense.
            TransactionType::Transfer => $this->transfer($c, $account, $toAccount),
            TransactionType::OpeningBalance => $this->opening($c, $account, $system),
            // Card bill: Dr card (liability) / Cr bank. The spending was recorded when the card was used.
            TransactionType::CcPayment => $this->cardPayment($c, $account, $toAccount),
            // Lending: Dr Receivable: person / Cr money account. Not an expense.
            TransactionType::Lend => $this->person($c, $account, $people, 'lend from', fn ($p) => [$p, Direction::Debit, $account, Direction::Credit]),
            // Borrowing: Dr money account / Cr Payable: person. Not income.
            TransactionType::Borrow => $this->person($c, $account, $people, 'receive into', fn ($p) => [$account, Direction::Debit, $p, Direction::Credit]),
            TransactionType::RepaymentIn => $this->person($c, $account, $people, 'receive into', fn ($p) => [$account, Direction::Debit, $p, Direction::Credit]),
            TransactionType::RepaymentOut => $this->person($c, $account, $people, 'pay from', fn ($p) => [$p, Direction::Debit, $account, Direction::Credit]),
            TransactionType::SplitExpense => $this->split($c, $account, $people, $system),
            // Loan instalment: Dr Loan (principal) + Dr Expenses [interest] / Cr paid-from account.
            TransactionType::EmiPayment => $this->emi($c, $account, $toAccount, $system),
            TransactionType::Reversal => throw new LedgerException('Reversals are created with LedgerService::reverse().'),
        };
    }

    private function expense(PostingCommand $c, LedgerAccount $from, array $system): array
    {
        $this->requireSpendable($from, 'pay from');

        return [
            new EntryLine($system['expenses']->id, Direction::Debit, $c->money, $c->categoryId),
            new EntryLine($from->id, Direction::Credit, $c->money),
        ];
    }

    private function income(PostingCommand $c, LedgerAccount $into, array $system): array
    {
        if (! $into->subtype->isOwnedAsset()) {
            throw new LedgerException("Income cannot be received into '{$into->name}' ({$into->subtype->value}).");
        }

        return [
            new EntryLine($into->id, Direction::Debit, $c->money),
            new EntryLine($system['income']->id, Direction::Credit, $c->money, $c->categoryId),
        ];
    }

    private function transfer(PostingCommand $c, LedgerAccount $from, ?LedgerAccount $to): array
    {
        if (! $to) {
            throw new LedgerException('A transfer needs a destination account.');
        }
        if ($from->id === $to->id) {
            throw new LedgerException('Cannot transfer an account to itself.');
        }
        foreach ([[$from, 'from'], [$to, 'to']] as [$acct, $role]) {
            if (! $acct->subtype->isOwnedAsset()) {
                throw new LedgerException("'{$acct->name}' ({$acct->subtype->value}) cannot be a transfer {$role} account yet.");
            }
        }

        return [
            new EntryLine($to->id, Direction::Debit, $c->money),
            new EntryLine($from->id, Direction::Credit, $c->money),
        ];
    }

    private function emi(PostingCommand $c, LedgerAccount $from, ?LedgerAccount $loan, array $system): array
    {
        if (! $loan || $loan->subtype !== AccountSubtype::Loan) {
            throw new LedgerException('An EMI payment needs a loan to pay.');
        }
        if (! $from->subtype->isOwnedAsset()) {
            throw new LedgerException("Cannot pay an EMI from '{$from->name}' ({$from->subtype->value}).");
        }
        $interest = $c->interestMinor;
        $principal = $c->money->minor - $interest;
        if ($interest < 0 || $principal < 0) {
            throw new LedgerException('The interest cannot be more than the payment.');
        }

        $lines = [];
        if ($principal > 0) {
            $lines[] = new EntryLine($loan->id, Direction::Debit, Money::ofMinor($principal, $c->money->currency));
        }
        if ($interest > 0) {
            $lines[] = new EntryLine($system['expenses']->id, Direction::Debit, Money::ofMinor($interest, $c->money->currency), $c->categoryId);
        }
        $lines[] = new EntryLine($from->id, Direction::Credit, $c->money);

        return $lines;
    }

    private function cardPayment(PostingCommand $c, LedgerAccount $from, ?LedgerAccount $card): array
    {
        if (! $card || $card->subtype !== AccountSubtype::CreditCard) {
            throw new LedgerException('A card payment needs a credit card to pay.');
        }
        if (! $from->subtype->isOwnedAsset()) {
            throw new LedgerException("Cannot pay a card from '{$from->name}' ({$from->subtype->value}).");
        }

        return [
            new EntryLine($card->id, Direction::Debit, $c->money),
            new EntryLine($from->id, Direction::Credit, $c->money),
        ];
    }

    /**
     * One person, one money account. $sides returns [debit account, Debit, credit account, Credit] given the person's account.
     *
     * @param  \Closure(LedgerAccount): array{0: LedgerAccount, 1: Direction, 2: LedgerAccount, 3: Direction}  $sides
     */
    private function person(PostingCommand $c, LedgerAccount $money, array $people, string $role, \Closure $sides): array
    {
        if (! $money->subtype->isOwnedAsset()) {
            throw new LedgerException("Cannot {$role} '{$money->name}' ({$money->subtype->value}).");
        }
        $person = $people[$c->counterpartyId] ?? throw new LedgerException('This needs a person.');
        [$debit, , $credit] = $sides($person);

        return [
            new EntryLine($debit->id, Direction::Debit, $c->money),
            new EntryLine($credit->id, Direction::Credit, $c->money),
        ];
    }

    /** I paid $c->money; each person in shares owes me their part; whatever is left is my own expense. */
    private function split(PostingCommand $c, LedgerAccount $from, array $people, array $system): array
    {
        $this->requireSpendable($from, 'pay from');
        if ($c->shares === []) {
            throw new LedgerException('A split needs at least one other person.');
        }

        $lines = [];
        $others = 0;
        $seen = [];
        foreach ($c->shares as $share) {
            if (isset($seen[$share['counterpartyId']])) {
                throw new LedgerException('A person appears twice in the split.');
            }
            $seen[$share['counterpartyId']] = true;
            if ($share['minor'] <= 0) {
                throw new LedgerException('Every share must be more than zero.');
            }
            $others += $share['minor'];
            $acct = $people[$share['counterpartyId']] ?? throw new LedgerException('A split person is unknown.');
            $lines[] = new EntryLine($acct->id, Direction::Debit, Money::ofMinor($share['minor'], $c->money->currency));
        }
        $mine = $c->money->minor - $others;
        if ($mine < 0) {
            throw new LedgerException('The shares add up to more than the total.');
        }
        if ($mine > 0) {
            array_unshift($lines, new EntryLine($system['expenses']->id, Direction::Debit, Money::ofMinor($mine, $c->money->currency), $c->categoryId));
        }
        $lines[] = new EntryLine($from->id, Direction::Credit, $c->money);

        return $lines;
    }

    private function opening(PostingCommand $c, LedgerAccount $account, array $system): array
    {
        // Assets open with Dr account / Cr Opening Balances; liabilities the other way round.
        return match ($account->kind) {
            AccountKind::Asset => [
                new EntryLine($account->id, Direction::Debit, $c->money),
                new EntryLine($system['opening']->id, Direction::Credit, $c->money),
            ],
            AccountKind::Liability => [
                new EntryLine($system['opening']->id, Direction::Debit, $c->money),
                new EntryLine($account->id, Direction::Credit, $c->money),
            ],
            default => throw new LedgerException('Only asset or liability accounts have an opening balance.'),
        };
    }

    private function requireSpendable(LedgerAccount $account, string $role): void
    {
        if (! $account->subtype->isSpendable()) {
            throw new LedgerException("Cannot {$role} '{$account->name}' ({$account->subtype->value}).");
        }
    }
}

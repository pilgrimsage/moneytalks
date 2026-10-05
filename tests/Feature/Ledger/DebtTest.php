<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\CounterpartyService;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerVerifier;
use App\Enums\AccountSubtype;
use App\Enums\TransactionType;
use App\Models\DebtRecord;
use App\Models\LedgerAccount;

beforeEach(function () {
    $this->user = ledgerUser();
    $this->rahul = app(CounterpartyService::class)->findOrCreate($this->user, 'Rahul');
    $this->amit = app(CounterpartyService::class)->findOrCreate($this->user, 'Amit');
    $this->person = fn ($p, AccountSubtype $s = AccountSubtype::Receivable) => app(AccountService::class)->personAccount($this->user, $p, $s);
    $this->debt = fn (TransactionType $t, string $amount, $p, array $o = []) => ledger()->post(command($this->user, $t, $amount, $o + ['counterpartyId' => $p->id]));
    $this->owed = fn ($p, AccountSubtype $s = AccountSubtype::Receivable) => balanceOf(($this->person)($p, $s));
});

describe('lending and repayment', function () {
    it('records lending as a receivable, never an expense', function () {
        ($this->debt)(TransactionType::Lend, '2000', $this->rahul, ['dueOn' => '2026-10-11']);

        expect(balanceOf(account($this->user, 'Cash')))->toBe(-200000)
            ->and(($this->owed)($this->rahul))->toBe(200000)
            ->and(balanceOf(account($this->user, 'Expenses')))->toBe(0)
            ->and(DebtRecord::firstOrFail()->due_on->format('Y-m-d'))->toBe('2026-10-11')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('settles repayments oldest first and tracks partial status', function () {
        ($this->debt)(TransactionType::Lend, '1000', $this->rahul);
        ($this->debt)(TransactionType::Lend, '500', $this->rahul);

        ($this->debt)(TransactionType::RepaymentIn, '1200', $this->rahul);

        $records = DebtRecord::orderBy('id')->get();
        expect($records[0]->status)->toBe('settled')->and($records[1]->status)->toBe('partial')
            ->and(($this->owed)($this->rahul))->toBe(30000)
            ->and(balanceOf(account($this->user, 'Cash')))->toBe(-30000)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses a repayment larger than what is owed, or from someone who owes nothing', function () {
        ($this->debt)(TransactionType::Lend, '500', $this->rahul);

        expect(fn () => ($this->debt)(TransactionType::RepaymentIn, '800', $this->rahul))->toThrow(LedgerException::class, 'only owe you ₹500')
            ->and(fn () => ($this->debt)(TransactionType::RepaymentIn, '10', $this->amit))->toThrow(LedgerException::class, 'Nothing is owed')
            ->and(($this->owed)($this->rahul))->toBe(50000);
    });

    it('re-opens the debt when a repayment is undone, and refuses to undo a loan that has repayments', function () {
        $lend = ($this->debt)(TransactionType::Lend, '1000', $this->rahul);
        $back = ($this->debt)(TransactionType::RepaymentIn, '400', $this->rahul);

        expect(fn () => ledger()->reverse($this->user->id, $lend->transaction->id, 'x'))->toThrow(LedgerException::class, 'Undo those first');

        ledger()->reverse($this->user->id, $back->transaction->id, 'x');

        expect(DebtRecord::firstOrFail()->status)->toBe('open')->and(($this->owed)($this->rahul))->toBe(100000);
        ledger()->reverse($this->user->id, $lend->transaction->id, 'x');
        expect(DebtRecord::firstOrFail()->status)->toBe('voided')->and(($this->owed)($this->rahul))->toBe(0)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('borrowing', function () {
    it('records borrowing as a payable, not income, and repaying reduces it', function () {
        ($this->debt)(TransactionType::Borrow, '5000', $this->amit);
        expect(balanceOf(account($this->user, 'Cash')))->toBe(500000)->and(($this->owed)($this->amit, AccountSubtype::Payable))->toBe(500000)
            ->and(balanceOf(account($this->user, 'Income')))->toBe(0);

        ($this->debt)(TransactionType::RepaymentOut, '2000', $this->amit);

        expect(($this->owed)($this->amit, AccountSubtype::Payable))->toBe(300000)
            ->and(fn () => ($this->debt)(TransactionType::RepaymentOut, '9000', $this->amit))->toThrow(LedgerException::class, 'only owe them')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('split expenses', function () {
    it('splits dinner three ways: my share is the expense, the others become receivables', function () {
        ledger()->post(command($this->user, TransactionType::SplitExpense, '2400', [
            'categoryId' => category($this->user, 'Food')->id,
            'shares' => [['counterpartyId' => $this->rahul->id, 'minor' => 80000], ['counterpartyId' => $this->amit->id, 'minor' => 80000]],
        ]));

        expect(balanceOf(account($this->user, 'Expenses')))->toBe(80000)
            ->and(balanceOf(account($this->user, 'Cash')))->toBe(-240000)
            ->and(($this->owed)($this->rahul))->toBe(80000)->and(($this->owed)($this->amit))->toBe(80000)
            ->and(DebtRecord::count())->toBe(2)->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('rejects shares above the total, duplicates and empty splits', function () {
        $try = fn (array $shares) => fn () => ledger()->post(command($this->user, TransactionType::SplitExpense, '100', ['shares' => $shares]));

        expect($try([['counterpartyId' => $this->rahul->id, 'minor' => 10001]]))->toThrow(LedgerException::class, 'more than the total')
            ->and($try([['counterpartyId' => $this->rahul->id, 'minor' => 100], ['counterpartyId' => $this->rahul->id, 'minor' => 100]]))->toThrow(LedgerException::class, 'twice')
            ->and($try([]))->toThrow(LedgerException::class, 'at least one other person');
    });

    it('lets me pay the whole thing for others (no own share)', function () {
        ledger()->post(command($this->user, TransactionType::SplitExpense, '100', ['shares' => [['counterpartyId' => $this->rahul->id, 'minor' => 10000]]]));

        expect(balanceOf(account($this->user, 'Expenses')))->toBe(0)->and(($this->owed)($this->rahul))->toBe(10000);
    });

    it('is idempotent: the same key never creates debts twice', function () {
        $cmd = command($this->user, TransactionType::SplitExpense, '300', ['idempotencyKey' => 'split-1', 'shares' => [['counterpartyId' => $this->rahul->id, 'minor' => 10000]]]);
        ledger()->post($cmd);
        $again = ledger()->post($cmd);

        expect($again->replayed)->toBeTrue()->and(DebtRecord::count())->toBe(1)->and(($this->owed)($this->rahul))->toBe(10000);
    });
});

describe('credit card bill payment', function () {
    it('reduces what is owed on the card and is not an expense', function () {
        $card = app(AccountService::class)->create($this->user, 'HDFC Card', AccountSubtype::CreditCard);
        $bank = app(AccountService::class)->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
        ledger()->post(command($this->user, TransactionType::Expense, '3000', ['accountId' => $card->id, 'categoryId' => category($this->user, 'Shopping')->id]));
        ledger()->post(command($this->user, TransactionType::Income, '20000', ['accountId' => $bank->id, 'categoryId' => category($this->user, 'Salary')->id]));

        ledger()->post(command($this->user, TransactionType::CcPayment, '3000', ['accountId' => $bank->id, 'toAccountId' => $card->id]));

        expect(balanceOf($card))->toBe(0)->and(balanceOf($bank))->toBe(1_700_000)->and(balanceOf(account($this->user, 'Expenses')))->toBe(300000)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses to pay something that is not a card', function () {
        $bank = app(AccountService::class)->create($this->user, 'HDFC Bank', AccountSubtype::Bank);

        expect(fn () => ledger()->post(command($this->user, TransactionType::CcPayment, '10', ['accountId' => $bank->id, 'toAccountId' => account($this->user, 'Cash')->id])))
            ->toThrow(LedgerException::class, 'credit card');
    });
});

describe('isolation', function () {
    it('never lets one user use another user\'s person or accounts', function () {
        $other = ledgerUser('919000000009');
        $theirs = app(CounterpartyService::class)->findOrCreate($other, 'Neha');

        expect(fn () => ledger()->post(command($this->user, TransactionType::Lend, '10', ['counterpartyId' => $theirs->id])))->toThrow(LedgerException::class)
            ->and(LedgerAccount::where('user_id', $this->user->id)->where('counterparty_id', $theirs->id)->exists())->toBeFalse();
    });

    it('is caught by the verifier if debt records drift from the ledger', function () {
        ($this->debt)(TransactionType::Lend, '1000', $this->rahul);
        DebtRecord::query()->update(['status' => 'voided']);

        expect(app(LedgerVerifier::class)->verify())->not->toBe([]);
    });
});

<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\LedgerVerifier;
use App\Enums\AccountSubtype;
use App\Enums\TransactionType;
use App\Models\Counterparty;
use App\Models\DebtRecord;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

function dItem(array $o = []): array
{
    return aiItem($o + ['event_type' => 'lend', 'amount' => '2000', 'category' => null, 'counterparty' => 'Rahul']);
}

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->say = function (string $text, array $item) {
        FakeAIProvider::respond(aiEnvelope($item));
        waText($this, $text);
    };
    $this->lend = function (string $name = 'Rahul', string $amount = '2000') {
        ($this->say)("gave {$name} {$amount}", dItem(['counterparty' => $name, 'amount' => $amount]));
        waTap($this, lastButtons()['confirm']);
    };
    $this->owed = fn (string $name) => balanceOf(LedgerAccount::where('user_id', $this->user->id)->where('name', 'Receivable: '.$name)->firstOrFail());
});

describe('lending', function () {
    it('asks first, creates the person only on Confirm, and records it as a receivable (not an expense)', function () {
        ($this->say)('gave Rahul 2000', dItem());

        expect(sentTexts()[0])->toContain('Record ₹2,000 lent to Rahul')->and(sentTexts()[0])->toContain("I'll add them")
            ->and(Counterparty::count())->toBe(0)->and(LedgerTransaction::count())->toBe(0)
            ->and(lastButtonTitles())->toBe(['Confirm', 'Cancel']);

        waTap($this, lastButtons()['confirm']);

        expect(sentTexts()[1])->toContain('₹2,000 lent to *Rahul*')->and(sentTexts()[1])->toContain('not counted as an expense')
            ->and(Counterparty::count())->toBe(1)->and(($this->owed)('Rahul'))->toBe(200000)
            ->and(balanceOf(account($this->user, 'Expenses')))->toBe(0)->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('does not create the person or record anything when Cancel is tapped', function () {
        ($this->say)('gave Rahul 2000', dItem());
        waTap($this, lastButtons()['cancel'], 'Cancel');

        expect(Counterparty::count())->toBe(0)->and(LedgerTransaction::count())->toBe(0);
    });

    it('asks who when the person is missing and accepts the name as the answer', function () {
        ($this->say)('lent 500', dItem(['amount' => '500', 'counterparty' => null, 'missing_fields' => ['counterparty']]));
        expect(sentTexts()[0])->toBe('Who did you lend it to?');

        waText($this, 'Amit');

        expect(sentTexts()[1])->toContain('Record ₹500 lent to Amit');
    });

    it('records the due date', function () {
        ($this->say)('gave Rahul 2000, back on 11 Oct', dItem(['due_date' => aiDate('iso', ['iso' => '2026-10-11'])]));

        expect(sentTexts()[0])->toContain('to be returned by 11 Oct');
        waTap($this, lastButtons()['confirm']);
        expect(DebtRecord::firstOrFail()->due_on->format('Y-m-d'))->toBe('2026-10-11')->and(sentTexts()[1])->toContain('Due back: 11 Oct');
    });
});

describe('repayments', function () {
    beforeEach(fn () => ($this->lend)());

    it('records a repayment straight away (it is checked against what is owed) and reduces the debt', function () {
        ($this->say)('Rahul returned 500', dItem(['event_type' => 'repayment_received', 'amount' => '500']));

        expect(sentTexts()[2])->toContain('₹500 received back from *Rahul*')->and(($this->owed)('Rahul'))->toBe(150000)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses more than is owed and explains, recording nothing', function () {
        ($this->say)('Rahul returned 9000', dItem(['event_type' => 'repayment_received', 'amount' => '9000']));

        expect(sentTexts()[2])->toContain('Rahul owes you ₹2,000')->and(sentTexts()[2])->toContain('Nothing was recorded')->and(LedgerTransaction::count())->toBe(1);
    });

    it('does not guess when nothing is owed', function () {
        ($this->say)('Amit returned 100', dItem(['event_type' => 'repayment_received', 'amount' => '100', 'counterparty' => 'Amit']));

        expect(sentTexts()[2])->toContain('no record')->and(LedgerTransaction::count())->toBe(1);
    });

    it('can be undone, which re-opens the debt; the loan itself cannot be undone while repaid', function () {
        ($this->say)('Rahul returned 500', dItem(['event_type' => 'repayment_received', 'amount' => '500']));
        $loan = LedgerTransaction::where('type', 'lend')->firstOrFail();

        $loanUndo = app(LedgerService::class);
        expect(fn () => $loanUndo->reverse($this->user->id, $loan->id, 'x'))->toThrow(LedgerException::class);

        waText($this, 'undo');

        expect(sentTexts()[3])->toContain('Undid')->and(($this->owed)('Rahul'))->toBe(200000)->and(DebtRecord::firstOrFail()->status)->toBe('open');
    });
});

describe('borrowing', function () {
    it('confirms, records a payable (not income) and repaying reduces it', function () {
        ($this->say)('rahul se 5000 liya', dItem(['event_type' => 'borrow', 'amount' => '5000']));
        waTap($this, lastButtons()['confirm']);
        ($this->say)('paid back Rahul 2000', dItem(['event_type' => 'repayment_made', 'amount' => '2000']));

        expect(sentTexts()[1])->toContain('not counted as income')->and(sentTexts()[2])->toContain('₹2,000 paid back to *Rahul*')
            ->and(balanceOf(account($this->user, 'Income')))->toBe(0)->and(balanceOf(account($this->user, 'Cash')))->toBe(300000);
    });
});

describe('split expenses', function () {
    it('shows each share, records on Confirm and puts the others in "who owes me"', function () {
        $people = fn (...$names) => array_map(fn ($n) => ['name' => $n, 'amount' => null], $names);
        ($this->say)('dinner 2400 split between me, Rahul and Amit', dItem(['event_type' => 'split_expense', 'amount' => '2400', 'category' => 'dinner', 'counterparty' => null, 'participants' => $people('Rahul', 'Amit')]));

        expect(sentTexts()[0])->toContain('you ₹800, Rahul ₹800, Amit ₹800');
        waTap($this, lastButtons()['confirm']);

        expect(balanceOf(account($this->user, 'Expenses')))->toBe(80000)->and(balanceOf(account($this->user, 'Cash')))->toBe(-240000)
            ->and(Counterparty::count())->toBe(2)->and(DebtRecord::count())->toBe(2);

        ($this->say)('who owes me?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'owed_to_me']));

        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('Owed to you')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Rahul: ₹800')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Total:* ₹1,600');
    });

    it('never records a split whose shares exceed the bill', function () {
        ($this->say)('dinner 1000, Rahul 800, Amit 500', dItem(['event_type' => 'split_expense', 'amount' => '1000', 'category' => 'dinner', 'counterparty' => null,
            'participants' => [['name' => 'Rahul', 'amount' => '800'], ['name' => 'Amit', 'amount' => '500']]]));

        expect(sentTexts()[0])->toContain('add up to more than')->and(LedgerTransaction::count())->toBe(0);
    });
});

describe('credit card bill payment', function () {
    it('confirms, then reduces the card balance without creating an expense', function () {
        $card = app(AccountService::class)->create($this->user, 'HDFC Card', AccountSubtype::CreditCard);
        ledger()->post(command($this->user, TransactionType::Expense, '4000', ['accountId' => $card->id, 'categoryId' => category($this->user, 'Shopping')->id]));
        ledger()->post(command($this->user, TransactionType::Income, '10000', ['categoryId' => category($this->user, 'Salary')->id]));

        ($this->say)('paid HDFC card 4000', dItem(['event_type' => 'credit_card_payment', 'amount' => '4000', 'counterparty' => null, 'to_account' => 'HDFC Card']));
        expect(sentTexts()[0])->toContain('card payment of ₹4,000');
        waTap($this, lastButtons()['confirm']);

        expect(balanceOf($card))->toBe(0)->and(balanceOf(account($this->user, 'Expenses')))->toBe(400000)->and(sentTexts()[1])->toContain('not counted as an expense')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('questions about people', function () {
    it('lists what I owe and answers for one person', function () {
        ($this->say)('rahul se 5000 liya', dItem(['event_type' => 'borrow', 'amount' => '5000']));
        waTap($this, lastButtons()['confirm']);

        ($this->say)('what do I owe?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'i_owe']));
        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('You owe')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Rahul: ₹5,000');

        ($this->say)('how much does Rahul owe me?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'owed_to_me', 'counterparty' => 'Rahul']));
        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain("Rahul doesn't owe you anything");

        ($this->say)('how much does Zed owe me?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'owed_to_me', 'counterparty' => 'Zed']));
        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('don\'t have a person called "Zed"');
    });

    it('includes money between people in balance and net worth', function () {
        $this->lend = null;
        ($this->say)('gave Rahul 2000', dItem());
        waTap($this, lastButtons()['confirm']);
        waText($this, 'balance');

        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('Owed to you: ₹2,000')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Net worth:* ₹0');
    });
});

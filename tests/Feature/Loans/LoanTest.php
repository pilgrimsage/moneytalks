<?php

use App\Domain\Ledger\LedgerVerifier;
use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Loan;
use App\Services\AI\FakeAIProvider;
use App\Services\Loans\LoanService;
use App\Services\Speech\FakeSpeechProvider;
use App\Services\Speech\SpeechToTextProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

function lItem(array $o = []): array
{
    return aiItem($o + ['intent' => 'create_loan', 'event_type' => null, 'amount' => '120000', 'category' => null, 'description' => 'bike', 'interest_rate' => '10.5', 'tenure_months' => 24, 'emi_amount' => '5538']);
}

function emiItem(array $o = []): array
{
    return aiItem($o + ['event_type' => 'emi_payment', 'amount' => null, 'category' => null, 'description' => 'bike']);
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
    ledger()->post(command($this->user, TransactionType::Income, '500000', ['categoryId' => category($this->user, 'Salary')->id]));
    // Tracking a loan writes to the ledger, so it always needs a Confirm tap: say it, then tap.
    $this->loan = function (string $text, array $item) {
        FakeAIProvider::respond(aiEnvelope($item));
        waText($this, $text);
        waTap($this, lastButtons()['confirm']);
    };
    $this->loanAccount = fn () => LedgerAccount::where('user_id', $this->user->id)->where('name', 'Loan: Bike')->firstOrFail();
});

describe('the maths', function () {
    it('computes the standard EMI, rounds interest half-up in whole minor units, and handles a zero rate', function () {
        expect(LoanService::emiFor(12000000, 1050, 24))->toBe(556512)->and(LoanService::emiFor(100000, 0, 3))->toBe(33334)
            ->and(LoanService::interestFor(12000000, 1050))->toBe(105000)->and(LoanService::interestFor(1, 1050))->toBe(0)
            ->and(LoanService::interestFor(1000, 6000))->toBe(50) // 5%/month of 10.00 = 0.50 exactly
            ->and(LoanService::monthsLeft(12000000, 1050, 553800))->toBe(25)->and(LoanService::monthsLeft(12000000, 1050, 100))->toBeNull();
    });

    it('amortises to exactly zero: principal parts of all instalments sum to the loan', function () {
        $out = 12000000;
        $paid = 0;
        for ($m = 1; $m < 100 && $out > 0; $m++) {
            $interest = LoanService::interestFor($out, 1050);
            $principal = min(556512 - $interest, $out);
            $out -= $principal;
            $paid += $principal;
        }

        expect($out)->toBe(0)->and($paid)->toBe(12000000);
    });
});

describe('tracking a loan', function () {
    it('opens a liability account with the outstanding amount and keeps the real EMI when the user states it', function () {
        ($this->loan)('bike loan 120000 outstanding at 10.5% for 24 months, emi 5538', lItem());

        expect(sentTexts()[0])->toContain('Start tracking the Bike loan: ₹120000 outstanding at 10.5% for 24 months?')->and(Loan::count())->toBe(1);
        $l = Loan::firstOrFail();
        expect($l->emi_minor)->toBe(553800)->and($l->rate_bp)->toBe(1050)->and(balanceOf(($this->loanAccount)()))->toBe(12000000)
            ->and(sentTexts()[1])->toContain('Tracking the *Bike* loan: ₹1,20,000 outstanding at 10.5%, EMI ₹5,538')->and(sentTexts()[1])->not->toContain('my estimate')
            ->and(balanceOf(account($this->user, 'Cash')))->toBe(50000000)->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('estimates the EMI when it is not stated and says so', function () {
        ($this->loan)('bike loan 120000 at 10.5% for 24 months', lItem(['emi_amount' => null]));

        expect(Loan::firstOrFail()->emi_minor)->toBe(556512)->and(sentTexts()[1])->toContain('₹5,565.12 (my estimate');
    });

    it('asks for what is missing and refuses nonsense', function () {
        ($this->loan)('bike loan 120000', lItem(['interest_rate' => null]));
        ($this->loan)('bike loan 120000 at 10%', lItem(['tenure_months' => null]));
        ($this->loan)('bike loan 120000 at 10% for 24 months emi 100', lItem(['emi_amount' => '100']));
        ($this->loan)('bike loan at 10% for 24 months', lItem(['amount' => '999999']));

        expect(sentTexts()[1])->toContain('yearly interest rate')->and(sentTexts()[3])->toContain('How many months')->and(sentTexts()[5])->toContain('would not even cover the interest')
            ->and(sentTexts()[7])->toContain("can't find that amount")->and(Loan::count())->toBe(0);
    });
});

describe('paying an EMI', function () {
    beforeEach(function () {
        ($this->loan)('bike loan 120000 outstanding at 10.5% for 24 months, emi 5538', lItem());
        FakeWhatsAppProvider::reset();
    });

    it('splits interest and principal, confirms first, and the interest is the only expense', function () {
        ($this->say)('paid bike EMI', emiItem());

        expect(sentTexts()[0])->toContain('an EMI of ₹5,538 to Bike (principal ₹4,488, interest ₹1,050)');
        waTap($this, lastButtons()['confirm']);

        expect(balanceOf(($this->loanAccount)()))->toBe(12000000 - 448800)->and(balanceOf(account($this->user, 'Expenses')))->toBe(105000)
            ->and(balanceOf(account($this->user, 'Cash')))->toBe(50000000 - 553800)->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('principal ₹4,488, interest ₹1,050')
            ->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Outstanding on Bike: ₹1,15,512')->and(app(LedgerVerifier::class)->verify())->toBe([]);

        // the interest is booked under "Loan Interest"
        $interestEntry = LedgerEntry::whereNotNull('category_id')->firstOrFail();
        expect($interestEntry->category_id)->toBe(category($this->user, 'Loan Interest')->id);
    });

    it('charges less interest in the second month', function () {
        ($this->say)('paid bike EMI', emiItem());
        waTap($this, lastButtons()['confirm']);
        ($this->say)('paid bike EMI', emiItem());

        // outstanding 1,15,512 -> interest 1,010.73 (rounded half up: 101073 minor)
        expect(sentTexts()[2])->toContain('interest ₹1,010.73');
    });

    it('does not guess when the payment is below the interest, or when no loan matches', function () {
        ($this->say)('paid 500 bike EMI', emiItem(['amount' => '500']));
        ($this->say)('paid car EMI', emiItem(['description' => 'car']));

        expect(sentTexts()[0])->toContain("less than this month's interest")->and(sentTexts()[1])->toContain('Which loan: Bike')
            ->and(LedgerTransaction::where('type', 'emi_payment')->count())->toBe(0);
    });

    it('closes a loan with the final instalment', function () {
        ($this->loan)('phone loan 1000 at 12% for 1 month', lItem(['description' => 'phone', 'amount' => '1000', 'interest_rate' => '12', 'tenure_months' => 1, 'emi_amount' => null]));
        ($this->say)('paid phone EMI', emiItem(['description' => 'phone']));
        waTap($this, lastButtons()['confirm']);

        expect(Loan::where('name', 'Phone')->first()->status)->toBe('closed')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('fully paid off')
            ->and(balanceOf(LedgerAccount::where('name', 'Loan: Phone')->first()))->toBe(0);
    });

    it('shows loans with outstanding amount and months left', function () {
        ($this->say)('what loans do I have?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'loans']));

        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('Bike: ₹1,20,000 left · EMI ₹5,538 at 10.5%, about 25 months to go');
    });
});

describe('confirmation policy', function () {
    it('never creates the loan or its opening balance before the tap, and Cancel drops it', function () {
        FakeAIProvider::respond(aiEnvelope(lItem()));
        waText($this, 'bike loan 120000 outstanding at 10.5% for 24 months, emi 5538');

        expect(Loan::count())->toBe(0)->and(LedgerAccount::where('name', 'Loan: Bike')->exists())->toBeFalse()->and(lastButtonTitles())->toBe(['Confirm', 'Cancel']);

        waTap($this, lastButtons()['cancel'], 'Cancel');
        expect(Loan::count())->toBe(0);
    });

    it('also asks before applying budgets, goals, recurring changes and undo when they come from a voice note', function () {
        config(['stt.provider' => 'fake']);
        app()->forgetInstance(SpeechToTextProvider::class);
        FakeWhatsAppProvider::$media['V'] = ['OggS-audio', 'audio/ogg'];
        $voice = function (string $words, array $item) {
            FakeSpeechProvider::reset();
            FakeSpeechProvider::respond($words);
            FakeAIProvider::respond(aiEnvelope($item));
            postWebhook($this, waFactory()->media('919876543210', 'audio', 'V'))->assertOk();
        };

        $voice('set a budget of 5000 for food', aiItem(['intent' => 'create_budget', 'event_type' => null, 'amount' => '5000', 'category' => 'food', 'action' => 'set']));
        expect(Budget::count())->toBe(0)->and(sentTexts()[0])->toContain('Set the food budget to ₹5000 per month?');
        waTap($this, lastButtons()['confirm']);
        expect(Budget::count())->toBe(1);

        $voice('save 10000 for a bike', aiItem(['intent' => 'create_goal', 'event_type' => null, 'amount' => '10000', 'description' => 'bike']));
        expect(Goal::count())->toBe(0);
        waTap($this, lastButtons()['confirm']);
        expect(Goal::count())->toBe(1);

        ledger()->post(command($this->user, TransactionType::Expense, '100', ['categoryId' => category($this->user, 'Fuel')->id]));
        $voice('undo', aiItem(['intent' => 'undo_transaction', 'event_type' => null, 'amount' => null, 'target_kind' => 'last']));
        expect(LedgerTransaction::where('status', 'reversed')->count())->toBe(0);   // asked first, not undone
        waTap($this, lastButtons()['confirm']);
        expect(LedgerTransaction::where('status', 'reversed')->count())->toBe(1);
    });
});

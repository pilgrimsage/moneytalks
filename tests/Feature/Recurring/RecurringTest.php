<?php

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\LedgerTransaction;
use App\Models\RecurringOccurrence;
use App\Models\RecurringRule;
use App\Models\WhatsappMessage;
use App\Services\AI\FakeAIProvider;
use App\Services\Recurring\RecurringService;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use Carbon\CarbonImmutable;

function rItem(array $o = []): array
{
    return aiItem($o + ['intent' => 'create_recurring', 'event_type' => 'expense', 'amount' => '649', 'category' => null, 'merchant' => 'Netflix', 'recurrence' => 'monthly']);
}

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    // freeze the clock: the dates below are fixed, so the tests must not depend on the real date
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00', 'Asia/Kolkata'));
    $this->user = ledgerUser('919876543210');
    $this->say = function (string $text, array $item) {
        FakeAIProvider::respond(aiEnvelope($item));
        waText($this, $text);
    };
    // the user messaged recently, so the 24h window is open for reminders
    $this->user->forceFill(['last_inbound_at' => now()])->save();
    $this->tick = fn (string $date) => app(RecurringService::class)->tick(CarbonImmutable::parse($date.' 09:00', 'Asia/Kolkata')->utc());
});

describe('creating rules', function () {
    it('creates a monthly rule whose first due date is one period away, and records nothing', function () {
        ($this->say)('Netflix 649 every month', rItem(['category' => 'entertainment']));

        $r = RecurringRule::firstOrFail();
        expect($r->name)->toBe('Netflix')->and($r->amount_minor)->toBe(64900)->and($r->frequency)->toBe('monthly')
            ->and($r->next_due_on->format('Y-m-d'))->toBe('2026-11-04')->and(LedgerTransaction::count())->toBe(0)
            ->and(sentTexts()[0])->toContain('Added *Netflix*')->and(sentTexts()[0])->toContain('nothing is recorded until you tap Paid');
    });

    it('uses a stated day and rolls it forward if it already passed this month', function () {
        ($this->say)('rent 15000 on the 1st every month', rItem(['merchant' => null, 'category' => 'rent', 'amount' => '15000', 'date' => aiDate('day_of_month', ['day' => 1])]));

        expect(RecurringRule::firstOrFail()->next_due_on->format('Y-m-d'))->toBe('2026-11-01');
    })->skip(fn () => ! Category::query()->where('name', 'Rent')->exists() && false);

    it('asks for what it needs: frequency, category, and does not duplicate an existing rule', function () {
        ($this->say)('Netflix 649', rItem(['recurrence' => null, 'category' => 'entertainment']));
        expect(sentTexts()[0])->toContain('How often');

        ($this->say)('Zorp 100 monthly', rItem(['merchant' => 'Zorp', 'amount' => '100', 'category' => null]));
        expect(sentTexts()[1])->toBe('Which category is Zorp?');
        waText($this, 'entertainment');
        expect(RecurringRule::count())->toBe(1);

        ($this->say)('Zorp 120 monthly', rItem(['merchant' => 'Zorp', 'amount' => '120', 'category' => 'entertainment']));
        expect(RecurringRule::count())->toBe(1)->and(RecurringRule::first()->amount_minor)->toBe(12000)->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Updated');
    });

    it('can stop a rule by name', function () {
        ($this->say)('Netflix 649 every month', rItem(['category' => 'entertainment']));
        ($this->say)('cancel netflix', aiItem(['intent' => 'create_recurring', 'event_type' => null, 'amount' => null, 'category' => null, 'merchant' => 'Netflix', 'action' => 'remove']));

        expect(RecurringRule::firstOrFail()->status)->toBe('cancelled')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Stopped *Netflix*');
    });
});

describe('reminders and the Paid tap', function () {
    beforeEach(function () {
        ($this->say)('Netflix 649 every month', rItem(['category' => 'entertainment']));
        FakeWhatsAppProvider::reset();
    });

    it('sends nothing before the due date, then one reminder with Paid/Skip, once', function () {
        expect(($this->tick)('2026-11-03'))->toBe(0);
        expect(($this->tick)('2026-11-04'))->toBe(1)->and(($this->tick)('2026-11-04'))->toBe(0);

        expect(sentTexts()[0])->toContain('*Netflix* ₹649 is due today.')->and(lastButtonTitles())->toBe(['Paid', 'Skip'])->and(RecurringOccurrence::count())->toBe(1);
    });

    it('records the payment once however often Paid is tapped, and moves to the next month', function () {
        ($this->tick)('2026-11-04');
        $paid = lastButtons()['paid'];
        waTap($this, $paid, 'Paid');
        waTap($this, $paid, 'Paid');

        $tx = LedgerTransaction::firstOrFail();
        expect(LedgerTransaction::count())->toBe(1)->and($tx->type)->toBe(TransactionType::Expense)->and($tx->occurred_on->format('Y-m-d'))->toBe('2026-11-04')
            ->and(balanceOf(account($this->user, 'Expenses')))->toBe(64900)->and(RecurringRule::first()->next_due_on->format('Y-m-d'))->toBe('2026-12-04')
            ->and(RecurringOccurrence::first()->status)->toBe('paid')->and(sentTexts()[1])->toContain('Recorded Netflix')->and(sentTexts()[2])->toContain('expired');
    });

    it('skip records nothing and advances', function () {
        ($this->tick)('2026-11-04');
        waTap($this, lastButtons()['skip'], 'Skip');

        expect(LedgerTransaction::count())->toBe(0)->and(RecurringOccurrence::first()->status)->toBe('skipped')->and(RecurringRule::first()->next_due_on->format('Y-m-d'))->toBe('2026-12-04');
    });

    it('nags once more after two days, then stops; never floods after a long silence', function () {
        ($this->tick)('2026-11-04');
        expect(($this->tick)('2026-11-05'))->toBe(0)->and(($this->tick)('2026-11-06'))->toBe(1)->and(($this->tick)('2026-11-20'))->toBe(0)
            ->and(RecurringOccurrence::count())->toBe(1)->and(RecurringOccurrence::first()->reminders_sent)->toBe(2);
    });

    it('does not remind about a payment the user already logged themselves', function () {
        RecurringRule::query()->update(['anchor_on' => '2026-10-04', 'next_due_on' => '2026-10-04']);
        ($this->tick)('2026-10-04');
        FakeWhatsAppProvider::reset();
        ($this->say)('paid netflix 649', aiItem(['amount' => '649', 'category' => 'entertainment', 'merchant' => 'Netflix']));

        expect(sentTexts()[0])->toContain('That matches your *Netflix* payment')->and(RecurringOccurrence::first()->status)->toBe('paid')
            ->and(LedgerTransaction::count())->toBe(1)->and(RecurringRule::first()->next_due_on->format('Y-m-d'))->toBe('2026-11-04');
    });

    it('records a failed reminder when the 24h window is closed instead of dropping it silently', function () {
        $this->user->forceFill(['last_inbound_at' => now()->subDays(3)])->save();

        expect(($this->tick)('2026-11-04'))->toBe(0)->and(WhatsappMessage::where('status', 'failed')->where('error', 'like', '%outside_window%')->count())->toBe(1);
    });
});

describe('questions', function () {
    it('lists subscriptions with a monthly total and what is due soon', function () {
        ($this->say)('Netflix 649 every month', rItem(['category' => 'entertainment']));
        ($this->say)('Prime 1499 yearly', rItem(['merchant' => 'Prime', 'amount' => '1499', 'recurrence' => 'yearly', 'category' => 'entertainment']));

        ($this->say)('what are my subscriptions?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'subscriptions']));
        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('Netflix: ₹649 monthly')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('About ₹773.91 a month');

        RecurringRule::where('name', 'Netflix')->update(['next_due_on' => '2026-10-20']);
        ($this->say)('what is coming up?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'upcoming_bills']));
        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('20 Oct · Netflix: ₹649')->and(end(FakeWhatsAppProvider::$sent)->body)->not->toContain('Prime');
    });
});

describe('dates', function () {
    it('keeps the day of month across short months and never drifts', function () {
        $a = CarbonImmutable::parse('2026-01-31');

        expect(RecurringService::dueOn($a, 'monthly', 1)->format('Y-m-d'))->toBe('2026-02-28')->and(RecurringService::dueOn($a, 'monthly', 2)->format('Y-m-d'))->toBe('2026-03-31')
            ->and(RecurringService::dueOn($a, 'weekly', 2)->format('Y-m-d'))->toBe('2026-02-14')->and(RecurringService::monthlyMinor(120000, 'yearly'))->toBe(10000);
    });
});

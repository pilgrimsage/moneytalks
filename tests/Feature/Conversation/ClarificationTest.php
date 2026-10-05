<?php

use App\Domain\Ledger\AccountService;
use App\Enums\AccountSubtype;
use App\Jobs\ProcessWebhookEvent;
use App\Models\AiRequest;
use App\Models\ConversationState;
use App\Models\LedgerTransaction;
use App\Models\UserAlias;
use App\Models\WebhookEvent;
use App\Services\AI\FakeAIProvider;
use App\Services\Conversation\ConversationStore;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser();
});

describe('"paid 500" -> "for what?" -> "groceries"', function () {
    beforeEach(function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '500', 'category' => null, 'missing_fields' => ['category']])));
        waText($this, 'paid 500');
    });

    it('asks the question and remembers it (for a limited time)', function () {
        $state = ConversationState::where('user_id', $this->user->id)->first();

        expect(sentTexts())->toBe(['₹500 paid for what?'])->and(LedgerTransaction::count())->toBe(0)
            ->and($state->kind)->toBe('clarify')->and($state->payload['awaiting'])->toBe('category')
            ->and($state->expires_at->diffInMinutes(now(), true))->toBeBetween(9, 10)
            ->and(DB::table('conversation_states')->first()->payload)->not->toContain('500');   // encrypted at rest
    });

    it('completes the transaction from the answer WITHOUT another AI call', function () {
        waText($this, 'groceries');

        $tx = LedgerTransaction::firstOrFail();
        expect(count(FakeAIProvider::$requests))->toBe(1)->and(AiRequest::count())->toBe(1)
            ->and($tx->debit_total_minor)->toBe(50000)
            ->and(sentTexts()[1])->toContain('Recorded ₹500 expense under *Groceries*')
            ->and(ConversationState::count())->toBe(0);
    });

    it('understands "groceries using UPI" (category plus payment method)', function () {
        waText($this, 'groceries using UPI');

        expect(LedgerTransaction::firstOrFail()->payment_method)->toBe('upi')->and(sentTexts()[1])->toContain('under *Groceries*');
    });

    it('understands Hinglish and Devanagari answers', function (string $answer, string $expected) {
        waText($this, $answer);

        expect(sentTexts()[1])->toContain("under *{$expected}*");
    })->with([['sabji', 'Vegetables'], ['सब्जी', 'Vegetables'], ['petrol', 'Fuel'], ['vegtables', 'Vegetables']]);

    it('keeps asking if the answer is not a category, and gives up politely after three tries', function () {
        waText($this, 'blah blah');   // not an answer -> treated as a new message (AI says: unknown)

        expect(ConversationState::count())->toBe(0);
    });

    it('treats an answer that contains a new amount as a new message, not a category', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '300', 'category' => 'petrol'])));

        waText($this, '300 petrol');

        expect(LedgerTransaction::count())->toBe(1)->and(LedgerTransaction::first()->debit_total_minor)->toBe(30000)
            ->and(count(FakeAIProvider::$requests))->toBe(2);
    });

    it('lets the user cancel the question', function (string $word) {
        waText($this, $word);

        expect(ConversationState::count())->toBe(0)->and(LedgerTransaction::count())->toBe(0)->and(sentTexts()[1])->toContain('cancelled');
    })->with(['cancel', 'no', 'nahi']);

    it('forgets the question after the time limit', function () {
        $this->travel(11)->minutes();
        FakeAIProvider::respond(aiEnvelope(aiItem(['intent' => 'unknown', 'event_type' => null, 'amount' => null])));

        waText($this, 'groceries');

        expect(LedgerTransaction::count())->toBe(0)->and(count(FakeAIProvider::$requests))->toBe(2);   // read as a brand-new (unclear) message
    });

    it('is not fooled by a different user\'s pending question', function () {
        $other = ledgerUser('919111111111');
        config(['moneytalks.allowed_wa_ids' => ['919876543210', '919111111111']]);

        waText($this, 'groceries', null, '919111111111');

        expect(LedgerTransaction::where('user_id', $other->id)->count())->toBe(0);
    });
});

describe('other missing details', function () {
    it('asks for a missing amount and accepts a bare number', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => null, 'missing_fields' => ['amount']])));
        waText($this, 'spent on vegetables');
        expect(sentTexts())->toBe(['How much was it?']);

        waText($this, '₹250');

        expect(LedgerTransaction::firstOrFail()->debit_total_minor)->toBe(25000)->and(count(FakeAIProvider::$requests))->toBe(1);
    });

    it('asks which account when an unknown one is named and accepts an exact name', function () {
        $bank = app(AccountService::class)->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
        UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'account', 'entity_id' => $bank->id, 'alias' => 'hdfc']);
        FakeAIProvider::respond(aiEnvelope(aiItem(['account' => 'axis bank'])));
        waText($this, 'spent 250 on vegetables from axis bank');
        expect(sentTexts()[0])->toContain('Your accounts:');

        waText($this, 'hdfc');

        expect(sentTexts()[1])->toContain('From: HDFC Bank')->and(balanceOf($bank))->toBe(-25000);
    });

    it('never accepts a fuzzy account name as an answer', function () {
        $bank = app(AccountService::class)->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
        FakeAIProvider::respond(aiEnvelope(aiItem(['account' => 'axis bank'])));
        waText($this, 'spent 250 on vegetables from axis bank');
        FakeAIProvider::respond(aiEnvelope(aiItem(['intent' => 'unknown', 'event_type' => null, 'amount' => null])));

        waText($this, 'hdfcc bnk');

        expect(LedgerTransaction::count())->toBe(0)->and(balanceOf($bank))->toBe(0);
    });

    it('gives up after three follow-up answers that still do not work, and asks for the whole message', function () {
        $card = app(AccountService::class)->create($this->user, 'HDFC Credit Card', AccountSubtype::CreditCard);
        UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'account', 'entity_id' => $card->id, 'alias' => 'cc']);
        // Salary into a credit card is never acceptable, so every "cc" answer is understood but refused again.
        FakeAIProvider::respond(aiEnvelope(aiItem(['event_type' => 'income', 'category' => 'salary', 'amount' => '45000', 'account' => 'cc'])));
        waText($this, 'salary 45000 into cc');
        expect(ConversationState::count())->toBe(1)->and(sentTexts()[0])->toContain("can't use");

        waText($this, 'cc');
        waText($this, 'cc');
        expect(ConversationState::count())->toBe(1);

        waText($this, 'cc');   // the third answer

        expect(ConversationState::count())->toBe(0)->and(sentTexts()[3])->toContain("Let's start over")->and(LedgerTransaction::count())->toBe(0)
            ->and(count(FakeAIProvider::$requests))->toBe(1);   // all of it without a second AI call
    });

    it('does not offer a follow-up for an impossible date; the user must resend', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['date' => aiDate('iso', ['iso' => '2026-02-30'])])));

        waText($this, 'spent 250 on vegetables on 30 feb');

        expect(ConversationState::count())->toBe(0)->and(sentTexts()[0])->toContain('Which date was it?');
    });
});

describe('retries', function () {
    it('keeps the same question (and its id) when the same message is processed twice', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '500', 'category' => null, 'missing_fields' => ['category']])));
        waText($this, 'paid 500', 'wamid.Q1');
        $first = ConversationState::first();

        ProcessWebhookEvent::dispatchSync(WebhookEvent::first()->id);   // stray extra job

        expect(ConversationState::count())->toBe(1)->and(ConversationState::first()->id)->toBe($first->id)->and(sentTexts())->toHaveCount(1);
    });
});

describe('the conversation store', function () {
    it('purges expired items on a schedule', function () {
        ConversationState::create(['user_id' => $this->user->id, 'kind' => 'clarify', 'payload' => ['x' => 1], 'source_wa_message_id' => 'w', 'expires_at' => now()->subMinute()]);

        expect(app(ConversationStore::class)->purgeExpired())->toBe(1)->and(ConversationState::count())->toBe(0);
    });

    it('has a command that purges them, run hourly by the scheduler', function () {
        ConversationState::create(['user_id' => $this->user->id, 'kind' => 'clarify', 'payload' => ['x' => 1], 'source_wa_message_id' => 'w', 'expires_at' => now()->subMinute()]);

        $this->artisan('moneytalks:conversation:purge')->expectsOutputToContain('Purged 1 expired')->assertSuccessful();

        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        expect(ConversationState::count())->toBe(0)->and($commands)->toContain('moneytalks:conversation:purge');
    });

    it('allows only one pending item per user', function () {
        $store = app(ConversationStore::class);
        $store->put($this->user, 'clarify', ['a' => 1], 'w1');
        $store->put($this->user, 'confirm', ['b' => 2], 'w2');

        expect(ConversationState::count())->toBe(1)->and(ConversationState::first()->kind)->toBe('confirm');
    });
});

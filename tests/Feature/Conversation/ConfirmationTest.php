<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\LedgerVerifier;
use App\Enums\AccountSubtype;
use App\Models\ConversationState;
use App\Models\LedgerTransaction;
use App\Models\UserAlias;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser();
    $svc = app(AccountService::class);
    $this->bank = $svc->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
    $this->sbi = $svc->create($this->user, 'SBI Bank', AccountSubtype::Bank);
    foreach ([[$this->bank, 'hdfc'], [$this->sbi, 'sbi']] as [$a, $alias]) {
        UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'account', 'entity_id' => $a->id, 'alias' => $alias]);
    }
    $this->transfer = fn () => aiEnvelope(aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'account' => 'sbi', 'to_account' => 'hdfc']));
});

describe('a transfer always waits for a tap', function () {
    beforeEach(function () {
        FakeAIProvider::respond(($this->transfer)());
        waText($this, 'transfer 1000 from SBI to HDFC');
    });

    it('asks first, with Confirm and Cancel buttons, and records nothing yet', function () {
        expect(LedgerTransaction::count())->toBe(0)->and(sentTexts())->toHaveCount(1)   // just the question
            ->and(lastButtonTitles())->toBe(['Confirm', 'Cancel'])
            ->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('Record a transfer of ₹1,000 from SBI Bank to HDFC Bank, today?')
            ->and(ConversationState::first()->kind)->toBe('confirm');
    });

    it('records it when Confirm is tapped, and says no money was moved', function () {
        waTap($this, lastButtons()['confirm']);

        expect(balanceOf($this->bank))->toBe(100_000)->and(balanceOf($this->sbi))->toBe(-100_000)->and(balanceOf(account($this->user, 'Expenses')))->toBe(0)
            ->and(sentTexts()[1])->toContain('transfer of ₹1,000 from *SBI Bank* to *HDFC Bank*')->and(sentTexts()[1])->toContain('no money was actually sent')
            ->and(ConversationState::count())->toBe(0)->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('records it when the user types yes (or haan)', function (string $word) {
        waText($this, $word);

        expect(LedgerTransaction::count())->toBe(1)->and(balanceOf($this->bank))->toBe(100_000);
    })->with(['yes', 'Yes!', 'haan', 'ok']);

    it('drops it when Cancel is tapped or the user types no', function () {
        waTap($this, lastButtons()['cancel'], 'Cancel');

        expect(LedgerTransaction::count())->toBe(0)->and(sentTexts()[1])->toContain('cancelled')->and(ConversationState::count())->toBe(0);
    });

    it('applies a tap only once, however many times the button is pressed', function () {
        $confirm = lastButtons()['confirm'];
        waTap($this, $confirm);
        waTap($this, $confirm);
        waTap($this, $confirm);

        expect(LedgerTransaction::count())->toBe(1)->and(balanceOf($this->bank))->toBe(100_000)->and(sentTexts()[2])->toContain('expired');
    });

    it('records at most once even if the confirming message is delivered twice in different wrappers', function () {
        $payload = waFactory()->buttonReply('919876543210', lastButtons()['confirm'], 'Confirm', 'wamid.TAP1');
        $variant = $payload;
        $variant['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] = 'Renamed';

        postWebhook($this, $payload)->assertOk();
        postWebhook($this, $variant)->assertOk();

        expect(LedgerTransaction::count())->toBe(1);
    });

    it('expires after the time limit', function () {
        $confirm = lastButtons()['confirm'];
        $this->travel(11)->minutes();

        waTap($this, $confirm);

        expect(LedgerTransaction::count())->toBe(0)->and(sentTexts()[1])->toContain('expired');
    });

    it('cannot be confirmed by a button that belongs to someone else\'s request', function () {
        $other = ledgerUser('919111111111');
        config(['moneytalks.allowed_wa_ids' => ['919876543210', '919111111111']]);
        $mine = lastButtons()['confirm'];

        waTap($this, $mine, 'Confirm', '919111111111');

        expect(LedgerTransaction::count())->toBe(0)->and(ConversationState::where('user_id', $this->user->id)->count())->toBe(1)
            ->and(ConversationState::where('user_id', $other->id)->count())->toBe(0);
    });

    it('is cancelled (never applied) when the user moves on to something else', function () {
        $stale = lastButtons()['confirm'];
        FakeAIProvider::respond(aiEnvelope(aiItem()));

        waText($this, 'spent 250 on vegetables');
        waTap($this, $stale);

        // only the new, unrelated expense is recorded; the old transfer was cancelled when we moved on
        expect(LedgerTransaction::count())->toBe(1)->and(LedgerTransaction::first()->type->value)->toBe('expense')
            ->and(sentTexts()[1])->toContain('earlier request waiting for confirmation was cancelled')
            ->and(sentTexts()[2])->toContain('expired');
    });

    it('is refused cleanly if the ledger no longer accepts it (e.g. the account was closed meanwhile)', function () {
        $this->sbi->update(['status' => 'closed']);

        waTap($this, lastButtons()['confirm']);

        expect(LedgerTransaction::count())->toBe(0)->and(sentTexts()[1])->toContain('couldn\'t do that')->and(ConversationState::count())->toBe(0);
    });
});

it('has nothing to confirm when there is no pending request', function () {
    waText($this, 'yes');

    expect(sentTexts())->toBe(['There is nothing waiting for a reply right now.'])->and(FakeAIProvider::$requests)->toBe([]);
});

describe('other reasons to ask', function () {
    it('asks about a large expense, and records it exactly once after Confirm', function () {
        config(['ai.risk.confirm_above_minor' => 5_000_000]);
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '60000', 'category' => 'shopping'])));

        waText($this, 'spent 60000 on shopping');
        expect(LedgerTransaction::count())->toBe(0)->and(FakeWhatsAppProvider::$sent[0]->body)->toContain("That's a large amount.");

        waTap($this, lastButtons()['confirm']);

        expect(LedgerTransaction::count())->toBe(1)->and(balanceOf(account($this->user, 'Cash')))->toBe(-6_000_000)
            ->and(LedgerTransaction::first()->idempotency_key)->toStartWith('confirm:');
    });

    it('asks "I think you meant this" for a read it is only fairly sure about', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['confidence' => 0.5])));

        waText($this, 'spent 250 on vegetables');

        expect(LedgerTransaction::count())->toBe(0)->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('I think you meant this.')
            ->and(lastButtonTitles())->toBe(['Confirm', 'Cancel']);

        waText($this, 'yes');
        expect(LedgerTransaction::count())->toBe(1);
    });

    it('only ever asks about one thing at a time', function () {
        FakeAIProvider::respond(aiEnvelope(
            aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'account' => 'sbi', 'to_account' => 'hdfc']),
            aiItem(['amount' => '500', 'category' => null, 'missing_fields' => ['category']]),
        ));

        waText($this, 'transfer 1000 sbi to hdfc and paid 500');

        expect(ConversationState::count())->toBe(1)->and(ConversationState::first()->kind)->toBe('confirm')
            ->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('send it again after answering');
    });

    it('records the sure items immediately and asks about the unsure one in the same reply', function () {
        FakeAIProvider::respond(aiEnvelope(
            aiItem(['event_type' => 'income', 'category' => 'salary', 'amount' => '45000']),
            aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'account' => 'sbi', 'to_account' => 'hdfc']),
        ));

        waText($this, 'salary 45000 and transfer 1000 sbi to hdfc');

        expect(LedgerTransaction::count())->toBe(1)->and(FakeWhatsAppProvider::$sent[0]->kind)->toBe('buttons')
            ->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('income under *Salary*')->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('Record a transfer');
    });
});

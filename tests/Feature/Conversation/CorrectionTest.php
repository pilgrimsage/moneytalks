<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\LedgerVerifier;
use App\Enums\AccountSubtype;
use App\Enums\TransactionType;
use App\Models\ConversationState;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\UserAlias;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser();
    $this->original = ledger()->post(command($this->user, TransactionType::Expense, '500', [
        'categoryId' => category($this->user, 'Groceries')->id, 'occurredOn' => now($this->user->timezone)->format('Y-m-d'),
    ]))->transaction;
    $this->fix = fn (array $o) => aiEnvelope(aiItem($o + [
        'intent' => 'correct_transaction', 'event_type' => null, 'amount' => null, 'category' => null, 'target_kind' => 'last',
    ]));
});

describe('"actually that was 600, not 500"', function () {
    beforeEach(function () {
        FakeAIProvider::respond(($this->fix)(['amount' => '600', 'target_amount' => '500']));
        waText($this, 'Actually that was 600, not 500');
    });

    it('shows before and after and waits for Apply', function () {
        expect(balanceOf(account($this->user, 'Cash')))->toBe(-50000)->and(lastButtonTitles())->toBe(['Apply', 'Cancel'])
            ->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('Change ₹500 expense under Groceries')->and(FakeWhatsAppProvider::$sent[0]->body)->toContain('to ₹600 expense under Groceries');
    });

    it('applies it as reverse + repost, keeping the whole history', function () {
        waTap($this, lastButtons()['confirm'], 'Apply');

        $replacement = LedgerTransaction::where('corrects_id', $this->original->id)->firstOrFail();
        expect(balanceOf(account($this->user, 'Cash')))->toBe(-60000)->and(balanceOf(account($this->user, 'Expenses')))->toBe(60000)
            ->and($replacement->debit_total_minor)->toBe(60000)->and($replacement->source->value)->toBe('whatsapp_text')
            ->and($this->original->fresh()->status->value)->toBe('reversed')
            ->and(LedgerTransaction::count())->toBe(3)                                    // original, reversal, replacement
            ->and(sentTexts()[1])->toContain('✏️ Updated.')->and(sentTexts()[1])->toContain('Was: ₹500')->and(sentTexts()[1])->toContain('Now: ₹600')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('changes nothing when cancelled', function () {
        waTap($this, lastButtons()['cancel'], 'Cancel');

        expect(balanceOf(account($this->user, 'Cash')))->toBe(-50000)->and(LedgerTransaction::count())->toBe(1)->and(ConversationState::count())->toBe(0);
    });

    it('applies only once however many times Apply is pressed', function () {
        $apply = lastButtons()['confirm'];
        waTap($this, $apply);
        waTap($this, $apply);

        expect(LedgerTransaction::count())->toBe(3)->and(balanceOf(account($this->user, 'Cash')))->toBe(-60000);
    });

    it('can be confirmed by typing yes', function () {
        waText($this, 'yes');

        expect(balanceOf(account($this->user, 'Cash')))->toBe(-60000);
    });
});

describe('other corrections', function () {
    it('changes the category: "change the grocery transaction to food"', function () {
        FakeAIProvider::respond(($this->fix)(['target_kind' => 'by_text', 'target_text' => 'grocery', 'category' => 'food']));

        waText($this, 'change the grocery transaction to food');
        waTap($this, lastButtons()['confirm'], 'Apply');

        $replacement = LedgerTransaction::where('corrects_id', $this->original->id)->firstOrFail();
        expect($replacement->entries->firstWhere('category_id', '!=', null)->category_id)->toBe(category($this->user, 'Food')->id)
            ->and(sentTexts()[1])->toContain('under Food');
    });

    it('changes the date: "that payment was yesterday"', function () {
        FakeAIProvider::respond(($this->fix)(['date' => aiDate('relative_days', ['offset_days' => -1])]));

        waText($this, 'that payment was yesterday');
        waTap($this, lastButtons()['confirm'], 'Apply');

        expect(LedgerTransaction::where('corrects_id', $this->original->id)->first()->occurred_on->format('Y-m-d'))->toBe(now($this->user->timezone)->subDay()->format('Y-m-d'));
    });

    it('changes the account: "I used HDFC credit card instead"', function () {
        $card = app(AccountService::class)->create($this->user, 'HDFC Credit Card', AccountSubtype::CreditCard);
        UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'account', 'entity_id' => $card->id, 'alias' => 'hdfc credit card']);
        FakeAIProvider::respond(($this->fix)(['account' => 'HDFC credit card', 'payment_method' => 'credit_card']));

        waText($this, 'I used HDFC credit card instead');
        waTap($this, lastButtons()['confirm'], 'Apply');

        expect(balanceOf(account($this->user, 'Cash')))->toBe(0)->and(balanceOf($card))->toBe(50000)
            ->and(LedgerTransaction::where('corrects_id', $this->original->id)->first()->payment_method)->toBe('credit_card');
    });

    it('keeps the merchant and other details of the original', function () {
        $uber = Merchant::where('user_id', $this->user->id)->where('name', 'Uber')->first();
        $tx = ledger()->post(command($this->user, TransactionType::Expense, '350', ['categoryId' => category($this->user, 'Cab')->id, 'merchantId' => $uber->id, 'description' => 'Office trip']))->transaction;
        FakeAIProvider::respond(($this->fix)(['amount' => '400']));

        waText($this, 'actually it was 400');
        waTap($this, lastButtons()['confirm'], 'Apply');

        $replacement = LedgerTransaction::where('corrects_id', $tx->id)->first();
        expect($replacement->merchant_id)->toBe($uber->id)->and($replacement->description)->toBe('Office trip');
    });

    it('corrects an income and a transfer too', function () {
        $svc = app(AccountService::class);
        $bank = $svc->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
        $income = ledger()->post(command($this->user, TransactionType::Income, '45000', ['accountId' => $bank->id, 'categoryId' => category($this->user, 'Salary')->id]))->transaction;
        FakeAIProvider::respond(($this->fix)(['amount' => '46000']));

        waText($this, 'actually the salary was 46000');
        waTap($this, lastButtons()['confirm'], 'Apply');

        expect(balanceOf($bank))->toBe(4_600_000)->and($income->fresh()->status->value)->toBe('reversed');
    });
});

describe('when the correction cannot be made', function () {
    it('refuses an amount that is not in the message', function () {
        FakeAIProvider::respond(($this->fix)(['amount' => '700']));

        waText($this, 'actually that was 600');

        expect(sentTexts()[0])->toContain("can't find that amount")->and(LedgerTransaction::count())->toBe(1)->and(lastButtons())->toBe([]);
    });

    it('asks what to change when nothing differs', function () {
        FakeAIProvider::respond(($this->fix)(['amount' => '500']));

        waText($this, 'actually it was 500');

        expect(sentTexts()[0])->toContain('What should I change');
    });

    it('asks when the new category is unknown or the new account is not exact', function (array $change, string $expected) {
        FakeAIProvider::respond(($this->fix)($change));

        waText($this, 'fix it');

        expect(sentTexts()[0])->toContain($expected)->and(LedgerTransaction::count())->toBe(1);
    })->with([
        'unknown category' => [['category' => 'spaceship fuel'], "don't have a category"],
        'unknown account' => [['account' => 'Axis Bank'], "don't have an account I can match"],
    ]);

    it('says so when there is no transaction to change', function () {
        LedgerTransaction::query()->update(['status' => 'reversed']);   // (test shortcut: nothing correctable left)
        FakeAIProvider::respond(($this->fix)(['amount' => '600']));

        waText($this, 'actually that was 600');

        expect(sentTexts()[0])->toContain("couldn't find the transaction");
    });

    it('will not correct an opening balance', function () {
        $card = app(AccountService::class)->create($this->user, 'Bank', AccountSubtype::Bank);
        ledger()->post(command($this->user, TransactionType::OpeningBalance, '1000', ['accountId' => $card->id]));
        FakeAIProvider::respond(($this->fix)(['amount' => '2000']));

        waText($this, 'actually it was 2000');

        expect(sentTexts()[0])->toContain("can't change that kind of entry")->and(balanceOf($card))->toBe(100_000);
    });

    it('is refused cleanly if the original was undone in the meantime', function () {
        FakeAIProvider::respond(($this->fix)(['amount' => '600']));
        waText($this, 'actually that was 600');
        $apply = lastButtons()['confirm'];

        ledger()->reverse($this->user->id, $this->original->id, 'undone elsewhere');
        waTap($this, $apply);

        expect(sentTexts()[1])->toContain("couldn't do that")->and(balanceOf(account($this->user, 'Cash')))->toBe(0)->and(LedgerTransaction::count())->toBe(2);
    });
});

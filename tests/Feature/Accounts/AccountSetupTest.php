<?php

use App\Domain\Ledger\AccountService;
use App\Enums\AccountSubtype;
use App\Enums\EntityType;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\UserAlias;
use App\Services\Accounts\AccountSetupService;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use App\Support\Text;

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
    $this->makeAccount = function (string $name, AccountSubtype $type = AccountSubtype::Bank, array $aliases = []) {
        $account = app(AccountService::class)->create($this->user, $name, $type);
        foreach (array_merge([$name], $aliases) as $a) {
            UserAlias::create(['user_id' => $this->user->id, 'entity_type' => EntityType::Account->value, 'entity_id' => $account->id, 'alias' => Text::normalize($a), 'source' => 'user']);
        }

        return $account;
    };
    $this->balanceOf = fn (LedgerAccount $a) => app(AccountService::class)->balance($a)->minor;
    $acct = fn (array $o = []) => aiItem($o + ['intent' => 'create_account', 'event_type' => null]);
    $this->acct = $acct;
});

describe('opening balance on an existing account', function () {
    it('asks for a tap, then sets it through the ledger', function () {
        $hdfc = ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);

        ($this->say)('my opening balance on hdfc is 52340', ($this->acct)(['account' => 'hdfc', 'amount' => '52340']));

        expect(sentTexts()[0])->toContain('Set the opening balance of HDFC Bank to ₹52,340')
            ->and(LedgerTransaction::where('idempotency_key', 'like', 'opening:'.$hdfc->id.'%')->count())->toBe(0); // nothing before the tap

        waTap($this, lastButtons()['confirm']);

        expect(($this->balanceOf)($hdfc))->toBe(5234000)
            ->and(collect(sentTexts())->last())->toContain('Opening balance set on *HDFC Bank*');
        $this->artisan('moneytalks:ledger:verify')->assertSuccessful();
    });

    it('sets nothing when the user cancels', function () {
        $hdfc = ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);
        ($this->say)('opening balance on hdfc is 5000', ($this->acct)(['account' => 'hdfc', 'amount' => '5000']));

        waTap($this, lastButtons()['cancel'], 'Cancel');

        expect(($this->balanceOf)($hdfc))->toBe(0)->and(LedgerTransaction::where('idempotency_key', 'like', 'opening:'.$hdfc->id.'%')->exists())->toBeFalse();
    });

    it('refuses a second opening balance, and the same tap twice posts once', function () {
        $hdfc = ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);
        ($this->say)('opening balance on hdfc is 5000', ($this->acct)(['account' => 'hdfc', 'amount' => '5000']));
        $confirm = lastButtons()['confirm'];
        waTap($this, $confirm);
        waTap($this, $confirm);

        expect(($this->balanceOf)($hdfc))->toBe(500000);

        ($this->say)('opening balance on hdfc is 9000', ($this->acct)(['account' => 'hdfc', 'amount' => '9000']));
        expect(collect(sentTexts())->last())->toContain('already has an opening balance')->and(($this->balanceOf)($hdfc))->toBe(500000);
    });

    it('can be undone, and then set again', function () {
        $hdfc = ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);
        ($this->say)('opening balance on hdfc is 5000', ($this->acct)(['account' => 'hdfc', 'amount' => '5000']));
        waTap($this, lastButtons()['confirm']);

        waText($this, 'undo'); // answered without any AI call
        expect(($this->balanceOf)($hdfc))->toBe(0);

        ($this->say)('opening balance on hdfc is 7000', ($this->acct)(['account' => 'hdfc', 'amount' => '7000']));
        waTap($this, lastButtons()['confirm']);
        expect(($this->balanceOf)($hdfc))->toBe(700000);
        $this->artisan('moneytalks:ledger:verify')->assertSuccessful();
    });

    it('is also reached when the model files it as an opening_balance event', function () {
        ($this->makeAccount)('SBI Bank', AccountSubtype::Bank, ['sbi']);

        ($this->say)('opening balance of sbi 5000', aiItem(['event_type' => 'opening_balance', 'account' => 'sbi', 'amount' => '5000']));

        expect(sentTexts()[0])->toContain('Set the opening balance of SBI Bank to ₹5,000');
    });
});

describe('adding a new account', function () {
    it('creates it with an opening balance after the tap, and its name works as an alias', function () {
        ($this->say)('add Axis bank account with 12000', ($this->acct)(['account' => 'Axis bank', 'amount' => '12000']));
        expect(sentTexts()[0])->toContain('Add account Axis bank (bank) with an opening balance of ₹12,000')
            ->and(LedgerAccount::where('user_id', $this->user->id)->where('name', 'Axis bank')->exists())->toBeFalse();

        waTap($this, lastButtons()['confirm']);

        $axis = LedgerAccount::where('user_id', $this->user->id)->where('name', 'Axis bank')->firstOrFail();
        expect(($this->balanceOf)($axis))->toBe(1200000)->and($axis->subtype)->toBe(AccountSubtype::Bank);

        // "axis bank" now resolves to it, so a later expense can name it
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '300', 'category' => 'fuel', 'account' => 'axis bank'])));
        waText($this, 'spent 300 on fuel from axis bank');
        expect(($this->balanceOf)($axis))->toBe(1200000 - 30000);
        $this->artisan('moneytalks:ledger:verify')->assertSuccessful();
    });

    it('can add an account with no balance', function () {
        ($this->say)('add a Paytm wallet', ($this->acct)(['account' => 'Paytm Wallet', 'amount' => null]));
        waTap($this, lastButtons()['confirm']);

        expect(LedgerAccount::where('user_id', $this->user->id)->where('name', 'Paytm Wallet')->firstOrFail()->subtype)->toBe(AccountSubtype::Wallet)
            ->and(LedgerTransaction::where('idempotency_key', 'like', 'opening:%')->count())->toBe(0);
    });

    it('guesses the kind from the name', function (string $name, AccountSubtype $kind) {
        $plan = app(AccountSetupService::class)->plan($this->user, ($this->acct)(['account' => $name, 'amount' => '100']), 'x 100')['plan'] ?? null;

        expect($plan['subtype'] ?? null)->toBe($kind->value);
    })->with([
        'bank' => ['Kotak Bank', AccountSubtype::Bank],
        'credit card' => ['ICICI Credit Card', AccountSubtype::CreditCard],
        'wallet' => ['PhonePe', AccountSubtype::Wallet],
        'cash' => ['Petty Cash', AccountSubtype::Cash],
    ]);
});

describe('what it refuses', function () {
    it('will not guess an amount that is not in the message', function () {
        ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);

        ($this->say)('my opening balance on hdfc is 52340', ($this->acct)(['account' => 'hdfc', 'amount' => '5234']));

        expect(collect(sentTexts())->last())->toContain("can't find that amount")->and(lastButtons())->toBe([]);
    });

    it('asks which account when none is named, and when the name is only close to an existing one', function () {
        ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);

        ($this->say)('opening balance 5000', ($this->acct)(['amount' => '5000']));
        expect(collect(sentTexts())->last())->toContain('Which account?');

        ($this->say)('opening balance on hdfcc is 1000', ($this->acct)(['account' => 'hdfcc', 'amount' => '1000']));
        expect(collect(sentTexts())->last())->toContain('similar account')->and(LedgerAccount::where('user_id', $this->user->id)->where('name', 'hdfcc')->exists())->toBeFalse();
    });

    it('asks for the amount when setting a balance on an existing account without one', function () {
        ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);

        ($this->say)('set up hdfc', ($this->acct)(['account' => 'hdfc', 'amount' => null]));

        expect(collect(sentTexts())->last())->toContain('What is the opening balance of HDFC Bank?');
    });

    it('never applies anything from a voice note without a tap (and it is a tap anyway)', function () {
        ($this->makeAccount)('HDFC Bank', AccountSubtype::Bank, ['hdfc']);
        ($this->say)('opening balance on hdfc is 5000', ($this->acct)(['account' => 'hdfc', 'amount' => '5000']));

        expect(lastButtons())->toHaveKeys(['confirm', 'cancel']);
    });
});

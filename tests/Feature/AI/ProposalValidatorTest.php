<?php

use App\Domain\Ledger\AccountService;
use App\Enums\AccountSubtype;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\UserAlias;
use App\Services\Interpretation\Decision;
use App\Services\Interpretation\ProposalValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = ledgerUser();
    $svc = app(AccountService::class);
    $this->bank = $svc->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
    $this->sbi = $svc->create($this->user, 'SBI Bank', AccountSubtype::Bank);
    $this->card = $svc->create($this->user, 'HDFC Credit Card', AccountSubtype::CreditCard);
    foreach ([[$this->bank, 'hdfc'], [$this->sbi, 'sbi'], [$this->card, 'cc']] as [$a, $alias]) {
        UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'account', 'entity_id' => $a->id, 'alias' => $alias]);
    }
    $this->v = app(ProposalValidator::class);
    // Sunday 4 Oct 2026, 10:00 IST
    $this->now = CarbonImmutable::create(2026, 10, 4, 10, 0, 0, 'Asia/Kolkata')->utc();
    $this->decide = fn (array $item, string $text = 'spent 250 on vegetables') => $this->v->decide($this->user, aiEnvelope($item), $text, $this->now)[0];
});

describe('a clean expense', function () {
    it('becomes a validated posting with ids resolved from the user\'s own data', function () {
        $d = ($this->decide)(aiItem());

        expect($d->kind)->toBe(Decision::RECORD);
        $p = $d->posting;
        expect($p->type)->toBe(TransactionType::Expense)
            ->and($p->money->minor)->toBe(25000)
            ->and($p->categoryId)->toBe(category($this->user, 'Vegetables')->id)
            ->and($p->categoryName)->toBe('Vegetables')
            ->and($p->accountId)->toBe(account($this->user, 'Cash')->id)    // the default account
            ->and($p->occurredOn)->toBe('2026-10-04')->and($p->isToday)->toBeTrue()
            ->and($d->score)->toBeGreaterThan(0.9);
    });

    it('understands aliases and Devanagari names', function (string $category, string $expected) {
        $d = ($this->decide)(aiItem(['category' => $category]), 'spent 250 on '.$category);

        expect($d->posting->categoryName)->toBe($expected);
    })->with([['sabji', 'Vegetables'], ['सब्जी', 'Vegetables'], ['petrol', 'Fuel'], ['chai', 'Tea/Coffee']]);

    it('forgives typos in categories (but is a little less sure)', function () {
        $exact = ($this->decide)(aiItem());
        $typo = ($this->decide)(aiItem(['category' => 'vegtables']), 'spent 250 on vegtables');

        expect($typo->kind)->toBe(Decision::RECORD)->and($typo->posting->categoryName)->toBe('Vegetables')
            ->and($typo->score)->toBeLessThan($exact->score);
    });

    it('keeps the payment method and a clean description', function () {
        $d = ($this->decide)(aiItem(['payment_method' => 'upi', 'description' => "  Veg \n  from\tmarket  "]));

        expect($d->posting->paymentMethod)->toBe(PaymentMethod::Upi)->and($d->posting->description)->toBe('Veg from market');
    });

    it('truncates absurdly long descriptions', function () {
        $d = ($this->decide)(aiItem(['description' => str_repeat('x', 500)]));

        expect(mb_strlen($d->posting->description))->toBe(120);
    });

    it('treats a missing payment method or a bogus one as unknown, not an error', function () {
        expect(($this->decide)(aiItem(['payment_method' => 'bitcoin']))->posting->paymentMethod)->toBeNull();
    });
});

describe('amounts', function () {
    it('asks when the amount is missing', function () {
        $d = ($this->decide)(aiItem(['amount' => null, 'missing_fields' => ['amount']]), 'spent on vegetables');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('amount_missing')->and($d->message)->toBe('How much was it?');
    });

    it('asks again for unreadable, zero or negative amounts instead of recording', function (string $amount, string $reason) {
        $d = ($this->decide)(aiItem(['amount' => $amount]), "spent {$amount} on vegetables");

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe($reason);
    })->with([['12.345', 'amount_invalid'], ['abc', 'amount_invalid'], ['0', 'amount_not_positive'], ['-50', 'amount_not_positive']]);

    it('refuses an amount the user never typed (model hallucination or arithmetic)', function () {
        $d = ($this->decide)(aiItem(['amount' => '750']), '3 items 250 each');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('amount_not_in_text')->and($d->message)->toContain('₹750');
    });

    it('accepts unit shorthands as the same amount', function (string $text, string $amount) {
        expect(($this->decide)(aiItem(['amount' => $amount]), $text)->kind)->toBe(Decision::RECORD);
    })->with([['rahul se 2k liya', '2000'], ['spent 0.5 lakh on vegetables', '50000'], ['spent ४५० on vegetables', '450'], ['spent ₹1,200.50 on vegetables', '1200.50']]);

    it('still records when the text has no digits to check against (voice-style input), with a lower score', function () {
        $checked = ($this->decide)(aiItem());
        $spoken = ($this->decide)(aiItem(['amount' => '350']), 'aaj teen sau pachaas ka sabji');

        expect($spoken->kind)->toBe(Decision::RECORD)->and($spoken->score)->toBeLessThan($checked->score);
    });

    it('rejects absurdly large amounts', function () {
        $d = ($this->decide)(aiItem(['amount' => '99999999999']), 'spent 99999999999 on vegetables');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('amount_too_large');
    });

    it('only supports the user\'s own currency for now', function () {
        $d = ($this->decide)(aiItem(['currency' => 'USD']));

        expect($d->kind)->toBe(Decision::UNSUPPORTED)->and($d->reason)->toBe('currency_not_supported');
    });

    it('treats an explicit INR currency like no currency', function () {
        expect(($this->decide)(aiItem(['currency' => 'inr']))->kind)->toBe(Decision::RECORD);
    });
});

describe('dates', function () {
    it('resolves yesterday, last Friday and the 2nd in the user\'s timezone', function (array $date, string $expected) {
        $d = ($this->decide)(aiItem(['date' => $date]));

        expect($d->kind)->toBe(Decision::RECORD)->and($d->posting->occurredOn)->toBe($expected)->and($d->posting->isToday)->toBeFalse();
    })->with([
        'yesterday' => [fn () => aiDate('relative_days', ['offset_days' => -1]), '2026-10-03'],
        'last friday' => [fn () => aiDate('weekday', ['weekday' => 'fri', 'which' => 'last']), '2026-10-02'],
        'on the 2nd' => [fn () => aiDate('day_of_month', ['day' => 2]), '2026-10-02'],
        'explicit' => [fn () => aiDate('iso', ['iso' => '2026-09-12']), '2026-09-12'],
    ]);

    it('uses the user\'s local date near midnight (20:30 UTC is already tomorrow in India)', function () {
        $late = CarbonImmutable::create(2026, 10, 3, 20, 30, 0, 'UTC');   // 02:00 on 4 Oct IST
        $d = $this->v->decide($this->user, aiEnvelope(aiItem()), 'spent 250 on vegetables', $late)[0];

        expect($d->posting->occurredOn)->toBe('2026-10-04');
    });

    it('asks instead of guessing impossible or out-of-range dates', function (array $date) {
        $d = ($this->decide)(aiItem(['date' => $date]));

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('date_invalid');
    })->with([
        'feb 30' => [fn () => aiDate('iso', ['iso' => '2026-02-30'])],
        'next month' => [fn () => aiDate('iso', ['iso' => '2026-11-15'])],
        'a decade ago' => [fn () => aiDate('iso', ['iso' => '2016-01-01'])],
        'unresolvable' => [fn () => aiDate('weekday')],
    ]);

    it('allows tomorrow (timezone slack) but not further ahead', function () {
        expect(($this->decide)(aiItem(['date' => aiDate('relative_days', ['offset_days' => 1])]))->kind)->toBe(Decision::RECORD)
            ->and(($this->decide)(aiItem(['date' => aiDate('relative_days', ['offset_days' => 2])]))->kind)->toBe(Decision::CLARIFY);
    });
});

describe('categories', function () {
    it('asks "for what?" instead of guessing a category', function () {
        $d = ($this->decide)(aiItem(['category' => null, 'missing_fields' => ['category'], 'amount' => '500']), 'paid 500');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('category_missing')->and($d->message)->toBe('₹500 paid for what?');
    });

    it('asks about income purpose with examples', function () {
        $d = ($this->decide)(aiItem(['event_type' => 'income', 'category' => null, 'amount' => '45000']), 'got 45000');

        expect($d->message)->toContain('₹45,000 received for what?');
    });

    it('does not accept a hallucinated category; it lists real ones', function () {
        $d = ($this->decide)(aiItem(['category' => 'Spaceship fuel']));

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('category_unknown')
            ->and($d->message)->toContain('Spaceship fuel')->and($d->message)->toContain('Food');
    });

    it('does not let an income category be used for an expense, or the reverse', function () {
        expect(($this->decide)(aiItem(['category' => 'salary'], 'spent 250 on salary'))->reason)->toBe('category_unknown')
            ->and(($this->decide)(aiItem(['event_type' => 'income', 'category' => 'petrol']))->reason)->toBe('category_unknown');
    });

    it('asks which one when a name matches several categories', function () {
        $parent = Category::where('user_id', $this->user->id)->where('name', 'Shopping')->first();
        Category::create(['user_id' => $this->user->id, 'kind' => 'expense', 'name' => 'Fuel', 'path' => 'Shopping > Fuel', 'parent_id' => $parent->id]);
        UserAlias::where('user_id', $this->user->id)->where('alias', 'fuel')->delete();

        $d = ($this->decide)(aiItem(['category' => 'fuel']));

        expect($d->reason)->toBe('category_ambiguous')->and($d->message)->toContain('Fuel');
    });

    it('uses the merchant\'s default category when no category was stated ("Uber 350")', function () {
        $d = ($this->decide)(aiItem(['category' => null, 'merchant' => 'Uber', 'amount' => '350']), 'Uber 350');

        expect($d->kind)->toBe(Decision::RECORD)->and($d->posting->categoryName)->toBe('Cab')->and($d->posting->merchantId)->not->toBeNull()
            ->and($d->posting->description)->toBe('Uber');
    });

    it('lets a stated category beat the merchant default', function () {
        $d = ($this->decide)(aiItem(['category' => 'groceries', 'merchant' => 'Amazon', 'amount' => '350']), 'Amazon 350 groceries');

        expect($d->posting->categoryName)->toBe('Groceries');
    });

    it('ignores an unknown merchant instead of failing', function () {
        $d = ($this->decide)(aiItem(['merchant' => 'Bhaiya ki dukaan']));

        expect($d->kind)->toBe(Decision::RECORD)->and($d->posting->merchantId)->toBeNull();
    });
});

describe('accounts', function () {
    it('uses an exactly-named account (alias or full name)', function (string $named) {
        $d = ($this->decide)(aiItem(['account' => $named]));

        expect($d->posting->accountName)->toBe('HDFC Bank');
    })->with(['hdfc', 'HDFC Bank', 'HDFC']);

    it('picks the only credit card when the payment method is credit_card', function () {
        $d = ($this->decide)(aiItem(['payment_method' => 'credit_card', 'category' => 'shoes', 'amount' => '3000']), 'bought shoes 3000 on credit card');

        // "shoes" is an alias of Clothes in the starter catalog
        expect($d->kind)->toBe(Decision::RECORD)->and($d->posting->accountName)->toBe('HDFC Credit Card');
    });

    it('never picks an account from a fuzzy match: it asks', function () {
        $d = ($this->decide)(aiItem(['account' => 'hdfcc bank']));

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('account_unsure')->and($d->message)->toContain('HDFC Bank');
    });

    it('lists real accounts when asked about an unknown one', function () {
        $d = ($this->decide)(aiItem(['account' => 'Axis Bank']));

        expect($d->reason)->toBe('account_unknown')->and($d->message)->toContain('Cash')->and($d->message)->toContain('HDFC Bank')
            ->and($d->message)->not->toContain('Expenses')->and($d->message)->not->toContain('Opening Balances');   // system accounts stay hidden
    });

    it('asks when there is no default account and none was named', function () {
        $this->user->settings->update(['default_account_id' => null]);

        $d = ($this->decide)(aiItem());

        expect($d->reason)->toBe('account_missing');
    });

    it('will not receive income into a credit card, or pay an expense from a goal fund', function () {
        expect(($this->decide)(aiItem(['event_type' => 'income', 'category' => 'salary', 'account' => 'cc']))->reason)->toBe('account_not_suitable')
            ->and(($this->decide)(aiItem(['event_type' => 'income', 'category' => 'salary']))->kind)->toBe(Decision::RECORD);
    });

    it('does not see another user\'s accounts', function () {
        $other = ledgerUser('919111111111');
        app(AccountService::class)->create($other, 'Secret Bank', AccountSubtype::Bank);

        $d = ($this->decide)(aiItem(['account' => 'Secret Bank']));

        // the reply echoes what the user typed, but its list of accounts only contains their own
        expect($d->reason)->toBe('account_unknown')->and(Str::after($d->message, 'Your accounts:'))->not->toContain('Secret');
    });
});

describe('transfers (records only; never a bank transfer)', function () {
    it('asks for a Confirm tap before recording a transfer between two exactly-named own accounts', function () {
        $d = ($this->decide)(aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'account' => 'sbi', 'to_account' => 'hdfc']), 'transfer 1000 from SBI to HDFC');

        expect($d->kind)->toBe(Decision::CONFIRM)->and($d->reason)->toBe('transfer')->and($d->message)->toContain('a transfer of ₹1,000 from SBI Bank to HDFC Bank')
            ->and($d->posting->type)->toBe(TransactionType::Transfer)
            ->and($d->posting->accountName)->toBe('SBI Bank')->and($d->posting->toAccountName)->toBe('HDFC Bank')
            ->and($d->posting->categoryId)->toBeNull();
    });

    it('never guesses the accounts of a transfer from defaults', function () {
        $d = ($this->decide)(aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'to_account' => 'hdfc']), 'transfer 1000 to hdfc');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('transfer_accounts_missing');
    });

    it('asks when both accounts are the same', function () {
        $d = ($this->decide)(aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'account' => 'hdfc', 'to_account' => 'HDFC Bank']), 'transfer 1000 hdfc to hdfc');

        expect($d->reason)->toBe('transfer_same_account');
    });

    it('does not treat "transfer to Rahul" as an own-account transfer', function () {
        Counterparty::create(['user_id' => $this->user->id, 'name' => 'Rahul']);

        $d = ($this->decide)(aiItem(['event_type' => 'transfer', 'amount' => '50000', 'category' => null, 'account' => 'hdfc', 'to_account' => 'Rahul']), 'transfer 50000 to Rahul');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('account_unknown');
    });
});

describe('what is recognised but not recorded', function () {
    it('turns lending into a confirmation, never into an expense', function () {
        $d = ($this->decide)(aiItem(['event_type' => 'lend', 'counterparty' => 'Rahul', 'category' => null, 'amount' => '2000']), 'gave rahul 2000');

        expect($d->kind)->toBe(Decision::CONFIRM)->and($d->reason)->toBe('person_loan')->and($d->posting->type)->toBe(TransactionType::Lend)
            ->and($d->posting->newPerson)->toBeTrue()->and($d->posting->counterpartyName)->toBe('Rahul');
    });

    it('declines the event types that do not exist yet, whatever else is in the message', function (string $type) {
        $d = ($this->decide)(aiItem(['event_type' => $type, 'counterparty' => 'Rahul']), 'gave rahul 250');

        expect($d->kind)->toBe(Decision::UNSUPPORTED)->and($d->posting)->toBeNull();
    })->with(['refund']); // opening_balance is handled by AccountSetupService (tests/Feature/Accounts)

    it('hands undo and correction requests on to the transaction logic, carrying what the model understood', function (string $intent, string $kind) {
        $d = ($this->decide)(aiItem(['intent' => $intent, 'event_type' => null, 'amount' => '600', 'target_kind' => 'last', 'target_amount' => '500']), 'actually that was 600, not 500');

        expect($d->kind)->toBe($kind)->and($d->item['target_kind'])->toBe('last')->and($d->item['amount'])->toBe('600')->and($d->posting)->toBeNull();
    })->with([['undo_transaction', Decision::UNDO], ['correct_transaction', Decision::CORRECT]]);

    it('declines intents that do not exist yet, saying so honestly', function (string $intent) {
        $d = ($this->decide)(aiItem(['intent' => $intent, 'event_type' => null, 'amount' => null]), 'how much did I spend this month?');

        expect($d->kind)->toBe(Decision::UNSUPPORTED)->and($d->message)->toContain('can\'t do yet')->and($d->message)->toContain('Nothing was recorded');
    })->with(['update_setting']);

    it('hands goal requests on to the goal code', function () {
        $d = ($this->decide)(aiItem(['intent' => 'create_goal', 'event_type' => null, 'amount' => '100000', 'description' => 'bike']), 'save 100000 for a bike');

        expect($d->kind)->toBe(Decision::GOAL)->and($d->posting)->toBeNull();
    });

    it('validates a recurring payment into a rule description, without touching the ledger', function () {
        $d = ($this->decide)(aiItem(['intent' => 'create_recurring', 'event_type' => 'expense', 'amount' => '649', 'category' => 'entertainment', 'merchant' => 'Netflix', 'recurrence' => 'monthly']), 'Netflix 649 every month');

        expect($d->kind)->toBe(Decision::RECURRING)->and($d->posting)->toBeNull()->and($d->item['rr']['minor'])->toBe(64900)->and($d->item['rr']['frequency'])->toBe('monthly')
            ->and($d->item['rr']['first_due'])->toBe('2026-11-04');
    });

    it('hands budget requests on to the budget code', function () {
        $d = ($this->decide)(aiItem(['intent' => 'create_budget', 'event_type' => null, 'amount' => '5000', 'action' => 'set']), 'budget 5000 for food');

        expect($d->kind)->toBe(Decision::BUDGET)->and($d->posting)->toBeNull()->and($d->item['amount'])->toBe('5000');
    });

    it('hands questions, reports and exports on to the reporting code, never to the ledger', function (string $intent, string $kind) {
        $d = ($this->decide)(aiItem(['intent' => $intent, 'event_type' => null, 'amount' => null, 'query_metric' => 'total_spend']), 'how much did I spend this month?');

        expect($d->kind)->toBe($kind)->and($d->posting)->toBeNull()->and($d->item['query_metric'])->toBe('total_spend');
    })->with([['query', Decision::QUERY], ['report', Decision::QUERY], ['export', Decision::EXPORT]]);

    it('answers chit-chat and unknown intents without recording', function () {
        $d = ($this->decide)(aiItem(['intent' => 'unknown', 'event_type' => null, 'amount' => null]), 'blah');

        expect($d->kind)->toBe(Decision::UNSUPPORTED)->and($d->message)->toContain('spent 250 on vegetables');
    });

    it('routes help requests', function () {
        expect(($this->decide)(aiItem(['intent' => 'help', 'event_type' => null]))->kind)->toBe(Decision::HELP);
    });

    it('asks when it cannot tell what kind of entry it is', function () {
        $d = ($this->decide)(aiItem(['event_type' => null, 'missing_fields' => ['event_type']]));

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('event_type_missing');
    });
});

describe('risk', function () {
    it('asks the user to Confirm a read it is only fairly sure about', function () {
        $d = ($this->decide)(aiItem(['confidence' => 0.5]));

        expect($d->kind)->toBe(Decision::CONFIRM)->and($d->reason)->toBe('low_confidence')->and($d->posting->money->minor)->toBe(25000)
            ->and($d->message)->toContain('I think you meant this.')->and($d->message)->toContain('₹250 expense under Vegetables, from Cash, today?');
    });

    it('asks the user to rephrase when it is too unsure even for that', function () {
        $d = ($this->decide)(aiItem(['confidence' => 0.2]));

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->reason)->toBe('low_confidence')->and($d->posting)->toBeNull()
            ->and($d->message)->toContain('Nothing was recorded');
    });

    it('puts the boundaries where the configuration says', function () {
        config(['ai.risk.auto_commit_min_score' => 0.80, 'ai.risk.confirm_min_score' => 0.55]);

        $kind = fn (float $confidence) => ($this->decide)(aiItem(['confidence' => $confidence]))->kind;

        // score = 0.60*confidence + 0.40
        expect($kind(1.00))->toBe(Decision::RECORD)->and($kind(0.67))->toBe(Decision::RECORD)    // 0.802
            ->and($kind(0.65))->toBe(Decision::CONFIRM)                                           // 0.79
            ->and($kind(0.26))->toBe(Decision::CONFIRM)                                           // 0.556
            ->and($kind(0.24))->toBe(Decision::CLARIFY);                                          // 0.544

        config(['ai.risk.auto_commit_min_score' => 0.99]);
        expect($kind(0.97))->toBe(Decision::CONFIRM);
    });

    it('clamps nonsense confidence values', function () {
        expect(($this->decide)(aiItem(['confidence' => 5]))->kind)->toBe(Decision::RECORD)
            ->and(($this->decide)(aiItem(['confidence' => -3]))->kind)->toBe(Decision::CLARIFY);
    });

    it('asks for a Confirm tap on large expenses and transfers, but not on large income', function () {
        config(['ai.risk.confirm_above_minor' => 5_000_000]); // INR 50,000

        $bigExpense = ($this->decide)(aiItem(['amount' => '60000']), 'spent 60000 on vegetables');
        $bigIncome = ($this->decide)(aiItem(['event_type' => 'income', 'category' => 'salary', 'amount' => '150000']), 'salary 150000');
        $bigTransfer = ($this->decide)(aiItem(['event_type' => 'transfer', 'amount' => '60000', 'category' => null, 'account' => 'sbi', 'to_account' => 'hdfc']), 'transfer 60000 sbi to hdfc');

        expect($bigExpense->kind)->toBe(Decision::CONFIRM)->and($bigExpense->reason)->toBe('large_amount')->and($bigExpense->message)->toContain("That's a large amount.")
            ->and($bigIncome->kind)->toBe(Decision::RECORD)
            ->and($bigTransfer->kind)->toBe(Decision::CONFIRM)->and($bigTransfer->reason)->toBe('transfer');
    });
});

describe('questions the user can answer in their next message', function () {
    it('remember what is missing and keep the model\'s item so the answer can complete it', function (array $override, string $awaiting) {
        $d = ($this->decide)(aiItem($override), 'x');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->awaiting)->toBe($awaiting)->and($d->item['intent'])->toBe('record_event');
    })->with([
        'category missing' => [['category' => null, 'amount' => '500'], 'category'],
        'unknown category' => [['category' => 'spaceship fuel', 'amount' => '250'], 'category'],
        'amount missing' => [['amount' => null, 'missing_fields' => ['amount']], 'amount'],
        'unknown account' => [['account' => 'Axis Bank'], 'account'],
        'unsure account' => [['account' => 'hdfcc bank'], 'account'],
    ]);

    it('knows which side of a transfer is missing', function () {
        $d = ($this->decide)(aiItem(['event_type' => 'transfer', 'amount' => '1000', 'category' => null, 'account' => 'sbi', 'to_account' => 'axis']), 'transfer 1000 from sbi to axis');

        expect($d->awaiting)->toBe('to_account');
    });

    it('does not offer a follow-up for things only a new message can fix', function (array $override) {
        $d = ($this->decide)(aiItem($override), 'x');

        expect($d->kind)->toBe(Decision::CLARIFY)->and($d->awaiting)->toBeNull();
    })->with([
        'date' => [['date' => ['kind' => 'iso', 'offset_days' => null, 'weekday' => null, 'which' => null, 'day' => null, 'month' => null, 'year' => null, 'iso' => '2026-02-30']]],
        'unknown event type' => [['event_type' => null]],
    ]);
});

describe('several items in one message', function () {
    it('records the good ones and asks about the others', function () {
        $env = aiEnvelope(
            aiItem(['event_type' => 'income', 'category' => 'salary', 'amount' => '45000']),
            aiItem(['category' => null, 'amount' => '12000', 'missing_fields' => ['category']]),
        );

        $ds = $this->v->decide($this->user, $env, 'salary 45000 and paid 12000', $this->now);

        expect($ds[0]->kind)->toBe(Decision::RECORD)->and($ds[1]->kind)->toBe(Decision::CLARIFY)
            ->and($ds[0]->index)->toBe(0)->and($ds[1]->index)->toBe(1);
    });

    it('caps the number of items it will act on', function () {
        $items = array_fill(0, 9, aiItem());

        $ds = $this->v->decide($this->user, ['language' => 'en', 'items' => $items], 'spent 250 on vegetables', $this->now);

        expect($ds)->toHaveCount(5);
    });

    it('answers an empty proposal with a gentle "did not understand"', function () {
        $d = $this->v->decide($this->user, aiEnvelope(), 'x', $this->now)[0];

        expect($d->kind)->toBe(Decision::UNSUPPORTED)->and($d->reason)->toBe('no_items');
    });
});

describe('structural validation (the schema is a guardrail, not a trust boundary)', function () {
    it('accepts a well-formed envelope', function () {
        expect($this->v->structuralError(aiEnvelope(aiItem())))->toBeNull()
            ->and($this->v->structuralError(aiEnvelope()))->toBeNull();
    });

    it('rejects malformed output', function (array $envelope) {
        expect($this->v->structuralError($envelope))->not->toBeNull();
    })->with([
        'no items' => [['language' => 'en']],
        'items not a list' => [['items' => ['a' => aiItem()]]],
        'item not an object' => [['items' => ['text']]],
        'invented intent (e.g. injection)' => [fn () => aiEnvelope(aiItem(['intent' => 'delete_all_transactions']))],
        'invented event type' => [fn () => aiEnvelope(aiItem(['event_type' => 'wire_transfer']))],
        'float amount' => [fn () => aiEnvelope(aiItem(['amount' => 250.5]))],
        'array amount' => [fn () => aiEnvelope(aiItem(['amount' => ['250']]))],
        'bad date kind' => [fn () => aiEnvelope(aiItem(['date' => ['kind' => 'someday']]))],
        'bad missing field' => [fn () => aiEnvelope(aiItem(['missing_fields' => ['password']]))],
        'object category' => [fn () => aiEnvelope(aiItem(['category' => ['x']]))],
        'text confidence' => [fn () => aiEnvelope(aiItem(['confidence' => 'high']))],
    ]);
});

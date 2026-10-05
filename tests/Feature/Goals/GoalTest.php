<?php

use App\Enums\TransactionType;
use App\Models\Goal;
use App\Models\LedgerAccount;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

function gItem(array $o = []): array
{
    return aiItem($o + ['intent' => 'create_goal', 'event_type' => null, 'amount' => '100000', 'category' => null, 'description' => 'bike', 'action' => 'set']);
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
});

it('creates a goal with its own account and a target date', function () {
    ($this->say)('save 100000 for a bike by 30 June 2027', gItem(['date' => aiDate('iso', ['iso' => '2027-06-30'])]));

    $g = Goal::firstOrFail();
    expect($g->name)->toBe('Bike')->and($g->target_minor)->toBe(10000000)->and($g->target_date->format('Y-m-d'))->toBe('2027-06-30')
        ->and(LedgerAccount::where('name', 'Goal: Bike')->first()->subtype->value)->toBe('goal')
        ->and(sentTexts()[0])->toContain('Created goal *Bike*: ₹1,00,000 by 30 Jun 2027');
});

it('tracks progress through ordinary transfers and celebrates once when reached', function () {
    ($this->say)('save 10000 for a bike', gItem(['amount' => '10000']));

    ($this->say)('transfer 4000 to bike', aiItem(['event_type' => 'transfer', 'amount' => '4000', 'category' => null, 'account' => null, 'to_account' => 'bike']));
    waTap($this, lastButtons()['confirm']);
    expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('🎯 Bike: ₹4,000 of ₹10,000 (40%)');

    ($this->say)('transfer 6000 to bike goal', aiItem(['event_type' => 'transfer', 'amount' => '6000', 'category' => null, 'account' => null, 'to_account' => 'bike goal']));
    waTap($this, lastButtons()['confirm']);
    expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('🎉 Goal reached: *Bike*')->and(Goal::first()->status)->toBe('achieved');
});

it('computes progress and cash correctly after saving', function () {
    ($this->say)('save 12000 for a bike by 30 June 2027', gItem(['amount' => '12000', 'date' => aiDate('iso', ['iso' => '2027-06-30'])]));
    ($this->say)('transfer 2000 to bike', aiItem(['event_type' => 'transfer', 'amount' => '2000', 'category' => null, 'account' => null, 'to_account' => 'bike']));
    waTap($this, lastButtons()['confirm']);
    ($this->say)('how are my goals?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'goals']));

    $body = end(FakeWhatsAppProvider::$sent)->body;
    expect($body)->toContain('Bike: ₹2,000 of ₹12,000 (16%)')->and($body)->toContain('needs ₹1,111.12/month')
        ->and(balanceOf(account($this->user, 'Cash')))->toBe(50000000 - 200000);
});

it('updates, removes, and never guesses an amount that is not in the message', function () {
    ($this->say)('save 10000 for a bike', gItem(['amount' => '10000']));
    ($this->say)('make the bike goal 20000', gItem(['amount' => '20000']));
    expect(Goal::count())->toBe(1)->and(Goal::first()->target_minor)->toBe(2000000);

    ($this->say)('save for a laptop', gItem(['description' => 'laptop', 'amount' => '55555']));
    expect(sentTexts()[2])->toContain("can't find that amount")->and(Goal::count())->toBe(1);

    ($this->say)('cancel my bike goal', gItem(['amount' => null, 'action' => 'remove']));
    expect(Goal::first()->status)->toBe('cancelled')->and(end(FakeWhatsAppProvider::$sent)->body)->toContain('Stopped the *Bike* goal');
});

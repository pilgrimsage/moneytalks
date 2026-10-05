<?php

use App\Models\LedgerTransaction;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser();
    FakeAIProvider::responder(fn () => aiEnvelope(aiItem(['amount' => '500', 'category' => 'groceries'])));
});

it('asks before recording the same thing twice in quick succession', function () {
    waText($this, 'paid 500 groceries');
    waText($this, 'paid 500 groceries');

    expect(LedgerTransaction::count())->toBe(1)->and(lastButtonTitles())->toBe(['Yes, record it', 'No'])
        ->and(FakeWhatsAppProvider::$sent[1]->body)->toContain('This looks similar to a transaction you recorded')->and(FakeWhatsAppProvider::$sent[1]->body)->toContain('₹500 expense under Groceries')
        ->and(FakeWhatsAppProvider::$sent[1]->body)->toContain('Record it anyway?');
});

it('records the second one when the user says yes', function () {
    waText($this, 'paid 500 groceries');
    waText($this, 'paid 500 groceries');
    waTap($this, lastButtons()['confirm'], 'Yes, record it');

    expect(LedgerTransaction::count())->toBe(2)->and(balanceOf(account($this->user, 'Cash')))->toBe(-100_000);
});

it('drops the second one when the user says no', function () {
    waText($this, 'paid 500 groceries');
    waText($this, 'paid 500 groceries');
    waText($this, 'no');

    expect(LedgerTransaction::count())->toBe(1)->and(balanceOf(account($this->user, 'Cash')))->toBe(-50_000);
});

it('does not ask when enough time has passed', function () {
    waText($this, 'paid 500 groceries');
    $this->travel(3)->minutes();
    waText($this, 'paid 500 groceries');

    expect(LedgerTransaction::count())->toBe(2)->and(lastButtons())->toBe([]);
});

it('uses the user\'s own window setting', function () {
    $this->user->settings->update(['duplicate_window_seconds' => 600]);
    waText($this, 'paid 500 groceries');
    $this->travel(5)->minutes();
    waText($this, 'paid 500 groceries');

    expect(LedgerTransaction::count())->toBe(1)->and(lastButtonTitles())->toBe(['Yes, record it', 'No']);
});

it('does not ask when the amount, category, account or date differs', function (array $second) {
    waText($this, 'paid 500 groceries');
    FakeAIProvider::reset();
    FakeAIProvider::respond(aiEnvelope(aiItem($second)));
    waText($this, 'something else');

    expect(LedgerTransaction::count())->toBe(2)->and(lastButtons())->toBe([]);
})->with([
    'other amount' => [['amount' => '501', 'category' => 'groceries']],
    'other category' => [['amount' => '500', 'category' => 'petrol']],
    'other date' => [fn () => ['amount' => '500', 'category' => 'groceries', 'date' => aiDate('relative_days', ['offset_days' => -1])]],
    'income instead of expense' => [['event_type' => 'income', 'amount' => '500', 'category' => 'salary']],
]);

it('is never triggered by a redelivered webhook (that is idempotency, not duplication)', function () {
    $payload = waFactory()->text('919876543210', 'paid 500 groceries', 'wamid.SAME');
    postWebhook($this, $payload)->assertOk();
    $variant = $payload;
    $variant['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] = 'x';
    postWebhook($this, $variant)->assertOk();

    expect(LedgerTransaction::count())->toBe(1)->and(lastButtons())->toBe([])->and(FakeWhatsAppProvider::$sent)->toHaveCount(1);
});

it('ignores transactions that were already undone', function () {
    waText($this, 'paid 500 groceries');
    waText($this, 'undo');
    waText($this, 'paid 500 groceries');

    expect(LedgerTransaction::where('type', 'expense')->where('status', 'posted')->count())->toBe(1)->and(lastButtons())->toBe([]);
});

it('does not compare against other users\' transactions', function () {
    $other = ledgerUser('919111111111');
    config(['moneytalks.allowed_wa_ids' => ['919876543210', '919111111111']]);
    waText($this, 'paid 500 groceries', null, '919111111111');
    waText($this, 'paid 500 groceries');

    expect(LedgerTransaction::count())->toBe(2)->and(lastButtons())->toBe([]);
});

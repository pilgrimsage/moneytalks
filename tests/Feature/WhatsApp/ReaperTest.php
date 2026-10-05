<?php

use App\Jobs\ProcessWebhookEvent;
use App\Jobs\ReapWebhookEvents;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    FakeWhatsAppProvider::reset();
    $this->user = ledgerUser('919876543210');
    $this->event = fn (array $o = []) => WebhookEvent::create($o + [
        'provider' => 'fake', 'event_hash' => bin2hex(random_bytes(32)),
        'payload' => json_encode(waFactory()->text('919876543210', 'hi', 'wamid.'.bin2hex(random_bytes(4)))),
        'status' => 'received', 'received_at' => now()->subMinutes(10),
    ]);
});

it('re-drives events that were never processed (e.g. the after-response job was killed)', function () {
    Queue::fake();
    $stuck = ($this->event)();
    ($this->event)(['received_at' => now()]);                       // fresh: leave alone

    $result = (new ReapWebhookEvents)();

    expect($result['redriven'])->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, fn ($j) => $j->eventId === $stuck->id);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('hands back events whose worker died mid-processing', function () {
    Queue::fake();
    $dead = ($this->event)(['status' => 'processing', 'processing_started_at' => now()->subHour(), 'attempts' => 1]);

    $result = (new ReapWebhookEvents)();

    expect($result['reset'])->toBe(1)
        ->and($dead->fresh()->status)->toBe('failed')
        ->and($dead->fresh()->error)->toBe('processing_timeout');
});

it('does not touch events still being processed or already done', function () {
    Queue::fake();
    ($this->event)(['status' => 'processing', 'processing_started_at' => now()]);
    ($this->event)(['status' => 'processed']);
    ($this->event)(['status' => 'ignored']);

    expect((new ReapWebhookEvents)())->toMatchArray(['reset' => 0, 'redriven' => 0]);
    Queue::assertNothingPushed();
});

it('gives up on poison events after the maximum attempts and reports them', function () {
    Queue::fake();
    config(['whatsapp.webhook.max_attempts' => 3]);
    ($this->event)(['status' => 'failed', 'attempts' => 3]);

    $result = (new ReapWebhookEvents)();

    expect($result)->toMatchArray(['redriven' => 0, 'exhausted' => 1]);
    Queue::assertNothingPushed();
});

it('backs off between attempts', function () {
    Queue::fake();
    config(['whatsapp.webhook.reap_after_seconds' => 120]);
    // attempt 3 => must wait 3 x 120s since the last attempt; 5 minutes ago is not enough.
    ($this->event)(['status' => 'failed', 'attempts' => 3, 'processing_started_at' => now()->subMinutes(5)]);

    expect((new ReapWebhookEvents)()['redriven'])->toBe(0);
});

it('processes a re-driven event end to end exactly once', function () {
    $stuck = ($this->event)();

    ProcessWebhookEvent::dispatchSync($stuck->id);
    ProcessWebhookEvent::dispatchSync($stuck->id);   // a duplicate job (e.g. after-response and reaper both ran)

    expect($stuck->fresh()->status)->toBe('processed')
        ->and($stuck->fresh()->attempts)->toBe(1)
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(1);
});

it('is scheduled every minute', function () {
    $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);

    expect($names->all())->toContain('whatsapp-reaper');
});

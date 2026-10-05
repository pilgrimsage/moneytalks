<?php

use App\Enums\MessageStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\Handlers\InboundHandler;
use App\Services\WhatsApp\OutboundMessageService;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

beforeEach(function () {
    FakeWhatsAppProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->from = '919876543210';
});

it('runs the full pipeline: webhook -> store -> handler -> reply', function () {
    postWebhook($this, waFactory()->text($this->from, 'spent 250 on vegetables', 'wamid.E2E1'))->assertOk();

    $in = WhatsappMessage::where('wa_message_id', 'wamid.E2E1')->first();
    $out = WhatsappMessage::where('direction', 'out')->first();

    expect($in->status)->toBe(MessageStatus::Processed)
        ->and($in->user_id)->toBe($this->user->id)
        ->and($in->text)->toBe('spent 250 on vegetables')
        ->and($out->status)->toBe(MessageStatus::Sent)
        ->and($out->in_reply_to)->toBe('wamid.E2E1')
        ->and($out->wa_message_id)->toStartWith('wamid.FAKE')
        ->and(sentTexts())->toHaveCount(1)
        ->and(FakeWhatsAppProvider::$read)->toBe(['wamid.E2E1']);
});

it('stores message bodies encrypted at rest', function () {
    postWebhook($this, waFactory()->text($this->from, 'my secret salary is 45000'))->assertOk();

    $raw = DB::table('whatsapp_messages')->where('direction', 'in')->first();

    expect($raw->text)->not->toContain('salary')
        ->and($raw->payload)->not->toContain('salary')
        ->and($raw->peer_bidx)->toBe(User::blindIndex($this->from))
        ->and(json_encode($raw))->not->toContain($this->from);   // the phone number itself is never stored here
});

it('opens the 24h window from the message time', function () {
    expect($this->user->fresh()->last_inbound_at)->toBeNull();

    postWebhook($this, waFactory()->text($this->from, 'hi', null, now()->subMinutes(5)->timestamp))->assertOk();

    $seen = $this->user->fresh()->last_inbound_at;
    expect($seen->diffInMinutes(now(), true))->toBeBetween(4, 6);
});

it('handles several messages in one delivery in the order they were sent', function () {
    $f = waFactory();
    $now = time();
    $payload = $f->wrapMessages([
        ['from' => $this->from, 'id' => 'wamid.B', 'timestamp' => (string) ($now + 2), 'type' => 'text', 'text' => ['body' => 'second']],
        ['from' => $this->from, 'id' => 'wamid.A', 'timestamp' => (string) $now, 'type' => 'text', 'text' => ['body' => 'first']],
    ], $this->from);

    postWebhook($this, $payload)->assertOk();

    $order = WhatsappMessage::where('direction', 'in')->orderBy('created_at')->orderBy('id')->pluck('wa_message_id')->all();
    expect($order)->toBe(['wamid.A', 'wamid.B']);
});

describe('who may talk to the bot (personal mode, fail closed)', function () {
    it('ignores strangers: no reply, no AI, and their text is never stored', function () {
        postWebhook($this, waFactory()->text('919000000000', 'hello, I am a stranger', 'wamid.STRANGER'))->assertOk();

        $row = WhatsappMessage::where('wa_message_id', 'wamid.STRANGER')->first();
        expect($row->status)->toBe(MessageStatus::Ignored)
            ->and($row->getRawOriginal('text'))->toBeNull()
            ->and($row->getRawOriginal('payload'))->toBeNull()
            ->and($row->user_id)->toBeNull()
            ->and(FakeWhatsAppProvider::$sent)->toBe([])
            ->and(FakeWhatsAppProvider::$read)->toBe([]);
    });

    it('ignores an allow-listed number that was never provisioned', function () {
        config(['moneytalks.allowed_wa_ids' => ['919876543210', '919555555555']]);

        postWebhook($this, waFactory()->text('919555555555', 'hi'))->assertOk();

        expect(WhatsappMessage::first()->status)->toBe(MessageStatus::Ignored)
            ->and(FakeWhatsAppProvider::$sent)->toBe([]);
    });

    it('ignores everyone when the allow-list is empty', function () {
        config(['moneytalks.allowed_wa_ids' => []]);

        postWebhook($this, waFactory()->text($this->from, 'hi'))->assertOk();

        expect(WhatsappMessage::first()->status)->toBe(MessageStatus::Ignored)
            ->and(FakeWhatsAppProvider::$sent)->toBe([]);
    });

    it('ignores suspended users', function () {
        $this->user->update(['status' => 'suspended']);

        postWebhook($this, waFactory()->text($this->from, 'hi'))->assertOk();

        expect(WhatsappMessage::first()->status)->toBe(MessageStatus::Ignored)
            ->and(FakeWhatsAppProvider::$sent)->toBe([]);
    });
});

describe('message types', function () {
    it('replies politely to voice and images the pipeline cannot read yet', function (string $type) {
        postWebhook($this, waFactory()->media($this->from, $type, 'MEDIA1'))->assertOk();

        expect(sentTexts())->toBe(['I can only read text messages for now.']);
    })->with(['audio', 'image']);

    it('understands a tapped button', function () {
        postWebhook($this, waFactory()->buttonReply($this->from, 'confirm:123', 'Confirm', 'wamid.BTN'))->assertOk();

        $row = WhatsappMessage::where('wa_message_id', 'wamid.BTN')->first();
        expect($row->message_type)->toBe('interactive')
            ->and($row->text)->toBe('Confirm')
            ->and($row->status)->toBe(MessageStatus::Processed);
    });

    it('survives a malformed message without losing the rest of the delivery', function () {
        $payload = waFactory()->wrapMessages([
            ['id' => 'wamid.NOFROM', 'type' => 'text'],           // missing sender
            'garbage',
            ['from' => $this->from, 'id' => 'wamid.GOOD', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'ok']],
        ], $this->from);

        postWebhook($this, $payload)->assertOk();

        expect(WhatsappMessage::where('wa_message_id', 'wamid.GOOD')->first()->status)->toBe(MessageStatus::Processed);
    });
});

describe('failures are never lost', function () {
    it('keeps the message and re-drives the event when the handler throws', function () {
        app()->bind(InboundHandler::class, fn () => new class implements InboundHandler
        {
            public function handle($user, $message, $row): void
            {
                throw new RuntimeException('boom');
            }
        });

        postWebhook($this, waFactory()->text($this->from, 'spent 100', 'wamid.FAIL1'))->assertOk();

        $event = WebhookEvent::first();
        $row = WhatsappMessage::where('wa_message_id', 'wamid.FAIL1')->first();
        expect($event->status)->toBe('failed')
            ->and($event->error)->toContain('RuntimeException')->and($event->error)->not->toContain('boom')   // class only: exception text can carry message content
            ->and($row->status)->toBe(MessageStatus::ProcessingFailed)
            ->and($row->text)->toBe('spent 100');                       // the user's words are preserved
    });

    it('retries successfully once the fault is gone, replying exactly once', function () {
        $broken = true;
        app()->bind(InboundHandler::class, fn () => new class($broken) implements InboundHandler
        {
            public function __construct(private bool $broken) {}

            public function handle($user, $message, $row): void
            {
                if ($this->broken) {
                    throw new RuntimeException('transient');
                }
                app(OutboundMessageService::class)->sendText($user, 'done', "reply:{$message->waMessageId}:0");
            }
        });
        postWebhook($this, waFactory()->text($this->from, 'x', 'wamid.RETRY1'))->assertOk();
        expect(WebhookEvent::first()->status)->toBe('failed');

        // The fault clears; the reaper re-drives the event (we call the job directly, as the queue would).
        app()->bind(InboundHandler::class, fn () => new class implements InboundHandler
        {
            public function handle($user, $message, $row): void
            {
                app(OutboundMessageService::class)->sendText($user, 'done', "reply:{$message->waMessageId}:0");
            }
        });
        ProcessWebhookEvent::dispatchSync(WebhookEvent::first()->id);

        expect(WebhookEvent::first()->status)->toBe('processed')
            ->and(WebhookEvent::first()->attempts)->toBe(2)
            ->and(WhatsappMessage::where('wa_message_id', 'wamid.RETRY1')->first()->status)->toBe(MessageStatus::Processed)
            ->and(sentTexts())->toBe(['done']);
    });

    it('stops retrying after the maximum attempts', function () {
        config(['whatsapp.webhook.max_attempts' => 2]);
        app()->bind(InboundHandler::class, fn () => new class implements InboundHandler
        {
            public function handle($user, $message, $row): void
            {
                throw new RuntimeException('always');
            }
        });
        postWebhook($this, waFactory()->text($this->from, 'x'))->assertOk();
        $id = WebhookEvent::first()->id;

        ProcessWebhookEvent::dispatchSync($id);
        ProcessWebhookEvent::dispatchSync($id);   // would be attempt 3: refused

        expect(WebhookEvent::first()->attempts)->toBe(2)
            ->and(WebhookEvent::first()->status)->toBe('failed');
    });

    it('lets only one worker claim an event', function () {
        postWebhook($this, waFactory()->text($this->from, 'x'))->assertOk();
        $id = WebhookEvent::first()->id;
        DB::table('webhook_events')->where('id', $id)->update(['status' => 'processing']);

        ProcessWebhookEvent::dispatchSync($id);   // finds it already claimed

        expect(WebhookEvent::first()->attempts)->toBe(1)
            ->and(WebhookEvent::first()->status)->toBe('processing');
    });
});

it('rate-limits a flooding user: one notice, then silence', function () {
    config(['whatsapp.rate_limit.user_messages_per_minute' => 3]);

    foreach (range(1, 8) as $i) {
        postWebhook($this, waFactory()->text($this->from, "msg {$i}", "wamid.FLOOD{$i}"))->assertOk();
    }

    $statuses = WhatsappMessage::where('direction', 'in')->orderBy('created_at')->orderBy('id')->get()->groupBy(fn ($m) => $m->status->value)->map->count();
    expect($statuses['processed'])->toBe(3)
        ->and($statuses['ignored'])->toBe(5)
        ->and(collect(sentTexts())->filter(fn ($t) => str_contains($t, 'very quickly'))->count())->toBe(1);
});

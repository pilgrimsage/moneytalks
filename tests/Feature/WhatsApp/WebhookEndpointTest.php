<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use App\Services\WhatsApp\Testing\MetaPayloadFactory;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    FakeWhatsAppProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->from = '919876543210';
});

describe('GET handshake', function () {
    it('echoes the challenge for the right verify token', function () {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=98765')
            ->assertOk()->assertSee('98765', false);
    });

    it('refuses a wrong token, a wrong mode, or missing parameters', function (string $qs) {
        $this->get('/webhooks/whatsapp?'.$qs)->assertForbidden();
    })->with([
        'wrong token' => 'hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1',
        'wrong mode' => 'hub.mode=unsubscribe&hub.verify_token=test-verify-token&hub.challenge=1',
        'no challenge' => 'hub.mode=subscribe&hub.verify_token=test-verify-token',
        'nothing' => '',
    ]);

    it('refuses everything when no verify token is configured (fail closed)', function () {
        config(['whatsapp.meta.verify_token' => '']);

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    });
});

describe('POST signature', function () {
    it('accepts a correctly signed payload and answers 200 immediately', function () {
        postWebhook($this, waFactory()->text($this->from, 'hello'))->assertOk();

        expect(WebhookEvent::count())->toBe(1);
    });

    it('rejects a missing, malformed or wrong signature and stores nothing', function (?string $sig) {
        $res = $this->call('POST', '/webhooks/whatsapp', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ]), waFactory()->sign(waFactory()->text($this->from, 'x'))['body']);

        $res->assertForbidden();
        expect(WebhookEvent::count())->toBe(0)->and(WhatsappMessage::count())->toBe(0);
    })->with([
        'missing' => [null],
        'wrong' => ['sha256='.str_repeat('0', 64)],
        'no prefix' => [str_repeat('a', 64)],
        'wrong algorithm' => ['sha1='.str_repeat('a', 40)],
    ]);

    it('rejects a body that was altered after signing', function () {
        $payload = waFactory()->text($this->from, 'spent 100');
        $signed = waFactory()->sign($payload);

        postWebhook($this, $payload, $signed['signature'], str_replace('100', '900', $signed['body']))->assertForbidden();

        expect(WebhookEvent::count())->toBe(0);
    });

    it('rejects a payload signed with another secret', function () {
        $other = new MetaPayloadFactory('1111111111', 'someone-elses-secret');
        $payload = $other->text($this->from, 'x');

        postWebhook($this, $payload, $other->sign($payload)['signature'])->assertForbidden();
    });

    it('fails closed when no app secret is configured', function () {
        config(['whatsapp.meta.app_secret' => '']);
        $signed = (new MetaPayloadFactory('1111111111', ''))->sign(waFactory()->text($this->from, 'x'));

        postWebhook($this, ['x' => 1], $signed['signature'], $signed['body'])->assertForbidden();
    });

    it('rejects malformed JSON even when correctly signed', function () {
        $body = '{not json';
        $sig = 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');

        postWebhook($this, [], $sig, $body)->assertStatus(400);
        expect(WebhookEvent::count())->toBe(0);
    });

    it('rejects an oversized body before doing anything else', function () {
        config(['whatsapp.webhook.max_body_bytes' => 200]);

        postWebhook($this, waFactory()->text($this->from, str_repeat('x', 500)))->assertStatus(413);
        expect(WebhookEvent::count())->toBe(0);
    });
});

describe('persistence and duplicates', function () {
    it('stores the raw payload durably and marks it processed', function () {
        $payload = waFactory()->text($this->from, 'hello');
        postWebhook($this, $payload)->assertOk();

        $event = WebhookEvent::first();
        expect($event->status)->toBe('processed')
            ->and($event->attempts)->toBe(1)
            ->and(json_decode($event->payload, true))->toBe($payload)
            ->and($event->event_hash)->toHaveLength(64);
    });

    it('treats an identical redelivery as a no-op', function () {
        $payload = waFactory()->text($this->from, 'hello', 'wamid.SAME1');

        postWebhook($this, $payload)->assertOk();
        postWebhook($this, $payload)->assertOk();
        postWebhook($this, $payload)->assertOk();

        expect(WebhookEvent::count())->toBe(1)
            ->and(WhatsappMessage::where('direction', 'in')->count())->toBe(1)
            ->and(FakeWhatsAppProvider::$sent)->toHaveCount(1);
    });

    it('never handles the same WhatsApp message twice even if wrapped in a different payload', function () {
        // Same message id, but an extra contact entry makes the raw body (and hash) differ.
        $a = waFactory()->text($this->from, 'hello', 'wamid.SAME2');
        $b = $a;
        $b['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] = 'Renamed';

        postWebhook($this, $a)->assertOk();
        postWebhook($this, $b)->assertOk();

        expect(WebhookEvent::count())->toBe(2)
            ->and(WhatsappMessage::where('direction', 'in')->count())->toBe(1)
            ->and(FakeWhatsAppProvider::$sent)->toHaveCount(1);
    });

    it('ignores payloads addressed to a different phone number', function () {
        $other = new MetaPayloadFactory('9999999999', 'test-app-secret');
        $payload = $other->text($this->from, 'hello');

        postWebhook($this, $payload, $other->sign($payload)['signature'])->assertOk();

        expect(WebhookEvent::first()->status)->toBe('ignored')
            ->and(WhatsappMessage::count())->toBe(0);
    });

    it('can also be processed through the queue instead of after the response', function () {
        config(['whatsapp.webhook.process_after_response' => false]);
        Queue::fake();

        postWebhook($this, waFactory()->text($this->from, 'hello'))->assertOk();

        Queue::assertPushed(ProcessWebhookEvent::class);
        expect(WebhookEvent::first()->status)->toBe('received');
    });

    it('does not expose a public web UI', function () {
        $this->get('/')->assertNotFound();
        $this->get('/up')->assertOk();
    });
});

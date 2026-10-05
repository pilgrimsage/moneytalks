<?php

use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use App\Services\WhatsApp\Testing\MetaPayloadFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->meta = new MetaWhatsAppProvider;
    config(['whatsapp.meta.graph_version' => 'v21.0']);
});

describe('sending (Graph API request shapes)', function () {
    it('posts text to the right URL with the bearer token', function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $result = $this->meta->send(Outbound::text('919876543210', 'Recorded ₹250'));

        expect($result->waMessageId)->toBe('wamid.OUT1');
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://graph.facebook.com/v21.0/1111111111/messages'
                && $r->hasHeader('Authorization', 'Bearer test-access-token')
                && $r['messaging_product'] === 'whatsapp'
                && $r['to'] === '919876543210'
                && $r['type'] === 'text'
                && $r['text']['body'] === 'Recorded ₹250'
                && $r['text']['preview_url'] === false;
        });
    });

    it('builds interactive reply buttons', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]])]);

        $this->meta->send(Outbound::buttons('91', 'Confirm?', [['id' => 'confirm:1', 'title' => 'Confirm'], ['id' => 'cancel:1', 'title' => 'Cancel']]));

        Http::assertSent(fn (Request $r) => $r['type'] === 'interactive'
            && $r['interactive']['type'] === 'button'
            && $r['interactive']['body']['text'] === 'Confirm?'
            && $r['interactive']['action']['buttons'][1] === ['type' => 'reply', 'reply' => ['id' => 'cancel:1', 'title' => 'Cancel']]);
    });

    it('builds template messages', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT3']]])]);

        $this->meta->send(Outbound::template('91', 'emi_due', 'en', [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '4200']]]]));

        Http::assertSent(fn (Request $r) => $r['type'] === 'template'
            && $r['template']['name'] === 'emi_due'
            && $r['template']['language']['code'] === 'en'
            && $r['template']['components'][0]['parameters'][0]['text'] === '4200');
    });

    it('marks messages read, and never throws if that fails', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 500)]);

        $this->meta->markRead('wamid.IN1');

        Http::assertSent(fn (Request $r) => $r['status'] === 'read' && $r['message_id'] === 'wamid.IN1');
    });
});

describe('error classification', function () {
    it('treats 429 and 5xx as transient', function (int $status) {
        Http::fake(['*' => Http::response(['error' => ['message' => 'slow down', 'code' => 4]], $status)]);

        expect(fn () => $this->meta->send(Outbound::text('91', 'x')))->toThrow(TransientSendException::class);
    })->with([429, 500, 502, 503]);

    it('treats provider rate-limit codes as transient even on 4xx', function (string $code) {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate', 'code' => (int) $code]], 400)]);

        expect(fn () => $this->meta->send(Outbound::text('91', 'x')))->toThrow(TransientSendException::class);
    })->with(['130429', '131056']);

    it('treats other 4xx as permanent and surfaces the provider code', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Re-engagement message', 'code' => 131047]], 400)]);

        try {
            $this->meta->send(Outbound::text('91', 'x'));
            $this->fail('expected an exception');
        } catch (PermanentSendException $e) {
            expect($e->providerCode)->toBe('131047')->and($e->getMessage())->toContain('Re-engagement');
        }
    });

    it('treats network failures as transient', function () {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        expect(fn () => $this->meta->send(Outbound::text('91', 'x')))->toThrow(TransientSendException::class);
    });

    it('rejects a "successful" reply that has no message id', function () {
        Http::fake(['*' => Http::response(['unexpected' => true])]);

        expect(fn () => $this->meta->send(Outbound::text('91', 'x')))->toThrow(PermanentSendException::class);
    });

    it('never leaks the access token into exception messages', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        try {
            $this->meta->send(Outbound::text('91', 'x'));
        } catch (Throwable $e) {
            expect($e->getMessage())->not->toContain('test-access-token');
        }
    });
});

describe('parsing webhooks', function () {
    it('parses text, media, and button replies', function () {
        $f = waFactory();
        $text = $this->meta->parseWebhook($f->text('919876543210', 'spent 250', 'wamid.T1', 1700000000));
        $audio = $this->meta->parseWebhook($f->media('919876543210', 'audio', 'MEDIA9', 'wamid.A1'));
        $image = $this->meta->parseWebhook($f->media('919876543210', 'image', 'MEDIA8', 'wamid.I1', 'bill'));
        $button = $this->meta->parseWebhook($f->buttonReply('919876543210', 'confirm:7', 'Confirm', 'wamid.B1'));

        $m = $text->messages[0];
        expect($m->waMessageId)->toBe('wamid.T1')->and($m->from)->toBe('919876543210')
            ->and($m->type)->toBe('text')->and($m->text)->toBe('spent 250')->and($m->timestamp)->toBe(1700000000)
            ->and($audio->messages[0]->type)->toBe('audio')->and($audio->messages[0]->mediaId)->toBe('MEDIA9')
            ->and($audio->messages[0]->mimeType)->toStartWith('audio/ogg')
            ->and($image->messages[0]->text)->toBe('bill')->and($image->messages[0]->mediaId)->toBe('MEDIA8')
            ->and($button->messages[0]->replyId)->toBe('confirm:7')->and($button->messages[0]->text)->toBe('Confirm');
    });

    it('parses list replies', function () {
        $payload = waFactory()->wrapMessages([[
            'from' => '91', 'id' => 'wamid.L1', 'timestamp' => '1', 'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'cat:food', 'title' => 'Food']],
        ]], '91');

        $m = $this->meta->parseWebhook($payload)->messages[0];
        expect($m->replyId)->toBe('cat:food')->and($m->text)->toBe('Food');
    });

    it('parses statuses including pricing and errors', function () {
        $p = waFactory()->status('91', 'wamid.S1', 'failed', [
            'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'marketing'],
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]);

        $s = $this->meta->parseWebhook($p)->statuses[0];
        expect($s->status)->toBe('failed')->and($s->pricingCategory)->toBe('marketing')->and($s->billable)->toBeTrue()
            ->and($s->errorCode)->toBe('131047')->and($s->errorMessage)->toBe('Re-engagement message');
    });

    it('keeps unknown message types instead of dropping them', function () {
        $payload = waFactory()->wrapMessages([['from' => '91', 'id' => 'wamid.X', 'timestamp' => '1', 'type' => 'sticker']], '91');

        expect($this->meta->parseWebhook($payload)->messages[0]->type)->toBe('sticker');
    });

    it('tolerates hostile or truncated payloads without throwing', function (mixed $payload) {
        $parsed = $this->meta->parseWebhook(is_array($payload) ? $payload : []);

        expect($parsed->messages)->toBe([])->and($parsed->statuses)->toBe([]);
    })->with([
        'empty' => [[]],
        'entry not a list' => [['entry' => 'x']],
        'changes missing' => [['entry' => [['id' => 'x']]]],
        'value not an array' => [['entry' => [['changes' => [['field' => 'messages', 'value' => 'oops']]]]]],
        'other field' => [['entry' => [['changes' => [['field' => 'account_update', 'value' => ['x' => 1]]]]]]],
    ]);

    it('flags payloads for another phone number', function () {
        $other = new MetaPayloadFactory('9999999999', 'x');

        $parsed = $this->meta->parseWebhook($other->text('91', 'hi'));
        expect($parsed->messages)->toBe([])->and($parsed->forThisNumber)->toBeFalse();
    });
});

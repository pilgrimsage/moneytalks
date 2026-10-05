<?php

use App\Models\LedgerTransaction;
use App\Models\WebhookEvent;
use App\Models\WhatsappMessage;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\Exceptions\MediaException;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use App\Services\WhatsApp\TelegramProvider;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TG_TOKEN = '123456:TEST-bot-token';
const TG_SECRET = 'tg-secret-0123456789abcdef';
const TG_USER = 424242424;

beforeEach(function () {
    config([
        'whatsapp.provider' => 'telegram',
        'whatsapp.telegram.bot_token' => TG_TOKEN,
        'whatsapp.telegram.webhook_secret' => TG_SECRET,
        'whatsapp.outbound.retry_backoff_ms' => [0, 0],
        'moneytalks.allowed_wa_ids' => [(string) TG_USER],
    ]);
    app()->forgetInstance(WhatsAppProvider::class);
    $this->tg = new TelegramProvider;
});

function tgUpdate(array $message, int $updateId = 1001): array
{
    return ['update_id' => $updateId, 'message' => $message + ['message_id' => 5, 'date' => 1_790_000_000, 'from' => ['id' => TG_USER, 'is_bot' => false], 'chat' => ['id' => TG_USER, 'type' => 'private']]];
}

function tgPost($t, array $payload, ?string $secret = TG_SECRET)
{
    return $t->call('POST', '/webhooks/telegram', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $secret,
    ]), json_encode($payload));
}

function tgOk(int $messageId = 7): array
{
    return ['ok' => true, 'result' => ['message_id' => $messageId]];
}

describe('authentication', function () {
    it('accepts only the registered secret token and fails closed without one', function () {
        expect($this->tg->verifySignature('{}', TG_SECRET))->toBeTrue()
            ->and($this->tg->verifySignature('{}', 'wrong'))->toBeFalse()
            ->and($this->tg->verifySignature('{}', null))->toBeFalse()
            ->and($this->tg->verifyChallenge(['hub_mode' => 'subscribe']))->toBeNull()
            ->and($this->tg->signatureHeader())->toBe('X-Telegram-Bot-Api-Secret-Token')
            ->and($this->tg->enforcesServiceWindow())->toBeFalse();

        config(['whatsapp.telegram.webhook_secret' => '']);
        expect($this->tg->verifySignature('{}', ''))->toBeFalse();
    });

    it('answers 403 and stores nothing for a missing or wrong secret', function () {
        tgPost($this, tgUpdate(['text' => 'hi']), 'wrong')->assertForbidden();
        tgPost($this, tgUpdate(['text' => 'hi']), null)->assertForbidden();

        expect(WebhookEvent::count())->toBe(0);
    });
});

describe('parsing updates', function () {
    it('reads text, and turns commands into plain words', function (string $in, string $out) {
        $m = $this->tg->parseWebhook(tgUpdate(['text' => $in]))->messages[0];

        expect($m->type)->toBe('text')->and($m->text)->toBe($out)->and($m->from)->toBe((string) TG_USER)->and($m->waMessageId)->toBe('tg:1001');
    })->with([
        'plain' => ['spent 250 on vegetables', 'spent 250 on vegetables'],
        'start' => ['/start', 'help'],
        'help' => ['/help', 'help'],
        'command with bot name' => ['/balance@MoneyBot', 'balance'],
        'command with args' => ['/undo', 'undo'],
    ]);

    it('reads a voice note, a photo (largest size) and a document', function () {
        $voice = $this->tg->parseWebhook(tgUpdate(['voice' => ['file_id' => 'VOICE1', 'mime_type' => 'audio/ogg']]))->messages[0];
        $photo = $this->tg->parseWebhook(tgUpdate(['photo' => [['file_id' => 'SMALL'], ['file_id' => 'BIG']], 'caption' => 'lunch']))->messages[0];
        $doc = $this->tg->parseWebhook(tgUpdate(['document' => ['file_id' => 'D1']]))->messages[0];

        expect($voice->type)->toBe('audio')->and($voice->mediaId)->toBe('VOICE1')->and($voice->mimeType)->toBe('audio/ogg')
            ->and($photo->type)->toBe('image')->and($photo->mediaId)->toBe('BIG')->and($photo->mimeType)->toBe('image/jpeg')->and($photo->text)->toBe('lunch')
            ->and($doc->type)->toBe('document');
    });

    it('reads a button tap with its title and an id that markRead can answer', function () {
        $update = ['update_id' => 77, 'callback_query' => [
            'id' => 'CBID1', 'from' => ['id' => TG_USER, 'is_bot' => false], 'data' => 'confirm:ABC',
            'message' => ['message_id' => 9, 'date' => 1_790_000_000, 'chat' => ['id' => TG_USER, 'type' => 'private'],
                'reply_markup' => ['inline_keyboard' => [[['text' => 'Confirm', 'callback_data' => 'confirm:ABC'], ['text' => 'Cancel', 'callback_data' => 'cancel:ABC']]]]],
        ]];
        $m = $this->tg->parseWebhook($update)->messages[0];

        expect($m->type)->toBe('interactive')->and($m->replyId)->toBe('confirm:ABC')->and($m->text)->toBe('Confirm')->and($m->waMessageId)->toBe('tg:77:cb:CBID1');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->tg->markRead($m->waMessageId);
        $this->tg->markRead('tg:1001'); // a plain message has nothing to answer

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'CBID1');
    });

    it('ignores groups, other bots and update kinds it does not serve', function () {
        $group = tgUpdate(['text' => 'hi', 'chat' => ['id' => -100, 'type' => 'group']]);
        $bot = tgUpdate(['text' => 'hi', 'from' => ['id' => 5, 'is_bot' => true]]);

        expect($this->tg->parseWebhook($group)->messages)->toBe([])
            ->and($this->tg->parseWebhook($bot)->messages)->toBe([])
            ->and($this->tg->parseWebhook(['update_id' => 1, 'edited_message' => ['text' => 'x']])->messages)->toBe([])
            ->and($this->tg->parseWebhook(['nonsense' => true])->messages)->toBe([]);
    });
});

describe('sending', function () {
    it('sends text as HTML: *bold* and _italic_ work, anything else is escaped', function () {
        Http::fake(['api.telegram.org/*' => Http::response(tgOk(31))]);

        $result = $this->tg->send(Outbound::text((string) TG_USER, 'Recorded *₹250* under _Food_ <b>&</b> snacks_and_more'));

        expect($result->waMessageId)->toBe('tgout:'.TG_USER.':31');
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://api.telegram.org/bot'.TG_TOKEN.'/sendMessage'
                && $r['chat_id'] === (string) TG_USER && $r['parse_mode'] === 'HTML'
                && $r['text'] === 'Recorded <b>₹250</b> under <i>Food</i> &lt;b&gt;&amp;&lt;/b&gt; snacks_and_more';
        });
    });

    it('sends reply buttons as an inline keyboard whose callback data is the button id', function () {
        Http::fake(['api.telegram.org/*' => Http::response(tgOk())]);

        $this->tg->send(Outbound::buttons((string) TG_USER, 'Record it?', [['id' => 'confirm:X1', 'title' => 'Confirm'], ['id' => 'cancel:X1', 'title' => 'Cancel']]));

        Http::assertSent(fn (Request $r) => $r['reply_markup']['inline_keyboard'][0] === [
            ['text' => 'Confirm', 'callback_data' => 'confirm:X1'], ['text' => 'Cancel', 'callback_data' => 'cancel:X1'],
        ]);
    });

    it('uploads a document with its caption', function () {
        Http::fake(['api.telegram.org/*' => Http::response(tgOk())]);
        $path = tempnam(sys_get_temp_dir(), 'tg');
        file_put_contents($path, "date,amount\n2026-10-04,250\n");

        $this->tg->send(Outbound::document((string) TG_USER, $path, 'export.csv', 'Your *export*'));
        @unlink($path);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendDocument') && $r->isMultipart()
            && collect($r->data())->contains(fn ($p) => ($p['name'] ?? '') === 'document' && ($p['filename'] ?? '') === 'export.csv')
            && collect($r->data())->contains(fn ($p) => ($p['name'] ?? '') === 'caption' && $p['contents'] === 'Your <b>export</b>'));
    });

    it('classifies errors: 429 and 5xx retry, other 4xx do not, and nothing leaks the token', function () {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5'], 429)
            ->push(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502)
            ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            ->pushFailedConnection('cURL error 28: Operation timed out for https://api.telegram.org/bot'.TG_TOKEN.'/sendMessage')]);
        $send = fn () => $this->tg->send(Outbound::text((string) TG_USER, 'hi'));

        expect($send)->toThrow(TransientSendException::class, 'Too Many Requests')
            ->and($send)->toThrow(TransientSendException::class)
            ->and($send)->toThrow(PermanentSendException::class, 'blocked by the user');

        try {
            $send();
            $this->fail('expected a transient failure');
        } catch (TransientSendException $e) {
            expect($e->getMessage())->not->toContain(TG_TOKEN)->and($e->getMessage())->not->toContain('bot123456');
        }
    });

    it('refuses templates (Telegram has none) and works without a token configured', function () {
        expect(fn () => $this->tg->send(Outbound::template((string) TG_USER, 'reminder')))->toThrow(PermanentSendException::class);

        config(['whatsapp.telegram.bot_token' => '']);
        expect(fn () => $this->tg->send(Outbound::text((string) TG_USER, 'hi')))->toThrow(PermanentSendException::class, 'TELEGRAM_BOT_TOKEN');
    });
});

describe('media', function () {
    it('downloads a voice note from Telegram only, and reports its type from the file extension', function () {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'V1', 'file_size' => 9, 'file_path' => 'voice/file_3.oga']]),
            'api.telegram.org/file/bot*' => Http::response('OggS-bytes'),
        ]);

        $file = $this->tg->downloadMedia('V1', 1000);

        expect($file->mimeType)->toBe('audio/ogg')->and($file->bytes)->toBe('OggS-bytes');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/file/bot'.TG_TOKEN.'/voice/file_3.oga');
        Http::assertSent(fn (Request $r) => parse_url($r->url(), PHP_URL_HOST) === 'api.telegram.org');
    });

    it('refuses too-large files before downloading, and suspicious paths', function () {
        Http::fake(['api.telegram.org/bot*/getFile' => Http::sequence()
            ->push(['ok' => true, 'result' => ['file_size' => 5000, 'file_path' => 'voice/a.oga']])
            ->push(['ok' => true, 'result' => ['file_size' => 5, 'file_path' => '../../etc/passwd']])]);

        expect(fn () => $this->tg->downloadMedia('V', 1000))->toThrow(MediaException::class, 'too large')
            ->and(fn () => $this->tg->downloadMedia('V', 1000))->toThrow(MediaException::class, 'Unexpected');
        Http::assertSentCount(2); // no file download was attempted
    });
});

describe('end to end through the webhook', function () {
    beforeEach(function () {
        useInterpretationHandler();
        FakeAIProvider::reset();
        $this->user = ledgerUser((string) TG_USER);
        Http::fake(['api.telegram.org/*' => Http::response(tgOk(50))]);
    });

    it('answers "help" without any AI call', function () {
        tgPost($this, tgUpdate(['text' => '/start']))->assertOk();

        expect(WebhookEvent::first()->status)->toBe('processed')
            ->and(WhatsappMessage::where('direction', 'out')->first()->status->value)->toBe('sent');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === (string) TG_USER && str_contains(strtolower($r['text']), 'spent'));
    });

    it('records an expense and replies', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '250', 'category' => 'vegetables'])));

        tgPost($this, tgUpdate(['text' => 'spent 250 on vegetables']))->assertOk();

        expect(LedgerTransaction::count())->toBe(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], '250'));
    });

    it('handles a redelivered update once', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['amount' => '250', 'category' => 'vegetables'])));
        $update = tgUpdate(['text' => 'spent 250 on vegetables'], 2002);

        tgPost($this, $update)->assertOk();
        tgPost($this, $update)->assertOk();

        expect(LedgerTransaction::count())->toBe(1)->and(WebhookEvent::count())->toBe(1);
    });

    it('stays silent and stores nothing for someone who is not on the allow-list', function () {
        tgPost($this, tgUpdate(['text' => 'hello', 'from' => ['id' => 999, 'is_bot' => false], 'chat' => ['id' => 999, 'type' => 'private']]))->assertOk();

        Http::assertNothingSent();
        expect(WhatsappMessage::first()->text)->toBeNull();
    });
});

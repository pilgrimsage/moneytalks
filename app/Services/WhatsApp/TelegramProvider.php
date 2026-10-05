<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\DTO\InboundMessage;
use App\Services\WhatsApp\DTO\MediaFile;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\DTO\ParsedWebhook;
use App\Services\WhatsApp\DTO\SendResult;
use App\Services\WhatsApp\Exceptions\MediaException;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram Bot API. The ONLY class that knows Telegram's URLs, field names and wire format.
 *
 * Identity: a private chat id equals the user's Telegram id (a plain positive number), so it fits the
 * existing "wa id" slot, allow-list and blind index unchanged. Only private chats are served.
 *
 * Secrets: the bot token is part of every API URL. Never put a URL, a request or an exception message
 * from the HTTP client into a log or an error (cURL messages embed the URL). Errors here carry only
 * Telegram's own description and code.
 */
class TelegramProvider implements WhatsAppProvider
{
    /** Telegram's hard limit is 4096; captions are limited to 1024. */
    private const MAX_CAPTION = 1000;

    private const EXTENSION_MIMES = [
        'oga' => 'audio/ogg', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    ];

    /** Telegram has no GET handshake. */
    public function verifyChallenge(array $query): ?string
    {
        return null;
    }

    /** Telegram echoes the secret we registered with setWebhook in this header. */
    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('whatsapp.telegram.webhook_secret');
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false; // fail closed
        }

        return hash_equals($secret, $signatureHeader);
    }

    public function signatureHeader(): string
    {
        return 'X-Telegram-Bot-Api-Secret-Token';
    }

    public function enforcesServiceWindow(): bool
    {
        return false;
    }

    public function parseWebhook(array $payload): ParsedWebhook
    {
        $messages = [];
        $updateId = $payload['update_id'] ?? null;

        if (is_int($updateId) || (is_string($updateId) && ctype_digit($updateId))) {
            $parsed = isset($payload['callback_query']) && is_array($payload['callback_query'])
                ? $this->parseCallback((string) $updateId, $payload['callback_query'])
                : (isset($payload['message']) && is_array($payload['message']) ? $this->parseMessage((string) $updateId, $payload['message']) : null);
            if ($parsed) {
                $messages[] = $parsed;
            }
        }

        return new ParsedWebhook($messages, [], true);
    }

    private function parseMessage(string $updateId, array $m): ?InboundMessage
    {
        $from = data_get($m, 'from.id');
        if (! is_int($from) || data_get($m, 'from.is_bot') === true || data_get($m, 'chat.type') !== 'private') {
            return null; // groups, channels and other bots are not served
        }

        $type = 'unsupported';
        $text = null;
        $mediaId = null;
        $mime = null;

        if (isset($m['text']) && is_string($m['text'])) {
            $type = 'text';
            $text = $this->normaliseCommand($m['text']);
        } elseif (is_array($m['voice'] ?? null)) {
            $type = 'audio';
            $mediaId = data_get($m, 'voice.file_id');
            $mime = data_get($m, 'voice.mime_type') ?: 'audio/ogg';
        } elseif (is_array($m['audio'] ?? null)) {
            $type = 'audio';
            $mediaId = data_get($m, 'audio.file_id');
            $mime = data_get($m, 'audio.mime_type');
        } elseif (is_array($m['photo'] ?? null) && $m['photo'] !== []) {
            $type = 'image';
            $largest = end($m['photo']); // sizes are ordered smallest to largest
            $mediaId = is_array($largest) ? ($largest['file_id'] ?? null) : null;
            $mime = 'image/jpeg'; // Telegram re-encodes photos as JPEG
            $text = isset($m['caption']) && is_string($m['caption']) ? $m['caption'] : null;
        } elseif (is_array($m['document'] ?? null)) {
            $type = 'document';
        }

        return new InboundMessage(
            waMessageId: 'tg:'.$updateId,
            from: (string) $from,
            type: $type,
            text: $text,
            timestamp: (int) ($m['date'] ?? 0),
            mediaId: is_string($mediaId) ? $mediaId : null,
            mimeType: is_string($mime) ? strtolower($mime) : null,
            raw: $this->minimalRaw($m),
        );
    }

    private function parseCallback(string $updateId, array $cb): ?InboundMessage
    {
        $from = data_get($cb, 'from.id');
        $data = $cb['data'] ?? null;
        $callbackId = $cb['id'] ?? null;
        if (! is_int($from) || data_get($cb, 'from.is_bot') === true || ! is_string($data) || $data === '' || ! is_scalar($callbackId)) {
            return null;
        }

        // Find the tapped button's title in the message it was attached to (used only as display text).
        $title = null;
        foreach ((array) data_get($cb, 'message.reply_markup.inline_keyboard', []) as $row) {
            foreach ((array) $row as $button) {
                if (is_array($button) && ($button['callback_data'] ?? null) === $data && is_string($button['text'] ?? null)) {
                    $title = $button['text'];
                }
            }
        }

        return new InboundMessage(
            waMessageId: 'tg:'.$updateId.':cb:'.$callbackId, // markRead() answers the callback with this id
            from: (string) $from,
            type: 'interactive',
            text: $title,
            timestamp: (int) (data_get($cb, 'message.date') ?: time()),
            replyId: $data,
            raw: ['callback_data' => $data],
        );
    }

    /** "/start" and "/help" show help; "/balance@MyBot" is "balance". */
    private function normaliseCommand(string $text): string
    {
        $text = trim($text);
        if (! str_starts_with($text, '/')) {
            return $text;
        }

        $word = (string) strtok(substr($text, 1), " \n");
        $command = strtolower((string) preg_replace('/@\w+$/', '', $word));
        $rest = trim(substr($text, 1 + strlen($word)));

        return $command === 'start' ? 'help' : trim($command.' '.$rest);
    }

    /** Keep only what is useful for debugging; the message text itself is stored separately (encrypted). */
    private function minimalRaw(array $m): array
    {
        return array_filter([
            'message_id' => $m['message_id'] ?? null,
            'has_voice' => isset($m['voice']) ?: null,
            'has_photo' => isset($m['photo']) ?: null,
        ]);
    }

    public function send(Outbound $message): SendResult
    {
        $chat = $message->to;

        $result = match ($message->kind) {
            'text' => $this->call('sendMessage', $this->textParams($chat, (string) $message->body)),
            'buttons' => $this->call('sendMessage', $this->textParams($chat, (string) $message->body) + [
                'reply_markup' => ['inline_keyboard' => [array_map(
                    fn (array $b) => ['text' => $b['title'], 'callback_data' => $b['id']],
                    $message->buttons,
                )]],
            ]),
            'document' => $this->sendDocument($message),
            'template' => throw new PermanentSendException('Telegram has no message templates.'),
            default => throw new \InvalidArgumentException('Unsupported outbound message kind.'),
        };

        $id = data_get($result, 'message_id');
        if (! is_int($id)) {
            throw new PermanentSendException('Telegram accepted the request but returned no message id.');
        }

        return new SendResult('tgout:'.$chat.':'.$id);
    }

    private function sendDocument(Outbound $message): array
    {
        $params = ['chat_id' => $message->to];
        if ($message->body !== null && $message->body !== '') {
            $params['caption'] = mb_substr($this->toHtml($message->body), 0, self::MAX_CAPTION, 'UTF-8');
            $params['parse_mode'] = 'HTML';
        }

        return $this->call('sendDocument', $params, [
            'name' => 'document',
            'contents' => (string) file_get_contents((string) $message->filePath),
            'filename' => (string) $message->filename,
        ]);
    }

    /** @return array<string, mixed> */
    private function textParams(string $chat, string $body): array
    {
        return [
            'chat_id' => $chat,
            'text' => $this->toHtml($body),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];
    }

    /**
     * Replies use WhatsApp-style *bold* and _italic_. Telegram's HTML mode needs everything else escaped,
     * so a stray "<" or "&" in a category name can never break a send.
     */
    public function toHtml(string $text): string
    {
        $html = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<b>$1</b>', $html) ?? $html;

        return preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<i>$1</i>', $html) ?? $html;
    }

    public function downloadMedia(string $mediaId, int $maxBytes): MediaFile
    {
        try {
            $info = $this->call('getFile', ['file_id' => $mediaId]);
        } catch (TransientSendException $e) {
            throw new MediaException('Telegram file lookup failed', 'lookup_failed', true);
        } catch (PermanentSendException $e) {
            throw new MediaException('Telegram file lookup failed', 'lookup_failed');
        }

        $path = data_get($info, 'file_path');
        if (! is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new MediaException('Unexpected Telegram file path', 'bad_url');
        }
        if ((int) data_get($info, 'file_size') > $maxBytes) {
            throw new MediaException('Media file is too large', 'too_large');
        }

        // Always Telegram's own host (api_base); the token goes nowhere else.
        $url = rtrim((string) config('whatsapp.telegram.api_base'), '/').'/file/bot'.config('whatsapp.telegram.bot_token').'/'
            .implode('/', array_map('rawurlencode', explode('/', $path)));

        try {
            $file = Http::withoutRedirecting()->timeout((int) config('whatsapp.telegram.timeout_seconds') * 3)->get($url);
        } catch (ConnectionException) {
            throw new MediaException('Network error downloading media', 'network', true);
        }
        if (! $file->successful()) {
            throw new MediaException('Telegram media download HTTP '.$file->status(), 'download_failed', $file->serverError() || $file->status() === 429);
        }
        if (strlen($file->body()) > $maxBytes) {
            throw new MediaException('Media file is too large', 'too_large');
        }

        $mime = self::EXTENSION_MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return new MediaFile($file->body(), $mime);
    }

    /** Answers a button tap so the spinner on the button stops. Telegram has no read receipts, so other ids are ignored. */
    public function markRead(string $waMessageId): void
    {
        if (preg_match('/^tg:\d+:cb:(.+)$/', $waMessageId, $m) !== 1) {
            return;
        }

        try {
            $this->call('answerCallbackQuery', ['callback_query_id' => $m[1]]);
        } catch (Throwable $e) {
            Log::notice('telegram.answer_callback_failed', ['error' => get_class($e)]);
        }
    }

    /**
     * Call a Bot API method. Public so the webhook console command can use setWebhook/getMe without a second HTTP stack.
     *
     * @param  array<string, mixed>  $params
     * @param  array{name: string, contents: string, filename: string}|null  $attachment
     * @return array<string, mixed> the "result" object
     *
     * @throws TransientSendException
     * @throws PermanentSendException
     */
    public function call(string $method, array $params = [], ?array $attachment = null): array
    {
        $token = (string) config('whatsapp.telegram.bot_token');
        if ($token === '') {
            throw new PermanentSendException('TELEGRAM_BOT_TOKEN is not set.');
        }
        $url = rtrim((string) config('whatsapp.telegram.api_base'), '/').'/bot'.$token.'/'.$method;

        try {
            $request = Http::acceptJson()->timeout((int) config('whatsapp.telegram.timeout_seconds'));
            if ($attachment !== null) {
                $request = $request->attach($attachment['name'], $attachment['contents'], $attachment['filename']);
                // multipart cannot carry nested values: encode them as JSON strings
                $params = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $params);
                $response = $request->post($url, $params);
            } else {
                $response = $request->asJson()->post($url, $params);
            }
        } catch (ConnectionException) {
            // Never include the client's message: it embeds the URL and therefore the bot token.
            throw new TransientSendException('Network error talking to Telegram.');
        }

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    private function unwrap(Response $response): array
    {
        if ($response->successful() && $response->json('ok') === true) {
            $result = $response->json('result');

            return is_array($result) ? $result : ['value' => $result];
        }

        $code = (int) ($response->json('error_code') ?: $response->status());
        $detail = preg_replace('/\d{7,}/', '#', (string) ($response->json('description') ?? $response->reason())) ?? '';
        $summary = "Telegram HTTP {$response->status()} (code {$code}): {$detail}";

        if ($code === 429 || $code >= 500) {
            throw new TransientSendException($summary);
        }

        throw new PermanentSendException($summary, (string) $code);
    }
}

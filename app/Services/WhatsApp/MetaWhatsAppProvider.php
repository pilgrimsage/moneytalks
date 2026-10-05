<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\DTO\InboundMessage;
use App\Services\WhatsApp\DTO\InboundStatus;
use App\Services\WhatsApp\DTO\MediaFile;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\DTO\ParsedWebhook;
use App\Services\WhatsApp\DTO\SendResult;
use App\Services\WhatsApp\Exceptions\MediaException;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meta WhatsApp Cloud API (direct; no intermediary). The ONLY class that knows Graph API URLs,
 * field names and wire formats. Field names follow Meta's documented webhook/send formats; re-verify
 * them (and META_GRAPH_VERSION) against current Meta docs when upgrading.
 */
class MetaWhatsAppProvider implements WhatsAppProvider
{
    /** Provider error codes that are retryable even though they are 4xx (rate limits). */
    private const TRANSIENT_CODES = ['130429', '131056', '80007'];

    public function verifyChallenge(array $query): ?string
    {
        $expected = (string) config('whatsapp.meta.verify_token');
        $given = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? null;
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? null;

        if ($expected === '' || $mode !== 'subscribe' || $challenge === null || ! hash_equals($expected, $given)) {
            return null;
        }

        return (string) $challenge;
    }

    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('whatsapp.meta.app_secret');
        if ($secret === '' || $signatureHeader === null || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false; // fail closed: no secret configured means nothing is trusted
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($signatureHeader, 7));
    }

    public function signatureHeader(): string
    {
        return 'X-Hub-Signature-256';
    }

    public function enforcesServiceWindow(): bool
    {
        return true;
    }

    public function parseWebhook(array $payload): ParsedWebhook
    {
        $messages = [];
        $statuses = [];
        $forUs = true;
        $ourNumber = (string) config('whatsapp.meta.phone_number_id');

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value');
                if (data_get($change, 'field') !== 'messages' || ! is_array($value)) {
                    continue;
                }
                if ($ourNumber === '' || (string) data_get($value, 'metadata.phone_number_id') !== $ourNumber) {
                    $forUs = false;

                    continue;
                }

                foreach ((array) ($value['messages'] ?? []) as $m) {
                    if ($parsed = $this->parseMessage($m)) {
                        $messages[] = $parsed;
                    }
                }
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    if ($parsed = $this->parseStatus($s)) {
                        $statuses[] = $parsed;
                    }
                }
            }
        }

        // Oldest first, so a user's messages are handled in the order they were sent.
        usort($messages, fn (InboundMessage $a, InboundMessage $b) => $a->timestamp <=> $b->timestamp);

        return new ParsedWebhook($messages, $statuses, $forUs || $messages !== [] || $statuses !== []);
    }

    private function parseMessage(mixed $m): ?InboundMessage
    {
        if (! is_array($m) || ! isset($m['id'], $m['from']) || ! is_string($m['id']) || ! is_scalar($m['from'])) {
            return null;
        }

        $type = is_string($m['type'] ?? null) ? $m['type'] : 'unsupported';
        $text = null;
        $mediaId = null;
        $mime = null;
        $replyId = null;

        switch ($type) {
            case 'text':
                $text = data_get($m, 'text.body');
                break;
            case 'audio':
            case 'image':
            case 'document':
            case 'video':
                $mediaId = data_get($m, "{$type}.id");
                $mime = data_get($m, "{$type}.mime_type");
                $text = data_get($m, "{$type}.caption");
                break;
            case 'interactive':
                $kind = data_get($m, 'interactive.type');
                $reply = data_get($m, "interactive.{$kind}");
                $replyId = data_get($reply, 'id');
                $text = data_get($reply, 'title');
                break;
            case 'button': // quick-reply button on a template
                $replyId = data_get($m, 'button.payload');
                $text = data_get($m, 'button.text');
                break;
        }

        return new InboundMessage(
            waMessageId: $m['id'],
            from: preg_replace('/\D+/', '', (string) $m['from']) ?? '',
            type: $type,
            text: is_string($text) ? $text : null,
            timestamp: (int) ($m['timestamp'] ?? 0),
            mediaId: is_string($mediaId) ? $mediaId : null,
            mimeType: is_string($mime) ? $mime : null,
            replyId: is_string($replyId) ? $replyId : null,
            raw: $m,
        );
    }

    private function parseStatus(mixed $s): ?InboundStatus
    {
        if (! is_array($s) || ! isset($s['id'], $s['status']) || ! is_string($s['id']) || ! is_string($s['status'])) {
            return null;
        }

        return new InboundStatus(
            waMessageId: $s['id'],
            status: $s['status'],
            timestamp: (int) ($s['timestamp'] ?? 0),
            pricingCategory: data_get($s, 'pricing.category'),
            pricingModel: data_get($s, 'pricing.pricing_model'),
            billable: data_get($s, 'pricing.billable'),
            errorCode: ($c = data_get($s, 'errors.0.code')) !== null ? (string) $c : null,
            errorMessage: data_get($s, 'errors.0.title') ?? data_get($s, 'errors.0.message'),
        );
    }

    public function send(Outbound $message): SendResult
    {
        $mediaId = $message->kind === 'document' ? $this->uploadMedia($message) : null;

        $body = match ($message->kind) {
            'text' => [
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $message->body],
            ],
            'buttons' => [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $message->body],
                    'action' => ['buttons' => array_map(
                        fn (array $b) => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => $b['title']]],
                        $message->buttons,
                    )],
                ],
            ],
            'document' => [
                'type' => 'document',
                'document' => array_filter(['id' => $mediaId, 'filename' => $message->filename, 'caption' => $message->body]),
            ],
            'template' => [
                'type' => 'template',
                'template' => [
                    'name' => $message->templateName,
                    'language' => ['code' => $message->language],
                    'components' => $message->templateComponents,
                ],
            ],
            default => throw new \InvalidArgumentException('Unsupported outbound message kind.'),
        };

        $response = $this->post(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $message->to] + $body);

        $id = $response->json('messages.0.id');
        if (! is_string($id) || $id === '') {
            throw new PermanentSendException('Meta accepted the request but returned no message id.');
        }

        return new SendResult($id);
    }

    public function downloadMedia(string $mediaId, int $maxBytes): MediaFile
    {
        $base = rtrim((string) config('whatsapp.meta.api_base'), '/').'/'.config('whatsapp.meta.graph_version');
        try {
            $meta = $this->client()->get($base.'/'.rawurlencode($mediaId));
            if (! $meta->successful() || ! is_string($meta->json('url'))) {
                throw new MediaException('Meta media lookup HTTP '.$meta->status(), 'lookup_failed', $meta->serverError() || $meta->status() === 429);
            }
            if ((int) $meta->json('file_size') > $maxBytes) {
                throw new MediaException('Media file is too large', 'too_large');
            }
            // The download URL is Meta's and needs the same bearer token. Never follow it to any other host.
            if (! $this->isTrustedMediaUrl((string) $meta->json('url'))) {
                throw new MediaException('Unexpected media URL', 'bad_url');
            }
            $file = Http::withToken((string) config('whatsapp.meta.access_token'))->withoutRedirecting()->timeout((int) config('whatsapp.meta.timeout_seconds') * 3)->get((string) $meta->json('url'));
        } catch (ConnectionException $e) {
            throw new MediaException('Network error downloading media', 'network', true);
        }
        if (! $file->successful()) {
            throw new MediaException('Meta media download HTTP '.$file->status(), 'download_failed', $file->serverError() || $file->status() === 429);
        }
        if ((int) $file->header('Content-Length') > $maxBytes) {
            throw new MediaException('Media file is too large', 'too_large');
        }
        $bytes = $file->body();
        if (strlen($bytes) > $maxBytes) {
            throw new MediaException('Media file is too large', 'too_large');
        }

        return new MediaFile($bytes, strtolower(trim(explode(';', (string) ($meta->json('mime_type') ?: $file->header('Content-Type')))[0])));
    }

    public function markRead(string $waMessageId): void
    {
        try {
            $this->post(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $waMessageId]);
        } catch (Throwable $e) {
            Log::notice('whatsapp.mark_read_failed', ['error' => $e->getMessage()]);
        }
    }

    /** https only, and only Meta's own domains (or the configured Graph API host). */
    private function isTrustedMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        if ($host === strtolower((string) parse_url((string) config('whatsapp.meta.api_base'), PHP_URL_HOST))) {
            return true;
        }
        foreach ((array) config('whatsapp.media.allowed_host_suffixes') as $suffix) {
            if (str_ends_with($host, (string) $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Upload the file to Meta's media endpoint and return its media id (never logged with the file content). */
    private function uploadMedia(Outbound $message): string
    {
        try {
            $response = Http::withToken((string) config('whatsapp.meta.access_token'))
                ->acceptJson()
                ->timeout((int) config('whatsapp.meta.timeout_seconds'))
                ->attach('file', (string) file_get_contents($message->filePath), (string) $message->filename, ['Content-Type' => $message->mimeType])
                ->post(rtrim((string) config('whatsapp.meta.api_base'), '/').'/'.config('whatsapp.meta.graph_version').'/'.config('whatsapp.meta.phone_number_id').'/media', [
                    'messaging_product' => 'whatsapp', 'type' => $message->mimeType,
                ]);
        } catch (ConnectionException $e) {
            throw new TransientSendException('Network error uploading media to Meta: '.$e->getMessage());
        }

        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || $id === '') {
            $summary = 'Meta media upload HTTP '.$response->status().': '.(string) ($response->json('error.message') ?? $response->reason());
            throw ($response->status() === 429 || $response->serverError()) ? new TransientSendException($summary) : new PermanentSendException($summary);
        }

        return $id;
    }

    private function post(array $payload): Response
    {
        try {
            $response = $this->client()->post($this->url(), $payload);
        } catch (ConnectionException $e) {
            throw new TransientSendException('Network error talking to Meta: '.$e->getMessage());
        }

        if ($response->successful()) {
            return $response;
        }

        $code = (string) ($response->json('error.code') ?? '');
        $detail = preg_replace('/\d{7,}/', '#', (string) ($response->json('error.message') ?? $response->reason())) ?? '';
        $summary = "Meta HTTP {$response->status()}".($code !== '' ? " (code {$code})" : '').": {$detail}";

        if ($response->status() === 429 || $response->serverError() || in_array($code, self::TRANSIENT_CODES, true)) {
            throw new TransientSendException($summary);
        }

        throw new PermanentSendException($summary, $code !== '' ? $code : null);
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('whatsapp.meta.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('whatsapp.meta.timeout_seconds'));
    }

    private function url(): string
    {
        return rtrim((string) config('whatsapp.meta.api_base'), '/')
            .'/'.config('whatsapp.meta.graph_version')
            .'/'.config('whatsapp.meta.phone_number_id').'/messages';
    }
}

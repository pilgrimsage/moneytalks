<?php

namespace App\Services\WhatsApp\Testing;

/**
 * Builds Meta-format webhook payloads and signs them. Used by the simulator command and the tests,
 * so development never needs a real phone. (Mirrors the documented Cloud API webhook shape.)
 */
class MetaPayloadFactory
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $appSecret,
    ) {}

    public static function fromConfig(): self
    {
        return new self((string) config('whatsapp.meta.phone_number_id'), (string) config('whatsapp.meta.app_secret'));
    }

    public function text(string $from, string $body, ?string $id = null, ?int $timestamp = null): array
    {
        return $this->wrapMessages([[
            'from' => $from, 'id' => $id ?? $this->newId(), 'timestamp' => (string) ($timestamp ?? time()),
            'type' => 'text', 'text' => ['body' => $body],
        ]], $from);
    }

    public function media(string $from, string $type, string $mediaId, ?string $id = null, ?string $caption = null): array
    {
        $media = ['id' => $mediaId, 'mime_type' => $type === 'audio' ? 'audio/ogg; codecs=opus' : 'image/jpeg'];
        if ($caption !== null) {
            $media['caption'] = $caption;
        }

        return $this->wrapMessages([[
            'from' => $from, 'id' => $id ?? $this->newId(), 'timestamp' => (string) time(), 'type' => $type, $type => $media,
        ]], $from);
    }

    public function buttonReply(string $from, string $buttonId, string $title, ?string $id = null): array
    {
        return $this->wrapMessages([[
            'from' => $from, 'id' => $id ?? $this->newId(), 'timestamp' => (string) time(), 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttonId, 'title' => $title]],
        ]], $from);
    }

    public function status(string $recipient, string $waMessageId, string $status, array $extra = []): array
    {
        $s = ['id' => $waMessageId, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => $recipient] + $extra;

        return $this->wrap(['statuses' => [$s]]);
    }

    /** @param list<array> $messages */
    public function wrapMessages(array $messages, string $from): array
    {
        return $this->wrap([
            'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => $from]],
            'messages' => $messages,
        ]);
    }

    public function wrap(array $value): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA_ID',
            'changes' => [[
                'field' => 'messages',
                'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $this->phoneNumberId]] + $value,
            ]],
        ]]];
    }

    /** @return array{body: string, signature: string} */
    public function sign(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'signature' => 'sha256='.hash_hmac('sha256', $body, $this->appSecret)];
    }

    public function newId(): string
    {
        return 'wamid.'.strtoupper(bin2hex(random_bytes(12)));
    }
}

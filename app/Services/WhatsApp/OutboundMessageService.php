<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageStatus;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every outbound message goes through here. It:
 *  - records the message first (a failure is visible, never silent),
 *  - refuses free-form sends outside WhatsApp's customer-service window (only templates may be sent),
 *  - retries transient provider errors, and
 *  - is retry-safe: a repeated dedupe key never sends twice.
 */
class OutboundMessageService
{
    public const FAILED_OUTSIDE_WINDOW = 'outside_window_no_template';

    public function __construct(private readonly WhatsAppProvider $provider) {}

    public function sendText(User $user, string $body, string $dedupeKey, ?string $inReplyTo = null): WhatsappMessage
    {
        $chunks = $this->chunk($body, (int) config('whatsapp.outbound.max_text_length'));
        $first = null;

        foreach ($chunks as $i => $chunk) {
            $key = $i === 0 ? $dedupeKey : "{$dedupeKey}:part{$i}";
            $row = $this->deliver($user, Outbound::text($user->waId(), $chunk), $key, $inReplyTo);
            $first ??= $row;
        }

        return $first;
    }

    /** @param list<array{id: string, title: string}> $buttons */
    public function sendButtons(User $user, string $body, array $buttons, string $dedupeKey, ?string $inReplyTo = null): WhatsappMessage
    {
        return $this->deliver($user, Outbound::buttons($user->waId(), $body, $buttons), $dedupeKey, $inReplyTo);
    }

    /** Upload and send a local file as a document. Free-form, so the 24-hour window applies. */
    public function sendDocument(User $user, string $path, string $filename, ?string $caption, string $dedupeKey, ?string $inReplyTo = null): WhatsappMessage
    {
        return $this->deliver($user, Outbound::document($user->waId(), $path, $filename, $caption), $dedupeKey, $inReplyTo);
    }

    public function sendTemplate(User $user, string $name, string $dedupeKey, string $language = 'en', array $components = []): WhatsappMessage
    {
        return $this->deliver($user, Outbound::template($user->waId(), $name, $language, $components), $dedupeKey, null);
    }

    public function isWithinWindow(User $user): bool
    {
        if (! $this->provider->enforcesServiceWindow()) {
            return true; // Telegram has no customer-service window
        }

        return $user->last_inbound_at !== null
            && $user->last_inbound_at->greaterThan(now()->subHours((int) config('whatsapp.window_hours')));
    }

    private function deliver(User $user, Outbound $message, string $dedupeKey, ?string $inReplyTo): WhatsappMessage
    {
        $row = WhatsappMessage::firstOrCreate(['dedupe_key' => $dedupeKey], [
            'user_id' => $user->id,
            'in_reply_to' => $inReplyTo,
            'peer_bidx' => $user->wa_id_bidx,
            'direction' => 'out',
            'message_type' => match ($message->kind) {
                'text' => 'text', 'buttons' => 'interactive', 'document' => 'document', default => 'template',
            },
            'text' => $message->kind === 'document' ? $message->filename : ($message->body ?? $message->templateName),
            'payload' => $message->kind === 'buttons' ? ['buttons' => $message->buttons] : null,
            'status' => MessageStatus::Queued,
        ]);

        // Retry of a job that already sent this message: do nothing.
        if (in_array($row->status, [MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read], true)) {
            return $row;
        }

        if ($message->isFreeForm() && ! $this->isWithinWindow($user)) {
            return $this->fail($row, self::FAILED_OUTSIDE_WINDOW);
        }

        $attempts = max(1, (int) config('whatsapp.outbound.send_attempts'));
        $backoff = (array) config('whatsapp.outbound.retry_backoff_ms');

        for ($try = 1; $try <= $attempts; $try++) {
            try {
                $result = $this->provider->send($message);
                $row->update(['wa_message_id' => $result->waMessageId, 'status' => MessageStatus::Sent, 'sent_at' => now(), 'error' => null]);

                return $row;
            } catch (TransientSendException $e) {
                if ($try === $attempts) {
                    return $this->fail($row, 'transient: '.$this->scrub($e->getMessage()));
                }
                usleep(((int) ($backoff[$try - 1] ?? end($backoff) ?: 0)) * 1000);
            } catch (PermanentSendException $e) {
                return $this->fail($row, 'permanent: '.$this->scrub($e->getMessage()));
            }
        }

        return $row; // unreachable
    }

    /** Vendor error text can echo recipient details: keep the gist, drop anything that looks like a phone number. */
    private function scrub(string $text): string
    {
        return preg_replace('/\d{7,}/', '#', $text) ?? '';
    }

    private function fail(WhatsappMessage $row, string $reason): WhatsappMessage
    {
        $row->update(['status' => MessageStatus::Failed, 'error' => Str::limit($reason, 250, '')]);
        Log::warning('whatsapp.outbound_failed', ['message_id' => $row->id, 'reason' => Str::limit($reason, 120, '')]);

        return $row;
    }

    /** @return list<string> */
    private function chunk(string $body, int $max): array
    {
        if (mb_strlen($body, 'UTF-8') <= $max) {
            return [$body];
        }

        $chunks = [];
        $rest = $body;
        while (mb_strlen($rest, 'UTF-8') > $max) {
            $slice = mb_substr($rest, 0, $max, 'UTF-8');
            $cut = max((int) mb_strrpos($slice, "\n", 0, 'UTF-8'), (int) mb_strrpos($slice, ' ', 0, 'UTF-8'));
            $cut = $cut > $max / 2 ? $cut : $max;
            $chunks[] = rtrim(mb_substr($rest, 0, $cut, 'UTF-8'));
            $rest = ltrim(mb_substr($rest, $cut, null, 'UTF-8'));
        }
        $chunks[] = $rest;

        return array_values(array_filter($chunks, fn ($c) => $c !== ''));
    }
}

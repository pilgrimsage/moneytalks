<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageStatus;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DTO\InboundMessage;
use App\Services\WhatsApp\Handlers\InboundHandler;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Turns one verified inbound message into a stored row and, if the sender is allowed, hands it to
 * the handler. Idempotent: the same wa_message_id is never handled to completion twice.
 */
class MessageProcessor
{
    public function __construct(
        private readonly WhatsAppProvider $provider,
        private readonly InboundHandler $handler,
        private readonly OutboundMessageService $out,
    ) {}

    public function process(InboundMessage $m): void
    {
        $user = $this->resolveUser($m);

        $row = $this->store($m, keepContent: $user !== null);
        if ($row === null) {
            return; // already fully handled earlier (duplicate delivery)
        }

        if (! $user) {
            $row->update(['status' => MessageStatus::Ignored, 'error' => 'sender_not_allowed', 'processed_at' => now()]);
            Log::info('whatsapp.inbound_ignored', ['message_id' => $row->id, 'reason' => 'sender_not_allowed']);

            return;
        }

        $row->update(['user_id' => $user->id, 'status' => MessageStatus::Processing]);

        // Serialise this user's messages (webhook request, cron worker and reaper may overlap).
        Cache::lock("wa:user:{$user->id}", 120)->block(60, function () use ($user, $m, $row) {
            $this->touchWindow($user, $m);

            if (! RateLimiter::attempt("wa-msgs:{$user->id}", (int) config('whatsapp.rate_limit.user_messages_per_minute'), fn () => true, 60)) {
                $this->rateLimited($user, $m, $row);

                return;
            }

            $this->provider->markRead($m->waMessageId);

            try {
                $this->handler->handle($user, $m, $row);
            } catch (\Throwable $e) {
                $row->update(['status' => MessageStatus::ProcessingFailed, 'error' => get_class($e)]);
                throw $e;
            }

            $row->update(['status' => MessageStatus::Processed, 'processed_at' => now(), 'error' => null]);
        });
    }

    /**
     * Called once the event has exhausted its retries: the message is kept (processing_failed) and the
     * user is told, once, that it could not be processed. Nothing is silently dropped (docs/ai.md section 12).
     */
    public function giveUp(InboundMessage $m): void
    {
        $row = WhatsappMessage::where('wa_message_id', $m->waMessageId)->whereNotNull('user_id')->first();
        $user = $row?->user_id ? User::find($row->user_id) : null;
        if (! $user) {
            return;
        }

        $this->out->sendText($user, app(ReplyFormatter::class)->givingUp(), "giveup:{$m->waMessageId}", $m->waMessageId);
    }

    /** Insert the inbound row, or find the existing one. Returns null when it needs no further work. */
    private function store(InboundMessage $m, bool $keepContent): ?WhatsappMessage
    {
        try {
            return WhatsappMessage::create([
                'wa_message_id' => $m->waMessageId,
                'peer_bidx' => $m->from !== '' ? User::blindIndex($m->from) : null,
                'direction' => 'in',
                'message_type' => $m->type,
                // Data minimisation: strangers' message content is never stored, only that it arrived.
                'text' => $keepContent ? $m->text : null,
                'payload' => $keepContent ? $m->raw : null,
                'status' => MessageStatus::Received,
                'meta_timestamp' => $m->timestamp ?: null,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = WhatsappMessage::where('wa_message_id', $m->waMessageId)->first();
            if (! $existing || in_array($existing->status, [MessageStatus::Processed, MessageStatus::Ignored], true)) {
                Log::info('whatsapp.duplicate_message', ['wa_message_id' => $m->waMessageId]);

                return null;
            }

            // Received/processing/failed earlier (crash or retry): safe to run again, downstream is idempotent.
            return $existing;
        }
    }

    /** Personal mode, fail closed: only a provisioned, active user on the allow-list may talk to the bot. */
    private function resolveUser(InboundMessage $m): ?User
    {
        $allowed = config('moneytalks.allowed_wa_ids');
        if ($m->from === '' || $allowed === [] || ! in_array($m->from, $allowed, true)) {
            return null;
        }

        $user = User::findByWaId($m->from);

        return $user && $user->status === 'active' ? $user : null;
    }

    private function touchWindow(User $user, InboundMessage $m): void
    {
        $sentAt = $m->timestamp > 0 ? CarbonImmutable::createFromTimestampUTC($m->timestamp) : now();
        if ($sentAt->isFuture()) {
            $sentAt = now();
        }
        if ($user->last_inbound_at === null || $sentAt->greaterThan($user->last_inbound_at)) {
            $user->update(['last_inbound_at' => $sentAt]);
        }
    }

    /** One polite notice per window, then silence (replying to spam costs money). */
    private function rateLimited(User $user, InboundMessage $m, WhatsappMessage $row): void
    {
        $row->update(['status' => MessageStatus::Ignored, 'error' => 'rate_limited', 'processed_at' => now()]);

        if (RateLimiter::attempt("wa-msgs-notice:{$user->id}", 1, fn () => true, 300)) {
            $this->out->sendText($user, 'You are sending messages very quickly. Please wait a minute and try again.', "ratelimit:{$m->waMessageId}", $m->waMessageId);
        }
    }
}

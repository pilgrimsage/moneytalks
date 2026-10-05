<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WhatsApp\MessageProcessor;
use App\Services\WhatsApp\StatusUpdater;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Handles one stored webhook delivery. Safe to run any number of times concurrently: it atomically
 * claims the event, and everything downstream is idempotent. Retries are driven by the scheduled
 * reaper (see ReapWebhookEvents), not by the queue, so one mechanism owns the retry policy.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $eventId) {}

    public function handle(WhatsAppProvider $provider, MessageProcessor $processor, StatusUpdater $statuses): void
    {
        Context::add('correlation_id', (string) Str::uuid());
        Context::add('webhook_event_id', $this->eventId);

        if (! $this->claim()) {
            return; // someone else is processing it, or it is done
        }

        $event = WebhookEvent::findOrFail($this->eventId);
        $failures = [];
        $failedMessages = [];

        try {
            $parsed = $provider->parseWebhook(json_decode($event->payload, true) ?: []);

            foreach ($parsed->statuses as $status) {
                $statuses->apply($status);
            }

            foreach ($parsed->messages as $message) {
                try {
                    $processor->process($message);
                } catch (Throwable $e) {
                    // Keep going: one bad message must not block the rest of the delivery.
                    $failures[] = get_class($e); // never persist exception text: it can embed message content or numbers
                    $failedMessages[] = $message;
                    Log::error('whatsapp.message_failed', ['wa_message_id' => $message->waMessageId, 'error' => get_class($e)]);
                }
            }

            if ($parsed->messages === [] && $parsed->statuses === [] && ! $parsed->forThisNumber) {
                $event->update(['status' => 'ignored', 'processed_at' => now(), 'error' => 'not_for_this_number']);

                return;
            }
        } catch (Throwable $e) {
            $failures[] = get_class($e); // never persist exception text: it can embed message content or numbers
        }

        if ($failures === []) {
            $event->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        } else {
            $event->update(['status' => 'failed', 'error' => Str::limit(implode(' | ', $failures), 1000, '')]);

            // Out of retries: keep the message, tell the user once instead of staying silent.
            if ($event->fresh()->attempts >= (int) config('whatsapp.webhook.max_attempts')) {
                foreach ($failedMessages as $failed) {
                    try {
                        $processor->giveUp($failed);
                    } catch (Throwable $e) {
                        Log::error('whatsapp.give_up_failed', ['error' => get_class($e)]);
                    }
                }
            }
            Log::warning('whatsapp.event_failed', ['event_id' => $event->id, 'failures' => count($failures)]);
        }
    }

    /** Atomic claim: only one worker may move the event into `processing`. */
    private function claim(): bool
    {
        return DB::table('webhook_events')
            ->where('id', $this->eventId)
            ->whereIn('status', ['received', 'failed'])
            ->where('attempts', '<', (int) config('whatsapp.webhook.max_attempts'))
            ->update([
                'status' => 'processing',
                'processing_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]) === 1;
    }
}

<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for lost work: re-drives webhook events that were never finished (process crashed,
 * after-response job killed by the host, transient failure). Run every minute by the scheduler.
 */
class ReapWebhookEvents
{
    /** @return array{reset: int, redriven: int, exhausted: int} */
    public function __invoke(): array
    {
        $cfg = config('whatsapp.webhook');

        // A worker that died mid-processing leaves the event in `processing`: hand it back.
        $reset = DB::table('webhook_events')
            ->where('status', 'processing')
            ->where('processing_started_at', '<', now()->subSeconds($cfg['processing_timeout_seconds']))
            ->update(['status' => 'failed', 'error' => 'processing_timeout']);

        $redriven = 0;
        WebhookEvent::whereIn('status', ['received', 'failed'])
            ->where('attempts', '<', $cfg['max_attempts'])
            ->orderBy('id')->limit(100)->get()
            ->each(function (WebhookEvent $e) use ($cfg, &$redriven) {
                // Back off linearly with the number of attempts so a poison event cannot spin.
                $last = $e->processing_started_at ?? $e->received_at;
                if ($last->greaterThan(now()->subSeconds($cfg['reap_after_seconds'] * max(1, $e->attempts)))) {
                    return;
                }
                ProcessWebhookEvent::dispatch($e->id);
                $redriven++;
            });

        $exhausted = WebhookEvent::where('status', 'failed')->where('attempts', '>=', $cfg['max_attempts'])->count();
        if ($exhausted > 0) {
            Log::critical('whatsapp.events_exhausted', ['count' => $exhausted]);
        }

        return ['reset' => $reset, 'redriven' => $redriven, 'exhausted' => $exhausted];
    }
}

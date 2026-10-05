<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The webhook does exactly four things: authenticate, persist, enqueue, answer 200.
 * No AI, no ledger, no outbound calls here (docs/whatsapp.md).
 */
class WhatsAppWebhookController extends Controller
{
    /** Meta's subscription handshake. */
    public function verify(Request $request, WhatsAppProvider $provider): Response
    {
        $challenge = $provider->verifyChallenge($request->query());

        return $challenge === null
            ? response('Forbidden', 403)
            : response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppProvider $provider): Response
    {
        $max = (int) config('whatsapp.webhook.max_body_bytes');
        if ((int) $request->header('Content-Length', '0') > $max) {
            return response('Payload too large', 413);
        }

        $raw = $request->getContent();
        if (strlen($raw) > $max) {
            return response('Payload too large', 413);
        }

        // Authenticity is checked on the raw bytes, before the body is parsed or trusted.
        if (! $provider->verifySignature($raw, $request->header('X-Hub-Signature-256'))) {
            Log::warning('whatsapp.webhook.bad_signature', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Bad request', 400);
        }

        try {
            $event = WebhookEvent::create([
                'provider' => config('whatsapp.provider'),
                'event_hash' => hash('sha256', $raw),
                'payload' => $raw,
                'status' => 'received',
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::info('whatsapp.webhook.duplicate_delivery');

            return response('OK', 200); // Meta redelivered the identical payload: already stored
        }

        if (config('whatsapp.webhook.process_after_response')) {
            ProcessWebhookEvent::dispatchAfterResponse($event->id);
        } else {
            ProcessWebhookEvent::dispatch($event->id);
        }

        return response('OK', 200);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\Testing\MetaPayloadFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateWhatsApp extends Command
{
    protected $signature = 'moneytalks:whatsapp:simulate
        {text? : Message text (omit for --handshake)}
        {--from= : Sender WhatsApp id (default: first ALLOWED_WA_IDS entry)}
        {--kind=text : text | audio | image | button | status}
        {--url= : Webhook URL (default: APP_URL/webhooks/whatsapp)}
        {--id= : Fixed message id (use the same id twice to test duplicate handling)}
        {--bad-signature : Send a deliberately wrong signature}
        {--handshake : Test the GET verification handshake instead}';

    protected $description = 'Send a Meta-format, correctly signed webhook to this app (no phone needed)';

    public function handle(): int
    {
        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/whatsapp';

        if ($this->option('handshake')) {
            $r = Http::get($url, ['hub.mode' => 'subscribe', 'hub.verify_token' => config('whatsapp.meta.verify_token'), 'hub.challenge' => '12345']);
            $this->line("HTTP {$r->status()}: ".$r->body());

            return $r->body() === '12345' ? self::SUCCESS : self::FAILURE;
        }

        $secret = (string) config('whatsapp.meta.app_secret');
        $phoneId = (string) config('whatsapp.meta.phone_number_id');
        if ($secret === '' || $phoneId === '') {
            $this->error('Set META_APP_SECRET and META_PHONE_NUMBER_ID first (any values work with WHATSAPP_PROVIDER=fake).');

            return self::FAILURE;
        }

        $from = preg_replace('/\D+/', '', (string) ($this->option('from') ?: (config('moneytalks.allowed_wa_ids')[0] ?? '')));
        if ($from === '') {
            $this->error('No sender: pass --from or set the allow-list (ALLOWED_TELEGRAM_IDS / ALLOWED_WA_IDS).');

            return self::FAILURE;
        }

        $factory = new MetaPayloadFactory($phoneId, $secret);
        $id = $this->option('id') ?: null;
        $text = (string) $this->argument('text');

        $payload = match ($this->option('kind')) {
            'audio', 'image' => $factory->media($from, $this->option('kind'), 'MEDIA_ID_1', $id, $text ?: null),
            'button' => $factory->buttonReply($from, $text ?: 'confirm', ucfirst($text ?: 'confirm'), $id),
            'status' => $factory->status($from, $id ?? 'wamid.UNKNOWN', $text ?: 'delivered'),
            default => $factory->text($from, $text, $id),
        };

        $signed = $factory->sign($payload);
        $signature = $this->option('bad-signature') ? 'sha256='.str_repeat('0', 64) : $signed['signature'];

        $r = Http::withBody($signed['body'], 'application/json')->withHeaders(['X-Hub-Signature-256' => $signature])->post($url);
        $this->line("HTTP {$r->status()}: ".$r->body());

        return $r->successful() ? self::SUCCESS : self::FAILURE;
    }
}

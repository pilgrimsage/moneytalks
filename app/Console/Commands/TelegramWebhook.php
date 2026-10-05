<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use App\Services\WhatsApp\TelegramProvider;
use Illuminate\Console\Command;

class TelegramWebhook extends Command
{
    protected $signature = 'moneytalks:telegram:webhook {action=info : info | set | delete} {--url= : Public webhook URL (default APP_URL/webhooks/telegram)}';

    protected $description = 'Check the Telegram bot and register, inspect or remove its webhook (never prints the bot token)';

    public function handle(TelegramProvider $telegram): int
    {
        try {
            $me = $telegram->call('getMe');
            $this->info('Bot: @'.($me['username'] ?? '?').' ('.($me['first_name'] ?? '').')');

            match ($this->argument('action')) {
                'set' => $this->set($telegram),
                'delete' => $this->delete($telegram),
                'info' => $this->info_($telegram),
                default => throw new \InvalidArgumentException('Action must be info, set or delete.'),
            };
        } catch (TransientSendException|PermanentSendException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function set(TelegramProvider $telegram): void
    {
        $secret = (string) config('whatsapp.telegram.webhook_secret');
        if (! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            throw new \InvalidArgumentException('Set TELEGRAM_WEBHOOK_SECRET first: 16 to 256 characters, letters, digits, _ and - only (for example: php -r "echo bin2hex(random_bytes(32));").');
        }

        $url = (string) ($this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/telegram');
        if (! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Telegram only delivers to https URLs. Got: '.$url);
        }

        $telegram->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'max_connections' => 10]);
        $this->info("Webhook set to {$url}");
    }

    private function delete(TelegramProvider $telegram): void
    {
        $telegram->call('deleteWebhook');
        $this->info('Webhook removed.');
    }

    private function info_(TelegramProvider $telegram): void
    {
        $i = $telegram->call('getWebhookInfo');
        $this->table(['Field', 'Value'], [
            ['url', ($i['url'] ?? '') !== '' ? $i['url'] : '(not set)'],
            ['pending updates', $i['pending_update_count'] ?? 0],
            ['last error', ($i['last_error_message'] ?? '') !== '' ? $i['last_error_message'].' ('.date('Y-m-d H:i', (int) ($i['last_error_date'] ?? 0)).')' : 'none'],
        ]);
    }
}

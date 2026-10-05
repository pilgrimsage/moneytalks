<?php

namespace App\Console\Commands;

use App\Models\WhatsappMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ShowOutbox extends Command
{
    protected $signature = 'moneytalks:whatsapp:messages {--limit=10}';

    protected $description = 'Show recent WhatsApp messages (decrypted) with their delivery status. Contains personal data.';

    public function handle(): int
    {
        $rows = WhatsappMessage::orderByDesc('created_at')->limit((int) $this->option('limit'))->get()->reverse();

        $this->table(['When', 'Dir', 'Type', 'Status', 'Billing', 'Text / error'], $rows->map(fn ($m) => [
            $m->created_at->format('m-d H:i:s'),
            $m->direction,
            $m->message_type,
            $m->status->value,
            $m->pricing_category ? $m->pricing_category.($m->billable ? ' $' : '') : '-',
            Str::limit(($m->text ?? '').($m->error ? "  [{$m->error}]" : ''), 70),
        ])->all());

        return self::SUCCESS;
    }
}

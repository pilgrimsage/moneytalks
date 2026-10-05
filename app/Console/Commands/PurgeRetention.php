<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PurgeRetention extends Command
{
    protected $signature = 'moneytalks:retention:purge {--webhook-days=14} {--message-days=90} {--export-hours=24}';

    protected $description = 'Retention (docs/security.md): clear old raw webhook payloads and message bodies, delete leftover export files';

    public function handle(): int
    {
        $webhook = DB::table('webhook_events')->where('received_at', '<', now()->subDays((int) $this->option('webhook-days')))
            ->whereNull('payload_cleared_at')->update(['payload' => Crypt::encryptString('{}'), 'payload_cleared_at' => now()]);

        // Update through the query builder: the Eloquent model (and its casts) are deliberately not involved.
        $messages = DB::table('whatsapp_messages')->where('created_at', '<', now()->subDays((int) $this->option('message-days')))
            ->where(fn ($q) => $q->whereNotNull('text')->orWhereNotNull('payload'))->update(['text' => null, 'payload' => null]);

        $files = 0;
        $dir = storage_path('app/private/exports');
        if (is_dir($dir)) {
            foreach (File::files($dir) as $f) {
                if ($f->getMTime() < now()->subHours((int) $this->option('export-hours'))->getTimestamp()) {
                    $files += @unlink($f->getPathname()) ? 1 : 0;
                }
            }
        }

        $this->info("Cleared {$webhook} webhook payload(s), {$messages} message body(ies), deleted {$files} old export file(s).");

        return self::SUCCESS;
    }
}

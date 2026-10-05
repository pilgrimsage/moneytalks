<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeAiBodies extends Command
{
    protected $signature = 'moneytalks:ai:purge {--days= : Override AI_RETENTION_DAYS}';

    protected $description = 'Erase stored AI prompt/response bodies older than the retention period (token and cost metadata is kept)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('ai.retention_days'));
        $cutoff = now()->subDays($days);

        // Update through the query builder: the Eloquent model is deliberately not involved.
        $n = DB::table('ai_requests')->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('input')->orWhereNotNull('output'))
            ->update(['input' => null, 'output' => null]);

        $this->info("Purged bodies of {$n} AI request(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}

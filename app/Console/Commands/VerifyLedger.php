<?php

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerVerifier;
use App\Services\Ops\HealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VerifyLedger extends Command
{
    protected $signature = 'moneytalks:ledger:verify {--user= : Limit to one user id}';

    protected $description = 'Re-check ledger integrity (balances, header totals, entry hashes, reversal links)';

    public function handle(LedgerVerifier $verifier): int
    {
        $issues = $verifier->verify($this->option('user'));
        if (! $this->option('user')) {
            Cache::put(HealthCheck::LEDGER_KEY, ['ok' => $issues === [], 'at' => now()->timestamp], now()->addDays(3));
        }

        if ($issues === []) {
            $this->info('Ledger OK.');

            return self::SUCCESS;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
        Log::critical('Ledger verification failed', ['issues' => array_slice($issues, 0, 20), 'count' => count($issues)]);

        return self::FAILURE;
    }
}

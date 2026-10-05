<?php

namespace App\Console\Commands;

use App\Services\Ops\HealthCheck;
use Illuminate\Console\Command;

class ShowHealth extends Command
{
    protected $signature = 'moneytalks:health';

    protected $description = 'Check scheduler, queue, webhooks, ledger verification, AI and outbound messages (exit 1 if a critical check fails)';

    public function handle(HealthCheck $health): int
    {
        $checks = $health->run();
        $this->table(['Check', 'Status', 'Detail'], array_map(fn ($c) => [$c['name'], $c['ok'] ? 'OK' : ($c['critical'] ? 'FAIL' : 'WARN'), $c['detail']], $checks));

        return $health->healthy($checks) ? self::SUCCESS : self::FAILURE;
    }
}

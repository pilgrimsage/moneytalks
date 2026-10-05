<?php

namespace App\Console\Commands;

use App\Services\Closing\MonthlyClosing;
use Illuminate\Console\Command;

class RunMonthlyClosing extends Command
{
    protected $signature = 'moneytalks:monthly:close';

    protected $description = "Send each user last month's summary (first week of the month, once)";

    public function handle(MonthlyClosing $closing): int
    {
        $this->info('Summaries sent: '.$closing->run());

        return self::SUCCESS;
    }
}

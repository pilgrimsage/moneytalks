<?php

namespace App\Console\Commands;

use App\Services\Recurring\RecurringService;
use Illuminate\Console\Command;

class RunRecurring extends Command
{
    protected $signature = 'moneytalks:recurring:run';

    protected $description = 'Open occurrences for recurring payments that are due and send the Paid/Skip reminders';

    public function handle(RecurringService $recurring): int
    {
        $this->info('Reminders sent: '.$recurring->tick());

        return self::SUCCESS;
    }
}

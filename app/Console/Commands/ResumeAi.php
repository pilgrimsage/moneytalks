<?php

namespace App\Console\Commands;

use App\Services\Ops\AiSwitch;
use Illuminate\Console\Command;

class ResumeAi extends Command
{
    protected $signature = 'moneytalks:ai:resume';

    protected $description = 'Switch AI back on after moneytalks:ai:pause';

    public function handle(AiSwitch $switch): int
    {
        $switch->resume();
        $this->info($switch->overBudget() ? 'AI resumed, but today\'s global budget is already used up; it stays blocked until tomorrow or the budget is raised.' : 'AI resumed.');

        return self::SUCCESS;
    }
}

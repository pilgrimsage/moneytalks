<?php

namespace App\Console\Commands;

use App\Services\Ops\AiSwitch;
use Illuminate\Console\Command;

class PauseAi extends Command
{
    protected $signature = 'moneytalks:ai:pause {reason=manual : Why (shown in health output)}';

    protected $description = 'Kill switch: stop all model, vision and transcription calls (reports, balance and undo keep working)';

    public function handle(AiSwitch $switch): int
    {
        $switch->pause((string) $this->argument('reason'));
        $this->warn('AI is paused. Run moneytalks:ai:resume to switch it back on.');

        return self::SUCCESS;
    }
}

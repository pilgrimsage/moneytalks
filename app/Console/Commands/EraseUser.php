<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Privacy\PrivacyService;
use Illuminate\Console\Command;

class EraseUser extends Command
{
    protected $signature = 'moneytalks:user:erase {wa_id : the user\'s WhatsApp number} {--force : do not ask for confirmation}';

    protected $description = 'Erase a user\'s personal data (anonymises the ledger, removes messages, names and the number); cannot be undone';

    public function handle(PrivacyService $privacy): int
    {
        $user = User::findByWaId((string) $this->argument('wa_id'));
        if (! $user || $user->status === 'deleted') {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $this->warn('This removes messages, names, the number and ledger descriptions for this user. Amounts and dates stay (anonymous). It cannot be undone.');
        if (! $this->option('force') && $this->ask('Type ERASE to continue') !== 'ERASE') {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        foreach ($privacy->erase($user) as $what => $n) {
            $this->line(sprintf('  %-34s %d', $what, $n));
        }
        $this->info('Done. Export first next time if you want a copy (moneytalks:user:export).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupCrypto;
use Illuminate\Console\Command;

class BackupKey extends Command
{
    protected $signature = 'moneytalks:backup:key';

    protected $description = 'Print a new random backup encryption key (put it in BACKUP_ENCRYPTION_KEY and store a copy off the server)';

    public function handle(): int
    {
        $this->line(BackupCrypto::generateKey());
        $this->warn('Keep a copy somewhere safe and OFF this server. Backups cannot be read without it.');

        return self::SUCCESS;
    }
}

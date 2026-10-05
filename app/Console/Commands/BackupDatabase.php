<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'moneytalks:backup {--keep= : How many backups to keep}';

    protected $description = 'Encrypted database backup (mysqldump + gzip + authenticated encryption) into storage/app/private/backups';

    public function handle(BackupService $backups): int
    {
        $key = (string) config('backup.encryption_key');
        if ($key === '') {
            $this->warn('BACKUP_ENCRYPTION_KEY is not set, so no backup was made. Create one with: php artisan moneytalks:backup:key');
            Log::warning('backup.skipped_no_key');

            return self::SUCCESS;
        }

        try {
            $file = $backups->create($key, (int) ($this->option('keep') ?: config('backup.keep')));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::critical('backup.failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return self::FAILURE;
        }

        $this->info('Backup written: '.$file.' ('.number_format(filesize($file) / 1024, 1).' KiB). Copy it off the server, and test it with moneytalks:backup:verify.');

        return self::SUCCESS;
    }
}

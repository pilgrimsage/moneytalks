<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RestoreBackup extends Command
{
    protected $signature = 'moneytalks:backup:restore {file? : backup file (default: the newest)} {--into= : name of an EXISTING EMPTY scratch database (never the live one)}';

    protected $description = 'Restore drill: load a backup into an empty scratch database and run the ledger verifier on it';

    public function handle(BackupService $backups): int
    {
        $key = (string) config('backup.encryption_key');
        $file = $this->argument('file') ?: ($backups->list()[0] ?? null);
        $into = (string) $this->option('into');
        if ($key === '' || ! $file || $into === '') {
            $this->error('Needs BACKUP_ENCRYPTION_KEY, a backup file and --into=<scratch database>.');

            return self::FAILURE;
        }

        try {
            $issues = $backups->restoreInto($file, $key, $into);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
        $issues === [] ? $this->info("Restored into {$into}; the ledger verifies. This backup is proven. Drop the scratch database now.") : $this->error('The restored ledger has problems: do not rely on this backup.');

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}

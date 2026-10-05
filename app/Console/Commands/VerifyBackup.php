<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class VerifyBackup extends Command
{
    protected $signature = 'moneytalks:backup:verify {file? : backup file (default: the newest)}';

    protected $description = 'Decrypt a backup and check it is a complete dump; with --into restore it into an empty scratch database and run the ledger verifier (the restore drill)';

    public function handle(BackupService $backups): int
    {
        $key = (string) config('backup.encryption_key');
        $file = $this->argument('file') ?: ($backups->list()[0] ?? null);
        if ($key === '' || ! $file) {
            $this->error('Needs BACKUP_ENCRYPTION_KEY and a backup file.');

            return self::FAILURE;
        }

        try {
            $r = $backups->verify($file, $key);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ok = $r['complete'] && $r['has_ledger'] && $r['tables'] > 0;
        $this->line(sprintf('%s: %d tables, %s MiB of SQL, %s, ledger tables %s.', basename($file), $r['tables'], number_format($r['bytes'] / 1048576, 2),
            $r['complete'] ? 'dump complete' : 'DUMP INCOMPLETE', $r['has_ledger'] ? 'present' : 'MISSING'));
        $ok ? $this->info('Backup looks good. Prove it with a restore drill: moneytalks:backup:restore --into=<empty scratch db>') : $this->error('This backup is NOT usable.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}

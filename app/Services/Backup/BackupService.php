<?php

namespace App\Services\Backup;

use App\Domain\Ledger\LedgerVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Encrypted database backups (docs/deployment.md section 6): mysqldump -> gzip -> authenticated encryption, written to
 * storage/app/private/backups (mode 0600), oldest pruned. The password reaches mysqldump through the environment, never
 * the command line. A backup is only trusted after `verify`, and only proven by a restore drill (`restore --into=SCRATCH`)
 * that ends with the ledger verifier.
 */
class BackupService
{
    public const LAST_KEY = 'ops.backup.last';

    public function dir(): string
    {
        return storage_path('app/private/backups');
    }

    /** @return string path of the new backup */
    public function create(string $hexKey, int $keep = 14): string
    {
        File::ensureDirectoryExists($this->dir(), 0700);
        $stamp = now()->format('Ymd-His');
        $plain = $this->dir()."/.dump-{$stamp}.sql.gz";
        $final = $this->dir()."/moneytalks-{$stamp}.sql.gz.enc";

        try {
            $gz = gzopen($plain, 'wb6');
            chmod($plain, 0600);
            $process = new Process($this->dumpCommand(), null, ['MYSQL_PWD' => (string) config('database.connections.mysql.password')], null, 3600);
            $process->run(function ($type, $buffer) use ($gz) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
            gzclose($gz);
            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysqldump failed: '.trim(mb_substr($process->getErrorOutput(), 0, 300)));
            }

            $in = fopen($plain, 'rb');
            $out = fopen($final, 'wb');
            chmod($final, 0600);
            BackupCrypto::encrypt($in, $out, $hexKey);
            fclose($in);
            fclose($out);
        } catch (\Throwable $e) {
            @unlink($final);
            throw $e;
        } finally {
            @unlink($plain); // never leave an unencrypted dump behind
        }

        $this->prune($keep);
        Cache::forever(self::LAST_KEY, now());

        return $final;
    }

    /**
     * Decrypt and unzip into a temp file, check it is a complete dump.
     *
     * @return array{bytes: int, tables: int, complete: bool, has_ledger: bool}
     */
    public function verify(string $file, string $hexKey): array
    {
        $sql = $this->decryptToSql($file, $hexKey);
        try {
            $text = fopen($sql, 'rb');
            $tables = 0;
            $complete = false;
            $ledger = false;
            $bytes = 0;
            while (($line = fgets($text)) !== false) {
                $bytes += strlen($line);
                $tables += str_starts_with($line, 'CREATE TABLE') ? 1 : 0;
                $ledger = $ledger || str_contains($line, 'CREATE TABLE `ledger_transactions`');
                $complete = str_starts_with($line, '-- Dump completed');
            }
            fclose($text);

            return ['bytes' => $bytes, 'tables' => $tables, 'complete' => $complete, 'has_ledger' => $ledger];
        } finally {
            @unlink($sql);
        }
    }

    /**
     * The restore drill: load the backup into an EXISTING EMPTY scratch database and run the ledger verifier against it.
     *
     * @return list<string> ledger problems found in the restored copy (empty = the backup is good)
     */
    public function restoreInto(string $file, string $hexKey, string $scratchDb): array
    {
        $live = (string) config('database.connections.mysql.database');
        if ($scratchDb === $live) {
            throw new RuntimeException('Refusing to restore over the live database.');
        }
        $exists = DB::select('select count(*) c from information_schema.schemata where schema_name = ?', [$scratchDb]);
        if ((int) $exists[0]->c !== 1) {
            throw new RuntimeException("Scratch database {$scratchDb} does not exist; create it empty first.");
        }
        if ((int) DB::select('select count(*) c from information_schema.tables where table_schema = ?', [$scratchDb])[0]->c !== 0) {
            throw new RuntimeException("Scratch database {$scratchDb} is not empty.");
        }

        $sql = $this->decryptToSql($file, $hexKey);
        try {
            $input = new InputStream;
            $process = new Process($this->clientCommand($scratchDb), null, ['MYSQL_PWD' => (string) config('database.connections.mysql.password')], $input, 3600);
            $process->start();
            $h = fopen($sql, 'rb');
            while (! feof($h)) {
                $input->write((string) fread($h, 1_048_576));
            }
            fclose($h);
            $input->close();
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Restore failed: '.trim(mb_substr($process->getErrorOutput(), 0, 300)));
            }
        } finally {
            @unlink($sql);
        }

        // Point the app at the restored copy just long enough to verify it.
        $original = config('database.connections.mysql.database');
        try {
            config(['database.connections.mysql.database' => $scratchDb]);
            DB::purge('mysql');

            return app(LedgerVerifier::class)->verify();
        } finally {
            config(['database.connections.mysql.database' => $original]);
            DB::purge('mysql');
        }
    }

    /** @return list<string> backup files, newest first */
    public function list(): array
    {
        $files = glob($this->dir().'/moneytalks-*.sql.gz.enc') ?: [];
        rsort($files);

        return $files;
    }

    private function prune(int $keep): void
    {
        foreach (array_slice($this->list(), max(1, $keep)) as $old) {
            @unlink($old);
        }
    }

    private function decryptToSql(string $file, string $hexKey): string
    {
        if (! is_file($file)) {
            throw new RuntimeException('No such backup file.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mtbk');
        chmod($tmp, 0600);
        $in = fopen($file, 'rb');
        // decrypt to a temp .gz first, then inflate to the temp SQL file; both are removed by the caller / finally.
        $gzTmp = tempnam(sys_get_temp_dir(), 'mtbk');
        chmod($gzTmp, 0600);
        try {
            $out = fopen($gzTmp, 'wb');
            BackupCrypto::decrypt($in, $out, $hexKey);
            fclose($in);
            fclose($out);

            $src = gzopen($gzTmp, 'rb');
            $dst = fopen($tmp, 'wb');
            while (! gzeof($src)) {
                fwrite($dst, (string) gzread($src, 1_048_576));
            }
            gzclose($src);
            fclose($dst);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            @unlink($gzTmp);
        }

        return $tmp;
    }

    /** @return list<string> */
    private function dumpCommand(): array
    {
        $c = (array) config('database.connections.mysql');
        $args = [(string) config('backup.mysqldump_binary'), '-h', (string) $c['host'], '-P', (string) $c['port'], '-u', (string) $c['username'],
            '--single-transaction', '--quick', '--routines', '--no-tablespaces', '--default-character-set=utf8mb4'];
        if ($this->dumpSupportsColumnStatistics()) {
            $args[] = '--column-statistics=0'; // MySQL 8 client talking to MariaDB/older servers
        }
        $args[] = (string) $c['database'];

        return $args;
    }

    /** Uses proc_open (Symfony Process), not shell_exec: shared hosts such as Hostinger disable shell_exec/exec/popen. */
    private function dumpSupportsColumnStatistics(): bool
    {
        $help = new Process([(string) config('backup.mysqldump_binary'), '--help'], null, null, null, 30);
        $help->run();

        return str_contains($help->getOutput(), 'column-statistics');
    }

    /** @return list<string> */
    private function clientCommand(string $db): array
    {
        $c = (array) config('database.connections.mysql');

        return [(string) config('backup.mysql_binary'), '-h', (string) $c['host'], '-P', (string) $c['port'], '-u', (string) $c['username'], '--default-character-set=utf8mb4', $db];
    }
}

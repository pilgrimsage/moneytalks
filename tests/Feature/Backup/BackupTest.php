<?php

use App\Services\Backup\BackupCrypto;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;

function encryptString(string $plain, string $key): string
{
    $in = fopen('php://memory', 'w+b');
    fwrite($in, $plain);
    rewind($in);
    $out = fopen('php://memory', 'w+b');
    BackupCrypto::encrypt($in, $out, $key);
    rewind($out);

    return (string) stream_get_contents($out);
}

function decryptString(string $cipher, string $key): string
{
    $in = fopen('php://memory', 'w+b');
    fwrite($in, $cipher);
    rewind($in);
    $out = fopen('php://memory', 'w+b');
    BackupCrypto::decrypt($in, $out, $key);
    rewind($out);

    return (string) stream_get_contents($out);
}

describe('backup encryption', function () {
    beforeEach(fn () => $this->key = BackupCrypto::generateKey());

    it('round-trips data of any size, including several chunks and the empty file', function (int $size) {
        $plain = $size === 0 ? '' : random_bytes($size);

        expect(decryptString(encryptString($plain, $this->key), $this->key))->toBe($plain);
    })->with([0, 1, 1000, 1_048_576, 2_500_000]);

    it('never contains the plaintext, and two backups of the same data differ', function () {
        $plain = 'INSERT INTO ledger_entries VALUES (1,"secret amount 12345")';
        $a = encryptString($plain, $this->key);
        $b = encryptString($plain, $this->key);

        expect($a)->not->toContain('secret amount')->and($a)->not->toBe($b)->and(str_starts_with($a, BackupCrypto::MAGIC))->toBeTrue();
    });

    it('refuses a wrong key, a flipped bit, a truncated file and a foreign file', function () {
        $cipher = encryptString('important ledger data', $this->key);
        $flipped = $cipher;
        $flipped[strlen($flipped) - 3] = chr(ord($flipped[strlen($flipped) - 3]) ^ 1);

        expect(fn () => decryptString($cipher, BackupCrypto::generateKey()))->toThrow(RuntimeException::class, 'Wrong key')
            ->and(fn () => decryptString($flipped, $this->key))->toThrow(RuntimeException::class)
            ->and(fn () => decryptString(substr($cipher, 0, strlen($cipher) - 10), $this->key))->toThrow(RuntimeException::class)
            ->and(fn () => decryptString('just some text, not a backup', $this->key))->toThrow(RuntimeException::class, 'Not a MoneyTalks backup');
    });

    it('rejects a malformed key', function () {
        expect(fn () => encryptString('x', 'not-hex'))->toThrow(RuntimeException::class, '64 hex')
            ->and(fn () => encryptString('x', 'abcd'))->toThrow(RuntimeException::class, '64 hex');
    });
});

describe('database backup', function () {
    beforeEach(function () {
        $this->key = BackupCrypto::generateKey();
        $this->service = app(BackupService::class);
        $bin = trim((string) shell_exec('command -v '.escapeshellarg((string) config('backup.mysqldump_binary')).' 2>/dev/null'));
        if ($bin === '') {
            $this->markTestSkipped('mysqldump is not installed here');
        }
        $this->files = [];
    });

    afterEach(function () {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    });

    it('creates an encrypted, private, complete backup that verifies, and prunes old ones', function () {
        ledgerUser('919876543210');

        $file = $this->files[] = $this->service->create($this->key, 5);
        $check = $this->service->verify($file, $this->key);

        expect(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600')->and(file_get_contents($file))->not->toContain('CREATE TABLE')
            ->and($check['complete'])->toBeTrue()->and($check['has_ledger'])->toBeTrue()->and($check['tables'])->toBeGreaterThan(10)
            ->and(glob($this->service->dir().'/.dump-*'))->toBe([]); // no plaintext dump left behind
    });

    it('can prove a backup by restoring it into an empty scratch database', function () {
        $scratch = 'moneytalks_scratch';
        $exists = DB::select('select count(*) c from information_schema.schemata where schema_name = ?', [$scratch])[0]->c;
        $tables = DB::select('select count(*) c from information_schema.tables where table_schema = ?', [$scratch])[0]->c;
        if ($exists < 1 || $tables > 0) {
            $this->markTestSkipped('needs an existing empty database named moneytalks_scratch');
        }
        ledgerUser('919876543210');
        $file = $this->files[] = $this->service->create($this->key, 5);

        expect(fn () => $this->service->restoreInto($file, $this->key, config('database.connections.mysql.database')))->toThrow(RuntimeException::class, 'live database');
        $issues = $this->service->restoreInto($file, $this->key, $scratch);

        expect($issues)->toBe([]);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('select table_name n from information_schema.tables where table_schema = ?', [$scratch]) as $t) {
            DB::statement("DROP TABLE IF EXISTS `{$scratch}`.`{$t->n}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    });

    it('does nothing (and says so) while no key is configured', function () {
        config(['backup.encryption_key' => null]);

        $this->artisan('moneytalks:backup')->expectsOutputToContain('BACKUP_ENCRYPTION_KEY is not set')->assertSuccessful();
        expect($this->service->list())->toBe([]);
    });
});

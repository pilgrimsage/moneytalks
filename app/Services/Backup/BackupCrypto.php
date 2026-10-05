<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Streaming authenticated encryption for backup files (libsodium secretstream, XChaCha20-Poly1305). A wrong key, a
 * truncated file or a single flipped bit is detected and the restore refuses; nothing partial is ever trusted.
 * File layout: "MTBK1" | 24-byte stream header | chunks, each [4-byte big-endian length][ciphertext].
 */
final class BackupCrypto
{
    public const MAGIC = 'MTBK1';

    private const CHUNK = 1_048_576;

    /** A new random key, hex encoded (put it in BACKUP_ENCRYPTION_KEY and keep a copy OFF the server). */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    }

    /** @param resource $in @param resource $out */
    public static function encrypt($in, $out, string $hexKey): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key($hexKey));
        fwrite($out, self::MAGIC.$header);

        while (true) {
            $plain = (string) fread($in, self::CHUNK);
            $last = feof($in);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($out, pack('N', strlen($cipher)).$cipher);
            if ($last) {
                return;
            }
        }
    }

    /** @param resource $in @param resource $out */
    public static function decrypt($in, $out, string $hexKey): void
    {
        $head = (string) fread($in, strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (! str_starts_with($head, self::MAGIC) || strlen($head) !== strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Not a MoneyTalks backup file.');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, strlen(self::MAGIC)), self::key($hexKey));

        $final = false;
        while (! $final) {
            $len = fread($in, 4);
            if ($len === false || strlen($len) !== 4) {
                throw new RuntimeException('The backup is truncated.');
            }
            $size = unpack('N', $len)[1];
            $cipher = $size > 0 && $size <= self::CHUNK + 64 ? (string) fread($in, $size) : '';
            if (strlen($cipher) !== $size || $size === 0) {
                throw new RuntimeException('The backup is truncated or damaged.');
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($result === false) {
                throw new RuntimeException('Wrong key, or the backup was altered.');
            }
            [$plain, $tag] = $result;
            fwrite($out, $plain);
            $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
    }

    private static function key(string $hexKey): string
    {
        $key = ctype_xdigit($hexKey) ? hex2bin($hexKey) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be 64 hex characters (php artisan moneytalks:backup:key).');
        }

        return $key;
    }
}

<?php

namespace App\Services\WhatsApp\Testing;

use App\Services\WhatsApp\DTO\MediaFile;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\DTO\SendResult;
use App\Services\WhatsApp\Exceptions\MediaException;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * No-network provider for local development and tests. It parses and verifies exactly like Meta
 * (same wire format, same signature) but "sends" by recording messages in memory. Refused in production.
 */
class FakeWhatsAppProvider extends MetaWhatsAppProvider
{
    /** @var list<Outbound> */
    public static array $sent = [];

    /** @var list<array{filename: string, contents: string}> documents as they were at send time (the temp file is deleted afterwards) */
    public static array $documents = [];

    /** @var array<string, array{0: string, 1: string}> media id => [bytes, mime] available to downloadMedia() */
    public static array $media = [];

    /** @var list<string> */
    public static array $read = [];

    /** @var list<Throwable> exceptions to throw on the next send() calls, in order */
    public static array $failures = [];

    private static int $counter = 0;

    public static function reset(): void
    {
        self::$sent = [];
        self::$read = [];
        self::$documents = [];
        self::$media = [];
        self::$failures = [];
        self::$counter = 0;
    }

    public function send(Outbound $message): SendResult
    {
        if (self::$failures !== []) {
            throw array_shift(self::$failures);
        }

        if ($message->kind === 'document') {
            self::$documents[] = ['filename' => (string) $message->filename, 'contents' => (string) file_get_contents($message->filePath)];
        }
        self::$sent[] = $message;
        Log::info('whatsapp.fake.sent', ['kind' => $message->kind]);

        return new SendResult('wamid.FAKE'.str_pad((string) ++self::$counter, 6, '0', STR_PAD_LEFT).bin2hex(random_bytes(3)));
    }

    public function downloadMedia(string $mediaId, int $maxBytes): MediaFile
    {
        [$bytes, $mime] = self::$media[$mediaId] ?? throw new MediaException('Media not found', 'lookup_failed');
        if (strlen($bytes) > $maxBytes) {
            throw new MediaException('Media file is too large', 'too_large');
        }

        return new MediaFile($bytes, $mime);
    }

    public function markRead(string $waMessageId): void
    {
        self::$read[] = $waMessageId;
    }
}

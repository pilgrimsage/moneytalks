<?php

namespace App\Services\Speech;

use App\Services\Speech\Exceptions\SpeechException;
use Throwable;

/** Scripted transcripts for tests and local development (refused in production). */
class FakeSpeechProvider implements SpeechToTextProvider
{
    /** @var list<string|Throwable> */
    private static array $queue = [];

    /** @var list<array{bytes: int, mime: string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$calls = [];
    }

    public static function respond(string|Throwable $next): void
    {
        self::$queue[] = $next;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function transcribe(string $audio, string $mimeType): string
    {
        self::$calls[] = ['bytes' => strlen($audio), 'mime' => $mimeType];
        $next = array_shift(self::$queue) ?? throw new SpeechException('Nothing scripted', 'empty');

        return $next instanceof Throwable ? throw $next : $next;
    }
}

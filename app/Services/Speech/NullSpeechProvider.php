<?php

namespace App\Services\Speech;

use App\Services\Speech\Exceptions\SpeechException;

/** The default: no vendor configured, so voice notes are politely declined. */
class NullSpeechProvider implements SpeechToTextProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function transcribe(string $audio, string $mimeType): string
    {
        throw new SpeechException('Speech-to-text is not configured', 'disabled');
    }
}

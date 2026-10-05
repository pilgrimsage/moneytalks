<?php

namespace App\Services\Speech;

use App\Services\Speech\Exceptions\SpeechException;

/** The only seam to a transcription vendor. Audio goes in, text comes out; the text is then treated like any typed message. */
interface SpeechToTextProvider
{
    public function name(): string;

    /** Is a vendor configured? When false, voice notes get an honest "not switched on" reply and no audio is processed. */
    public function enabled(): bool;

    /** @throws SpeechException */
    public function transcribe(string $audio, string $mimeType): string;
}

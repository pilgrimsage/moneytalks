<?php

namespace App\Services\Speech;

use App\Services\Speech\Exceptions\SpeechException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Transcription through any service that speaks the OpenAI `/audio/transcriptions` format (OpenAI Whisper, Groq, a
 * self-hosted whisper server...). Configure STT_BASE_URL / STT_API_KEY / STT_MODEL. The audio is sent in memory and
 * never stored; only the resulting text is used.
 */
class OpenAiCompatibleSpeechProvider implements SpeechToTextProvider
{
    private const EXTENSIONS = [
        'audio/ogg' => 'ogg', 'audio/opus' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
        'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/webm' => 'webm',
    ];

    public function name(): string
    {
        return 'openai_compatible';
    }

    public function enabled(): bool
    {
        return (string) config('stt.openai_compatible.api_key') !== '' && (string) config('stt.openai_compatible.base_url') !== '';
    }

    public function transcribe(string $audio, string $mimeType): string
    {
        if (! $this->enabled()) {
            throw new SpeechException('Speech-to-text is not configured', 'disabled');
        }

        $cfg = (array) config('stt.openai_compatible');
        $ext = self::EXTENSIONS[strtolower($mimeType)] ?? 'ogg';

        try {
            $request = Http::withToken((string) $cfg['api_key'])->acceptJson()->timeout((int) $cfg['timeout_seconds'])
                ->attach('file', $audio, "voice.{$ext}", ['Content-Type' => $mimeType]);
            $fields = ['model' => (string) $cfg['model'], 'response_format' => 'json'];
            if (! empty($cfg['language'])) {
                $fields['language'] = (string) $cfg['language']; // optional hint; leave unset for mixed Hindi/English
            }
            $response = $request->post(rtrim((string) $cfg['base_url'], '/').'/audio/transcriptions', $fields);
        } catch (ConnectionException) {
            throw new SpeechException('Network error talking to the transcription service', 'network', true);
        }

        if (! $response->successful()) {
            throw new SpeechException('Transcription HTTP '.$response->status(), 'http_'.$response->status(), $response->serverError() || $response->status() === 429);
        }
        $text = trim((string) $response->json('text'));
        if ($text === '') {
            throw new SpeechException('Nothing could be heard', 'empty');
        }

        return mb_substr($text, 0, 1000, 'UTF-8');
    }
}

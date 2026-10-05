<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Services\WhatsApp\DTO\InboundMessage;
use App\Services\WhatsApp\DTO\MediaFile;
use App\Services\WhatsApp\Exceptions\MediaException;
use Illuminate\Support\Facades\Cache;

/**
 * Limits applied BEFORE any media is processed (docs/whatsapp.md section 8): allowed type, allowed MIME types, a daily
 * count per user (so a loop or an abuser cannot run up transcription and vision costs) and a maximum size.
 */
class MediaGuard
{
    /**
     * The bytes must really be what the sender's declared type says (a renamed file never reaches a vendor).
     *
     * @throws MediaException
     */
    public function assertContent(MediaFile $file): void
    {
        $b = $file->bytes;
        $ok = match (strtolower($file->mimeType)) {
            'image/jpeg' => str_starts_with($b, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($b, "\x89PNG\r\n\x1A\n"),
            'image/webp' => substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP',
            'audio/ogg', 'audio/opus' => str_starts_with($b, 'OggS'),
            'audio/mpeg' => str_starts_with($b, 'ID3') || (strlen($b) > 1 && ord($b[0]) === 0xFF && (ord($b[1]) & 0xE0) === 0xE0),
            'audio/mp4' => substr($b, 4, 4) === 'ftyp',
            'audio/aac' => strlen($b) > 1 && ord($b[0]) === 0xFF && (ord($b[1]) & 0xF0) === 0xF0,
            'audio/amr' => str_starts_with($b, '#!AMR'),
            'audio/webm' => str_starts_with($b, "\x1A\x45\xDF\xA3"),
            default => false,
        };
        if (! $ok) {
            throw new MediaException('File content does not match its type', 'unsupported_type');
        }
    }

    /** @return int the maximum number of bytes allowed for this message's media */
    public function admit(User $user, InboundMessage $message): int
    {
        $kind = $message->type === 'audio' ? 'audio' : 'image';
        $mime = strtolower(trim(explode(';', (string) $message->mimeType)[0]));

        if ($message->mediaId === null || ! in_array($mime, (array) config("whatsapp.media.{$kind}_mimes"), true)) {
            throw new MediaException("Unsupported {$kind} type", 'unsupported_type');
        }

        $key = 'media:'.$user->id.':'.now()->format('Ymd');
        Cache::add($key, 0, now()->addDay());
        if ((int) Cache::increment($key) > (int) config('whatsapp.media.per_user_daily')) {
            throw new MediaException('Daily media limit reached', 'daily_limit');
        }

        return (int) config("whatsapp.media.max_{$kind}_bytes");
    }
}

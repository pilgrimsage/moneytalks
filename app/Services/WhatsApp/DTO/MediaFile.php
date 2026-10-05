<?php

namespace App\Services\WhatsApp\DTO;

/** A downloaded WhatsApp media file, held in memory only (voice notes and receipt photos are never written to disk). */
final class MediaFile
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
    ) {}
}

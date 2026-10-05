<?php

namespace App\Services\WhatsApp\DTO;

/** A message from the user, normalised away from Meta's wire format. */
final class InboundMessage
{
    public function __construct(
        public readonly string $waMessageId,
        public readonly string $from,            // sender's WhatsApp id (digits)
        public readonly string $type,            // text | audio | image | interactive | button | document | unsupported
        public readonly ?string $text,           // text body, caption, or the title of a tapped button
        public readonly int $timestamp,          // unix seconds, as reported by Meta
        public readonly ?string $mediaId = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $replyId = null, // id of the tapped button / list row
        public readonly array $raw = [],
    ) {}
}

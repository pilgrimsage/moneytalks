<?php

namespace App\Services\WhatsApp\DTO;

final class ParsedWebhook
{
    /**
     * @param  list<InboundMessage>  $messages
     * @param  list<InboundStatus>  $statuses
     */
    public function __construct(
        public readonly array $messages = [],
        public readonly array $statuses = [],
        /** False when the payload was addressed to a different phone number than ours. */
        public readonly bool $forThisNumber = true,
    ) {}
}

<?php

namespace App\Services\WhatsApp\DTO;

/** A delivery receipt for a message we sent. */
final class InboundStatus
{
    public function __construct(
        public readonly string $waMessageId,
        public readonly string $status,          // sent | delivered | read | failed
        public readonly int $timestamp,
        public readonly ?string $pricingCategory = null,
        public readonly ?string $pricingModel = null,
        public readonly ?bool $billable = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}
}

<?php

namespace App\Services\WhatsApp\DTO;

final class SendResult
{
    public function __construct(public readonly string $waMessageId) {}
}

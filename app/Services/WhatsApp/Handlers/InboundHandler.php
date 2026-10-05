<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DTO\InboundMessage;

/**
 * Decides what to do with a message from a verified, allowed user. Runs under the per-user lock.
 * Must be safe to run twice for the same message (retries): ledger writes use idempotency keys,
 * and replies use OutboundMessageService dedupe keys derived from the inbound message id.
 */
interface InboundHandler
{
    public function handle(User $user, InboundMessage $message, WhatsappMessage $row): void;
}

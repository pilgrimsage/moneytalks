<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DTO\InboundMessage;
use App\Services\WhatsApp\OutboundMessageService;

/**
 * M4 placeholder: proves the whole pipeline (webhook -> queue -> processor -> reply) works.
 * It does NOT interpret or record anything; M5 replaces it with the AI interpretation handler.
 */
class AcknowledgeHandler implements InboundHandler
{
    public function __construct(private readonly OutboundMessageService $out) {}

    public function handle(User $user, InboundMessage $message, WhatsappMessage $row): void
    {
        $reply = $message->type === 'text' || ($message->type === 'interactive' && $message->text !== null)
            ? '✅ Message received. (I can\'t record transactions yet; that arrives in the next milestone.)'
            : 'I can only read text messages for now.';

        $this->out->sendText($user, $reply, "reply:{$message->waMessageId}:0", $message->waMessageId);
    }
}

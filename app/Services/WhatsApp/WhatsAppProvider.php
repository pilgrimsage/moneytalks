<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\DTO\MediaFile;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\DTO\ParsedWebhook;
use App\Services\WhatsApp\DTO\SendResult;
use App\Services\WhatsApp\Exceptions\MediaException;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;

/**
 * The only seam between the application and a WhatsApp vendor. The domain sees DTOs, never wire
 * formats. Implementations: MetaWhatsAppProvider (production), FakeWhatsAppProvider (no network).
 * Documents are sent through send(); received media is fetched with downloadMedia().
 */
interface WhatsAppProvider
{
    /** GET handshake: return the challenge to echo, or null to refuse. */
    public function verifyChallenge(array $query): ?string;

    /** Authenticity of a POST, computed over the RAW body bytes. */
    public function verifySignature(string $rawBody, ?string $signatureHeader): bool;

    /** Name of the request header that carries the signature / secret checked by verifySignature(). */
    public function signatureHeader(): string;

    /** Whether free-form messages are limited to a window after the user's last message (WhatsApp: yes, Telegram: no). */
    public function enforcesServiceWindow(): bool;

    public function parseWebhook(array $payload): ParsedWebhook;

    /**
     * @throws TransientSendException
     * @throws PermanentSendException
     */
    public function send(Outbound $message): SendResult;

    /** Best-effort "seen" tick; never throws. */
    /**
     * Download a received media file (voice note, photo). Refuses before downloading when it is larger than $maxBytes.
     *
     * @throws MediaException
     */
    public function downloadMedia(string $mediaId, int $maxBytes): MediaFile;

    public function markRead(string $waMessageId): void;
}

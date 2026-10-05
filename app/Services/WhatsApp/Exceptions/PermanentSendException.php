<?php

namespace App\Services\WhatsApp\Exceptions;

/** Retrying will not help: bad request, bad credentials, unknown recipient. */
class PermanentSendException extends WhatsAppException
{
    public function __construct(string $message, public readonly ?string $providerCode = null)
    {
        parent::__construct($message);
    }
}

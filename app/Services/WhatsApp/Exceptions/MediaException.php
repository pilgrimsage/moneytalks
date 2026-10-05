<?php

namespace App\Services\WhatsApp\Exceptions;

use RuntimeException;

/** A media file could not be fetched or was refused (too big, wrong type). The message is safe to log, never contains content. */
class MediaException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed', public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

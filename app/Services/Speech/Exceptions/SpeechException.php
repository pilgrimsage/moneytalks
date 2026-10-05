<?php

namespace App\Services\Speech\Exceptions;

use RuntimeException;

class SpeechException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed', public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

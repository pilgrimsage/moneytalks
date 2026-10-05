<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

class AIException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}

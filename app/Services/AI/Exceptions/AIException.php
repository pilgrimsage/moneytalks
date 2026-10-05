<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

class AIException extends RuntimeException
{
    /**
     * $detail is the provider's own error text, for operators only (the eval command prints it). It is never
     * stored or logged: persistence uses $errorCode and the class name.
     */
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }
}

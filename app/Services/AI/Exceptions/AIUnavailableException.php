<?php

namespace App\Services\AI\Exceptions;

/** Every model in the chain failed. The caller must keep the user's message and tell them gracefully. */
class AIUnavailableException extends AIException {}

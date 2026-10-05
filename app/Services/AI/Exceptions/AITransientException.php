<?php

namespace App\Services\AI\Exceptions;

/** Worth retrying: rate limit, overload, 5xx, network/timeout. */
class AITransientException extends AIException {}

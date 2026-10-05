<?php

namespace App\Services\AI\Exceptions;

/** Retrying the same request will not help: bad request, auth, unknown model. */
class AIPermanentException extends AIException {}

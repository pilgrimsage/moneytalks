<?php

namespace App\Services\WhatsApp\Exceptions;

/** Worth retrying: rate limit (429), server error (5xx), network failure. */
class TransientSendException extends WhatsAppException {}

<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/** A request the ledger refuses (bad input or a rule violation). Nothing was written. */
class LedgerException extends RuntimeException {}

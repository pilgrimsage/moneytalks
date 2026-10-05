<?php

namespace App\Domain\Ledger\Exceptions;

/** Debits and credits disagree. Always a bug or tampering; the DB transaction is rolled back. */
class LedgerImbalanceException extends LedgerException {}

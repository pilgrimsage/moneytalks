<?php

namespace App\Enums;

enum AccountKind: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';
    case Equity = 'equity';

    /** Debit-normal kinds grow with debits (balance = debits - credits). */
    public function isDebitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }
}

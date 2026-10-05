<?php

namespace App\Enums;

enum AccountSubtype: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Wallet = 'wallet';
    case CreditCard = 'credit_card';
    case Loan = 'loan';
    case Receivable = 'receivable';
    case Payable = 'payable';
    case Investment = 'investment';
    case Goal = 'goal';
    case System = 'system';

    public function kind(): ?AccountKind
    {
        return match ($this) {
            self::Cash, self::Bank, self::Wallet, self::Receivable, self::Investment, self::Goal => AccountKind::Asset,
            self::CreditCard, self::Loan, self::Payable => AccountKind::Liability,
            self::System => null, // system accounts pick their own kind
        };
    }

    /** Accounts money can be spent from or received into by an ordinary expense/income. */
    public function isSpendable(): bool
    {
        return in_array($this, [self::Cash, self::Bank, self::Wallet, self::CreditCard], true);
    }

    /** Own accounts that can receive income and take part in plain transfers (M3). */
    public function isOwnedAsset(): bool
    {
        return in_array($this, [self::Cash, self::Bank, self::Wallet, self::Investment, self::Goal], true);
    }

    /** Accounts a user names in conversation ("HDFC", "cash"); excludes system and per-person accounts. */
    public function isUserFacing(): bool
    {
        return ! in_array($this, [self::System, self::Receivable, self::Payable], true);
    }
}

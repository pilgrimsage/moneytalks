<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Upi = 'upi';
    case BankTransfer = 'bank_transfer';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case NetBanking = 'net_banking';
    case Wallet = 'wallet';
    case Cheque = 'cheque';
    case Other = 'other';
}

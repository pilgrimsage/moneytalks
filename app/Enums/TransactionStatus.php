<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Voided = 'voided';
}

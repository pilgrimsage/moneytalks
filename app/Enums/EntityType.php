<?php

namespace App\Enums;

enum EntityType: string
{
    case Category = 'category';
    case Merchant = 'merchant';
    case Account = 'account';
    case Counterparty = 'counterparty';
}

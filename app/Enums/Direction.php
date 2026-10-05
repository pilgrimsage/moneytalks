<?php

namespace App\Enums;

enum Direction: string
{
    case Debit = 'D';
    case Credit = 'C';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}

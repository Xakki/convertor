<?php

declare(strict_types=1);

namespace App\Enum;

enum BalanceTransactionSource: string
{
    case Conversion = 'conversion';
    case Payment    = 'payment';
    case Admin      = 'admin';
    case Other      = 'other';
}

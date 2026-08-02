<?php

declare(strict_types=1);

namespace App\Enum;

enum BalanceTransactionType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
    case Refund = 'refund';
}

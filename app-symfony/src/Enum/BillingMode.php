<?php

declare(strict_types=1);

namespace App\Enum;

enum BillingMode: string
{
    case PlanQuota      = 'plan_quota';
    case PrepaidBalance = 'prepaid_balance';
}

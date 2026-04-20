<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentGateway: string
{
    case TelegramStars = 'telegram_stars';
    case Stripe = 'stripe';
    case Cryptomus = 'cryptomus';
}

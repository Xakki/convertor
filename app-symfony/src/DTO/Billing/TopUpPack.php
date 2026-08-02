<?php

declare(strict_types=1);

namespace App\DTO\Billing;

/**
 * Пакет пополнения prepaid-баланса (CNV-28): USD cents + цена в Telegram Stars (XTR).
 */
final readonly class TopUpPack
{
    public function __construct(
        public string $id,
        public int $usdCents,
        public int $stars,
    ) {
    }

    public function usdAmount(): float
    {
        return $this->usdCents / 100.0;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Service\Billing\TopUpPackRegistry;
use PHPUnit\Framework\TestCase;

final class TopUpPackRegistryTest extends TestCase
{
    public function testParsesDefaultShape(): void
    {
        $registry = new TopUpPackRegistry(
            '{"pack_100":{"usd_cents":100,"stars":100},"pack_500":{"usd_cents":500,"stars":450}}',
        );

        $pack = $registry->getPack('pack_500');
        self::assertSame('pack_500', $pack->id);
        self::assertSame(500, $pack->usdCents);
        self::assertSame(450, $pack->stars);
        self::assertSame(5.0, $pack->usdAmount());
    }
}

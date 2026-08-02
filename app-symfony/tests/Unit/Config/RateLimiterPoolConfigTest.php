<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Smoke: rate limiters wire to cache.app (KeyDB) outside test env.
 */
final class RateLimiterPoolConfigTest extends TestCase
{
    public function testProdDevLimitersUseCacheApp(): void
    {
        $path = dirname(__DIR__, 3) . '/config/packages/rate_limiter.yaml';
        self::assertFileExists($path);

        /** @var array<string, mixed> $all */
        $all = Yaml::parseFile($path);
        self::assertIsArray($all['framework']['rate_limiter'] ?? null);

        /** @var array<string, array<string, mixed>> $limiters */
        $limiters = $all['framework']['rate_limiter'];

        $expected = [
            'anon_convert',
            'anon_quota',
            'anon_telegram_poll',
            'user_convert',
            'user_quota',
            'api_ip',
        ];
        foreach ($expected as $name) {
            self::assertArrayHasKey($name, $limiters, "missing limiter {$name}");
            self::assertSame('cache.app', $limiters[$name]['cache_pool'] ?? null, "{$name} must use cache.app");
        }

        self::assertSame(120, $limiters['user_convert']['limit']);
        self::assertSame(120, $limiters['user_quota']['limit']);
        self::assertSame(300, $limiters['api_ip']['limit']);
        self::assertSame('1 minute', $limiters['api_ip']['interval']);

        /** @var array<string, mixed> $whenTest */
        $whenTest = $all['when@test']['framework']['rate_limiter'] ?? [];
        foreach ($expected as $name) {
            self::assertSame(
                'cache.rate_limiter.test_array',
                $whenTest[$name]['cache_pool'] ?? null,
                "when@test {$name} must use in-memory array pool",
            );
        }
    }
}

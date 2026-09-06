<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class Cnv87SecurityConfigTest extends TestCase
{
    public function testProxyAndAnonymousBoundariesAreExplicit(): void
    {
        $root       = dirname(__DIR__, 3);
        $framework  = file_get_contents($root . '/config/packages/framework.yaml');
        $services   = file_get_contents($root . '/config/services.yaml');
        $auth       = file_get_contents($root . '/src/Security/GuestAuthenticator.php');
        $repository = file_get_contents($root . '/src/Repository/UserRepository.php');

        self::assertIsString($framework);
        self::assertIsString($services);
        self::assertIsString($auth);
        self::assertIsString($repository);

        self::assertStringNotContainsString('private_ranges', $framework . $services);
        self::assertStringContainsString("env(TRUSTED_PROXIES): '127.0.0.1'", $services);

        self::assertStringContainsString('$path === \'/api/v1/convert/history\'', $auth);
        self::assertStringContainsString('allowsAnonymousIpFallback', $auth);
        self::assertStringContainsString("'anonymousIp' => false", $repository);
        self::assertStringContainsString("'anonymousIp' => true", $repository);
    }

    public function testMigrationAddsDiscriminatorWithCookieSafeDefault(): void
    {
        $path      = dirname(__DIR__, 3) . '/migrations/Version20260905100000.php';
        $migration = file_get_contents($path);

        self::assertIsString($migration);
        self::assertStringContainsString('ADD anonymous_ip TINYINT(1) NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('DROP anonymous_ip', $migration);
    }
}

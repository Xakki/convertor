<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Xakki\LogSymfony\Formatters\CustomFormatter;

final class MonologConfigurationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = Yaml::parseFile(__DIR__ . '/../../../config/packages/monolog.yaml');

        self::assertIsArray($config);

        return $config;
    }

    public function testTestHandlersNeverWriteToPhpUnitOutputStreams(): void
    {
        $config       = $this->config();
        $testHandlers = $config['when@test']['monolog']['handlers'];

        self::assertIsArray($testHandlers);
        self::assertNull($testHandlers['nested']['type']);

        foreach ($testHandlers as $name => $handler) {
            self::assertIsArray($handler);
            self::assertFalse(
                isset($handler['path']) && in_array($handler['path'], ['php://stdout', 'php://stderr'], true),
                sprintf('Test handler %s must not target PHPUnit output streams.', $name),
            );
        }
    }

    public function testProductionStructuredHandlersKeepCustomFormatterOnStderr(): void
    {
        $config             = $this->config();
        $productionHandlers = $config['when@prod']['monolog']['handlers'];

        self::assertIsArray($productionHandlers);
        self::assertSame('php://stderr', $productionHandlers['nested']['path']);
        self::assertSame(CustomFormatter::class, $productionHandlers['nested']['formatter']);
        self::assertSame('php://stderr', $productionHandlers['deprecation']['path']);
        self::assertSame(CustomFormatter::class, $productionHandlers['deprecation']['formatter']);
    }
}

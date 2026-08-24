<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Enum\WorkerType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * CNV-88: in-zone (app-symfony/) PHP mirror of the Python drift guard
 * `workers/tests/test_worker_type_drift.py::test_messenger_transports_match_canon`
 * — that Python test lives outside the backend-php agent's zone
 * (`workers/`), so this suite gives the SAME guard a home inside `app-symfony/`
 * where a future `WorkerType` case change can be caught by `make TEST=1
 * test-php` alone, without requiring a Python/docker run.
 *
 * Asserts: `App\Enum\WorkerType` (the canonical PHP-side set — see its class
 * docblock) has EXACTLY one `conv_<value>` transport in `messenger.yaml`,
 * each targeting stream `conv.<value>` in the `convertor` consumer group. Two
 * transports are intentionally NOT part of this set (`failed`, `async`) —
 * they are not `conv_*`/worker-type transports (see messenger.yaml comments).
 */
final class MessengerWorkerTypeTransportDriftTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadConvTransports(): array
    {
        $path = dirname(__DIR__, 3) . '/config/packages/messenger.yaml';
        self::assertFileExists($path);

        /** @var array<string, mixed> $all */
        $all = Yaml::parseFile($path);
        /** @var array<string, array<string, mixed>> $transports */
        $transports = $all['framework']['messenger']['transports'] ?? [];
        self::assertNotEmpty($transports, 'messenger.yaml: no transports parsed — yaml shape changed?');

        $conv = [];
        foreach ($transports as $name => $config) {
            if (str_starts_with((string) $name, 'conv_')) {
                $conv[$name] = $config;
            }
        }

        return $conv;
    }

    public function testEveryWorkerTypeHasExactlyOneConvTransport(): void
    {
        $conv = $this->loadConvTransports();

        $expected = array_map(
            static fn (WorkerType $t): string => 'conv_' . $t->value,
            WorkerType::cases(),
        );
        sort($expected);

        $actual = array_keys($conv);
        sort($actual);

        self::assertSame($expected, $actual, 'WorkerType::cases() drifted from conv_<type> transports in messenger.yaml');
    }

    public function testEveryConvTransportStreamMatchesItsWorkerType(): void
    {
        $conv = $this->loadConvTransports();

        foreach (WorkerType::cases() as $workerType) {
            $name = 'conv_' . $workerType->value;
            self::assertArrayHasKey($name, $conv, "missing transport {$name} for WorkerType::{$workerType->name}");

            $stream = $conv[$name]['options']['stream'] ?? null;
            self::assertSame(
                'conv.' . $workerType->value,
                $stream,
                "transport {$name} must target stream conv.{$workerType->value}",
            );

            $group = $conv[$name]['options']['group'] ?? null;
            self::assertSame('convertor', $group, "transport {$name} must use the shared 'convertor' consumer group");
        }
    }
}

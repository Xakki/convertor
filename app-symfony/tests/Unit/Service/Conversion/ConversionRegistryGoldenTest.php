<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Golden-master snapshot of the FULL routing behaviour for every supported pair:
 * `from→to = category|stream|isAi`, where `stream` is the real
 * {@see ConversionRegistry::streamFor()} output (the suffix that becomes
 * `conv_<stream>`).
 *
 * The frozen snapshot lives in tests/Fixtures/conversion_matrix.golden.txt.
 * Any change to category, stream, or isAi for ANY pair will fail this test —
 * proving the committed catalog (`config/catalog/conversion_pairs.json`)
 * introduces no unintended route regressions for audio/video/document/data/
 * markup/stt/tts.
 *
 * CNV-71-02: moved from `tests/Functional/` (KernelTestCase, booted the whole
 * container, needed a migrated `convertor-test` DB seeded with the registry-03
 * `__seed__` rows) to `tests/Unit/` (plain TestCase, no DB/container at all) —
 * the routing matrix ({@see ConversionRegistry::isSupported()}/`getCategory()`/
 * `streamFor()`) no longer reads `worker_capabilities` at all, so
 * `new ConversionRegistry()` with its default catalog path already gives the
 * exact same full 394-pair matrix production serves, no DB/seed required to
 * exercise it. `ConversionPairsCatalogDriftTest` guards a DIFFERENT thing —
 * that `conversion_pairs.json` matches a fresh reduction of
 * `worker_capabilities.json` — and never calls `streamFor()`; THIS test is
 * the only guard on stream fidelity (markup→document folding, ai routing).
 *
 * To intentionally update the golden after a deliberate matrix change: review
 * the diff (`php bin/dump-matrix.php`, no DB/container needed anymore either —
 * see its own docblock), then regenerate with `php bin/dump-matrix.php --write`.
 */
final class ConversionRegistryGoldenTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../Fixtures/conversion_matrix.golden.txt';

    public function testFullRoutingMatrixMatchesGolden(): void
    {
        $registry = new ConversionRegistry();

        $actual = [];
        foreach ($registry->getSupportedFormats() as $f) {
            /** @var array{from: string, to: string, category: string, isAi: bool} $f */
            $stream                             = $registry->streamFor($f['from'], $f['to']);
            $actual["{$f['from']}->{$f['to']}"] = "{$f['category']}|{$stream}|" . (int) $f['isAi'];
        }
        ksort($actual);

        $golden = $this->loadGolden();

        self::assertSame(
            $golden,
            $actual,
            'Routing matrix drifted from the golden snapshot. Review with '
            . '`php bin/dump-matrix.php`; if the change is intentional, regenerate via '
            . '`php bin/dump-matrix.php --write` and run `make formats-catalog` if the '
            . 'underlying worker_capabilities.json changed too.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function loadGolden(): array
    {
        $lines = file(self::FIXTURE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines, 'Golden fixture missing: ' . self::FIXTURE);

        $map = [];
        foreach ($lines as $line) {
            [$key, $value] = explode(' = ', $line, 2);
            $map[$key]     = $value;
        }
        ksort($map);

        return $map;
    }
}

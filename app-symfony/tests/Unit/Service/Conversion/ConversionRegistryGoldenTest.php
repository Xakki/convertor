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
 * proving the capability-config refactor (and the OCR additions) introduce no
 * unintended route regressions for audio/video/document/data/markup/stt/tts.
 *
 * To intentionally update the golden after a deliberate matrix change, review
 * the diff (`php bin/dump-matrix.php`), then regenerate the fixture with
 * `php bin/dump-matrix.php --write`.
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
            'Routing matrix drifted from the golden snapshot. '
            . 'Review with `php bin/dump-matrix.php`; if the change is intentional, regenerate via `php bin/dump-matrix.php --write`.',
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

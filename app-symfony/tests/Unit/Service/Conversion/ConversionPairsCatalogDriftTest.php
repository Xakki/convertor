<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CNV-71-01: the PHP half of the two-stage `make formats-catalog` drift guard
 * (the Python half — `worker_capabilities.json` vs. live worker CAPABILITIES —
 * lives in `workers/tests/test_catalog_drift.py`). Both halves run under
 * `make test` (`test-php` runs this test, `test-drift` runs the Python one —
 * see the root `Makefile`'s `test` target).
 *
 * This test runs the SAME reduction the committed generator command uses
 * ({@see ConversionRegistry::getSupportedFormatsFromBlobs()} — the exact
 * method `App\Command\GenerateConversionPairsCommand` calls) directly over the
 * committed `worker_capabilities.json`, and fails if the result no longer
 * matches the committed `conversion_pairs.json` byte-for-byte (same
 * sort/shape as the command's own output). A no-DB, no-container Unit test —
 * runs in the plain `tests/Unit` suite `make test-php` always executes.
 *
 * Catches: someone ran `make formats-catalog` (stage 1, regenerating
 * worker_capabilities.json) but forgot stage 2 (this reduction), OR hand-edited
 * either committed JSON file directly instead of regenerating both.
 */
final class ConversionPairsCatalogDriftTest extends TestCase
{
    private const CAPABILITIES_FIXTURE = __DIR__ . '/../../../../config/catalog/worker_capabilities.json';

    private const CATALOG_FIXTURE = __DIR__ . '/../../../../config/catalog/conversion_pairs.json';

    public function testCommittedConversionPairsMatchFreshReductionOfWorkerCapabilities(): void
    {
        self::assertFileExists(
            self::CAPABILITIES_FIXTURE,
            self::CAPABILITIES_FIXTURE . ' missing — this is a committed, generated artifact (stage 1: `make formats-catalog`).',
        );
        self::assertFileExists(
            self::CATALOG_FIXTURE,
            self::CATALOG_FIXTURE . ' missing — run `make formats-catalog` (stage 2) and commit the result.',
        );

        $blobsRaw = file_get_contents(self::CAPABILITIES_FIXTURE);
        self::assertNotFalse($blobsRaw);
        /** @var list<array<string, mixed>> $blobs */
        $blobs = json_decode($blobsRaw, true, 512, JSON_THROW_ON_ERROR);

        $registry = new ConversionRegistry();
        $fresh    = $registry->getSupportedFormatsFromBlobs($blobs);
        usort($fresh, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        $committedRaw = file_get_contents(self::CATALOG_FIXTURE);
        self::assertNotFalse($committedRaw);
        /** @var list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $committed */
        $committed = json_decode($committedRaw, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            $fresh,
            $committed,
            'config/catalog/conversion_pairs.json drifted from a fresh reduction of worker_capabilities.json — '
            . 'run `make formats-catalog` and commit the result (see App\\Command\\GenerateConversionPairsCommand).',
        );
    }
}

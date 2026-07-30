<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Conversion;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
 * registry-04: moved off `new ConversionRegistry()` (repository=null was
 * ALWAYS the hardcoded fallback then, dead at runtime on any migrated
 * environment since the registry-03 seed migration — and since registry-05
 * deleted that fallback entirely, repository=null now yields an EMPTY matrix
 * instead). Built from the REAL DB-backed repository, through the container
 * (KernelTestCase — requires convertor-test, migrated), like production does.
 *
 * DB rows are filtered to `instanceId === '__seed__'` (the registry-03 seed,
 * see Version20260722150301) rather than trusting "whatever else happens to
 * be in worker_capabilities". Rationale (registry-04 review): this test suite
 * has no per-test transactional rollback (no dama/doctrine-test-bundle), so
 * the shared table's content across the whole PHPUnit run is not otherwise
 * guaranteed — filtering to the known seed keeps this test reproducible and
 * immune to any other test's leftover rows (real or synthetic), without
 * needing to touch the shared table itself.
 *
 * To intentionally update the golden after a deliberate matrix change: review
 * the diff (`APP_ENV=test php bin/dump-matrix.php` on a freshly migrated
 * convertor-test — see `make test-up`), then regenerate with
 * `APP_ENV=test php bin/dump-matrix.php --write`. Running dump-matrix.php
 * against the DEV database instead would mix in real worker registrations
 * (`instance_id != '__seed__'`) that this test does NOT see — the golden
 * would silently stop matching.
 */
final class ConversionRegistryGoldenTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../../Fixtures/conversion_matrix.golden.txt';

    private const SEED_INSTANCE_ID = '__seed__';

    public function testFullRoutingMatrixMatchesGolden(): void
    {
        self::bootKernel();
        $repository = static::getContainer()->get(WorkerCapabilityRepository::class);

        $seedOnly = array_values(array_filter(
            $repository->findAllCapabilities(),
            static fn (WorkerCapability $c): bool => $c->getInstanceId() === self::SEED_INSTANCE_ID,
        ));
        self::assertNotEmpty(
            $seedOnly,
            'No worker_capabilities rows with instanceId="__seed__" found — has the registry-03 '
            . 'seed migration (Version20260722150301) run on convertor-test? Run `make test-up`.',
        );

        $repoStub = $this->createStub(WorkerCapabilityRepository::class);
        $repoStub->method('findAllCapabilities')->willReturn($seedOnly);

        $registry = new ConversionRegistry($repoStub);

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
            . '`APP_ENV=test php bin/dump-matrix.php`; if the change is intentional, regenerate via '
            . '`APP_ENV=test php bin/dump-matrix.php --write` (on a freshly migrated convertor-test).',
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

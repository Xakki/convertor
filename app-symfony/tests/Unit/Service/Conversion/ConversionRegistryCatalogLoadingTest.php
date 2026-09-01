<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Enum\FileCategory;
use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CNV-71-02: {@see ConversionRegistry} builds its routing matrix from a
 * committed catalog FILE (`config/catalog/conversion_pairs.json` by default —
 * see `ConversionRegistry::defaultCatalogPath()`), not from a DB table
 * anymore. This suite covers that file-loading seam directly: a missing,
 * malformed OR EMPTY file must all fail LOUDLY (never silently degrade to an
 * empty matrix — an empty matrix here means every format and every
 * conversion on the site 400s; there is no legitimate case for a committed
 * empty catalog), and the matrix is memoized per registry instance (one file
 * read per instance, not per method call).
 */
final class ConversionRegistryCatalogLoadingTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/conversion_registry_catalog_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    private function writeCatalog(string $contents): string
    {
        $path = $this->tmpDir . '/catalog.json';
        file_put_contents($path, $contents);

        return $path;
    }

    public function testMissingCatalogFileThrowsInsteadOfDegradingToEmptyMatrix(): void
    {
        $registry = new ConversionRegistry(catalogPath: $this->tmpDir . '/does-not-exist.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/файл каталога не найден/u');

        $registry->getSupportedFormats();
    }

    public function testMalformedJsonThrows(): void
    {
        $path     = $this->writeCatalog('{not valid json');
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/невалидный JSON/u');

        $registry->isSupported('jpg', 'png');
    }

    public function testNonArrayRootThrows(): void
    {
        $path     = $this->writeCatalog('{"from": "jpg"}');
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);

        $registry->getSupportedFormats();
    }

    public function testRowMissingRequiredKeyThrows(): void
    {
        // 'category' отсутствует
        $path     = $this->writeCatalog('[{"from": "jpg", "to": "png", "isAi": false}]');
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);

        $registry->getSupportedFormats();
    }

    public function testUnknownCategoryValueThrows(): void
    {
        $path     = $this->writeCatalog('[{"from": "jpg", "to": "png", "category": "bogus", "isAi": false}]');
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);

        $registry->getSupportedFormats();
    }

    /**
     * Синтаксически валидный, но ПУСТОЙ каталог — не «честный вырожденный
     * случай», а ГРОМКАЯ ошибка: легитимного коммиченного пустого каталога
     * не существует, пустая матрица означает потерю всех форматов сайтом.
     */
    public function testSyntacticallyValidEmptyArrayThrows(): void
    {
        $path     = $this->writeCatalog('[]');
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/пуст/u');

        $registry->getSupportedFormats();
    }

    public function testValidTinyCatalogBuildsWorkingMatrix(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'jpg', 'to' => 'png', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false],
            ['from' => 'mp3', 'to' => 'txt', 'category' => 'audio', 'isAi' => true, 'ocrCapable' => false],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        self::assertTrue($registry->isSupported('jpg', 'png'));
        self::assertSame(FileCategory::Image, $registry->getCategory('jpg', 'png'));
        self::assertFalse($registry->isAi('jpg', 'png'));
        self::assertSame('image', $registry->streamFor('jpg', 'png'));

        self::assertTrue($registry->isSupported('mp3', 'txt'));
        self::assertTrue($registry->isAi('mp3', 'txt'));
        self::assertSame('ai', $registry->streamFor('mp3', 'txt'));

        self::assertFalse($registry->isSupported('png', 'jpg'), 'reverse direction is not declared');
    }

    /**
     * Матрица держится в per-request memo (см. `ConversionRegistry::getMatrix()`):
     * файл читается максимум один раз за инстанс. Доказательство от противного —
     * удаляем файл ПОСЛЕ первого успешного построения матрицы и убеждаемся, что
     * повторные вызовы всё ещё работают (не падают на "файл не найден").
     */
    /**
     * CNV-88: a catalog row carrying `executionKind` routes there REGARDLESS
     * of its stored category — the can-fail proof (a) for the card ("browser
     * job routes to conv.browser") and the regression guard for animated
     * SVG→GIF (CNV-106, out of scope here): `category` stays `image` (quota/
     * retention unaffected), only `streamFor()`'s output changes.
     */
    public function testExecutionKindOverridesCategoryBasedRouting(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'svg', 'to' => 'gif', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false, 'executionKind' => 'browser'],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        self::assertTrue($registry->isSupported('svg', 'gif'));
        self::assertSame('browser', $registry->streamFor('svg', 'gif'), 'executionKind must win over category-based routing');
        self::assertSame(FileCategory::Image, $registry->getCategory('svg', 'gif'), 'category stays the quota/retention source, unaffected by executionKind');
        self::assertFalse($registry->isAi('svg', 'gif'));
    }

    /**
     * CNV-88: a row with NO `executionKind` key (100% of today's committed
     * `conversion_pairs.json`) must keep routing purely by category — the
     * can-fail proof (c) for the card ("existing image job still routes to
     * conv.image and NOT to conv.browser").
     */
    public function testAbsentExecutionKindKeepsCategoryBasedRoutingUnchanged(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'jpg', 'to' => 'png', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        self::assertSame('image', $registry->streamFor('jpg', 'png'));
        self::assertNotSame('browser', $registry->streamFor('jpg', 'png'));
    }

    /**
     * CNV-88: an explicit `executionKind: null` is equivalent to the key being
     * absent (defensive — a generator could emit `null` explicitly).
     */
    public function testExplicitNullExecutionKindKeepsCategoryBasedRouting(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'jpg', 'to' => 'png', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false, 'executionKind' => null],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        self::assertSame('image', $registry->streamFor('jpg', 'png'));
    }

    /**
     * CNV-88 can-fail proof (b): an unknown `executionKind` value (not a valid
     * `WorkerType` case) is rejected LOUDLY at catalog-load time, same policy
     * as an unknown `category`.
     */
    public function testUnknownExecutionKindValueThrows(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'svg', 'to' => 'gif', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false, 'executionKind' => 'bogus'],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/неизвестный executionKind/u');

        $registry->getSupportedFormats();
    }

    /**
     * CNV-88: `executionKind` must be a non-empty string when present — an
     * empty string is rejected the same way as an unknown value, not silently
     * treated as "absent".
     */
    public function testEmptyStringExecutionKindThrows(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'svg', 'to' => 'gif', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false, 'executionKind' => ''],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        $this->expectException(\RuntimeException::class);

        $registry->getSupportedFormats();
    }

    public function testExecutionKindTakesPrecedenceOverAiQuotaFlag(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'txt', 'to' => 'json_ai', 'category' => 'document', 'isAi' => true, 'ocrCapable' => false, 'executionKind' => 'api'],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        self::assertTrue($registry->isAi('txt', 'json_ai'), 'isAi remains the quota flag');
        self::assertSame('api', $registry->streamFor('txt', 'json_ai'));
    }

    public function testMatrixIsMemoizedPerInstanceNotReReadFromDisk(): void
    {
        $path = $this->writeCatalog(json_encode([
            ['from' => 'jpg', 'to' => 'png', 'category' => 'image', 'isAi' => false, 'ocrCapable' => false],
        ], JSON_THROW_ON_ERROR));
        $registry = new ConversionRegistry(catalogPath: $path);

        self::assertTrue($registry->isSupported('jpg', 'png'), 'precondition: matrix built successfully first');

        unlink($path);

        self::assertTrue(
            $registry->isSupported('jpg', 'png'),
            'second call must reuse the in-memory matrix, not re-read the (now missing) file',
        );
        self::assertSame(FileCategory::Image, $registry->getCategory('jpg', 'png'));
    }
}

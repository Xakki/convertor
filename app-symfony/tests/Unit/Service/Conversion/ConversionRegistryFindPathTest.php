<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Enum\FileCategory;
use App\Service\Conversion\ConversionRegistry;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 (CNV-5): {@see ConversionRegistry::findPath()} — BFS over the routing
 * matrix. Manager will prefer {@see ConversionRegistry::isSupported()} before
 * chaining; findPath may still return a length-1 direct edge.
 *
 * CNV-71-02: synthetic tiny matrices (BFS edge cases below — max-depth, AI-hop
 * preference, virtual-key exclusion) are no longer built by stubbing
 * `WorkerCapabilityRepository` (the routing matrix doesn't read it anymore) —
 * {@see registryFromPairs()} writes an already-resolved pair list to a temp
 * catalog JSON and points a fresh `ConversionRegistry` at it, same shape as
 * the real `config/catalog/conversion_pairs.json`.
 */
final class ConversionRegistryFindPathTest extends TestCase
{
    use SeedsConversionRegistry;

    /** @var list<string> temp files created by {@see registryFromPairs()}, removed in tearDown() */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];

        parent::tearDown();
    }

    public function testDirectEdgeIsLengthOnePath(): void
    {
        $registry = $this->newSeedRegistry();

        $path = $registry->findPath('jpg', 'png');

        self::assertNotNull($path);
        self::assertCount(1, $path);
        self::assertSame('jpg', $path[0]['from']);
        self::assertSame('png', $path[0]['to']);
        self::assertFalse($path[0]['isAi']);
        self::assertInstanceOf(FileCategory::class, $path[0]['category']);
        self::assertTrue($registry->isSupported('jpg', 'png'));
    }

    public function testSameFormatReturnsNull(): void
    {
        $registry = $this->newSeedRegistry();

        self::assertNull($registry->findPath('jpg', 'jpg'));
    }

    public function testNoPathWithinMaxDepthReturnsNull(): void
    {
        $registry = $this->registryFromPairs([
            ['from' => 'jpg', 'to' => 'png', 'category' => 'image', 'isAi' => false],
        ]);

        self::assertNull($registry->findPath('jpg', 'webp'));
        self::assertNull($registry->findPath('jpg', 'webp', 1));
    }

    public function testTwoHopChainFromSeedMatrix(): void
    {
        $registry = $this->newSeedRegistry();

        // epub→pdf is NOT direct in the catalog document matrix; epub→docx→pdf is.
        self::assertFalse($registry->isSupported('epub', 'pdf'));

        $path = $registry->findPath('epub', 'pdf');

        self::assertNotNull($path);
        self::assertCount(2, $path);
        self::assertSame('epub', $path[0]['from']);
        self::assertSame('pdf', $path[1]['to']);
        self::assertSame($path[0]['to'], $path[1]['from']);
        self::assertTrue($registry->isSupported($path[0]['from'], $path[0]['to']));
        self::assertTrue($registry->isSupported($path[1]['from'], $path[1]['to']));
        self::assertFalse($path[0]['isAi']);
        self::assertFalse($path[1]['isAi']);
    }

    public function testMaxDepthOneRejectsTwoHopOnlyPair(): void
    {
        $registry = $this->newSeedRegistry();

        self::assertFalse($registry->isSupported('epub', 'pdf'));
        self::assertNull($registry->findPath('epub', 'pdf', 1));
    }

    public function testPrefersFewerAiHopsAmongEqualLengthPaths(): void
    {
        // a→c length-2: a→b→c (0 AI) vs a→x→c (1 AI hop on a→x). Prefer non-AI.
        $registry = $this->registryFromPairs([
            ['from' => 'a', 'to' => 'b', 'category' => 'document', 'isAi' => false],
            ['from' => 'b', 'to' => 'c', 'category' => 'document', 'isAi' => false],
            ['from' => 'x', 'to' => 'c', 'category' => 'document', 'isAi' => false],
            ['from' => 'a', 'to' => 'x', 'category' => 'document', 'isAi' => true],
        ]);

        $path = $registry->findPath('a', 'c');

        self::assertNotNull($path);
        self::assertCount(2, $path);
        self::assertSame('a', $path[0]['from']);
        self::assertSame('b', $path[0]['to']);
        self::assertSame('b', $path[1]['from']);
        self::assertSame('c', $path[1]['to']);
        self::assertFalse($path[0]['isAi']);
        self::assertFalse($path[1]['isAi']);
    }

    public function testReturnsAiPathWhenItIsTheOnlyOption(): void
    {
        $registry = $this->registryFromPairs([
            ['from' => 'x', 'to' => 'c', 'category' => 'document', 'isAi' => false],
            ['from' => 'a', 'to' => 'x', 'category' => 'document', 'isAi' => true],
        ]);

        $path = $registry->findPath('a', 'c');

        self::assertNotNull($path);
        self::assertCount(2, $path);
        self::assertTrue($path[0]['isAi']);
        self::assertFalse($path[1]['isAi']);
    }

    public function testExcludesUnderscoreVirtualKeys(): void
    {
        // Without the `_` filter, mp3→mp3_stt→txt would be a 2-hop path.
        // With the filter, only mp3→wav→txt remains.
        $registry = $this->registryFromPairs([
            ['from' => 'mp3', 'to' => 'mp3_stt', 'category' => 'audio', 'isAi' => false],
            ['from' => 'mp3', 'to' => 'wav', 'category' => 'audio', 'isAi' => false],
            ['from' => 'mp3_stt', 'to' => 'txt', 'category' => 'audio', 'isAi' => false],
            ['from' => 'wav', 'to' => 'txt', 'category' => 'audio', 'isAi' => false],
        ]);

        $path = $registry->findPath('mp3', 'txt');

        self::assertNotNull($path);
        self::assertCount(2, $path);
        self::assertSame('wav', $path[0]['to']);
        self::assertSame('wav', $path[1]['from']);

        // Endpoint itself virtual → null
        self::assertNull($registry->findPath('mp3_stt', 'txt'));
        self::assertNull($registry->findPath('mp3', 'mp3_stt'));

        // Only virtual bridge → no path
        $virtualOnly = $this->registryFromPairs([
            ['from' => 'mp3', 'to' => 'mp3_stt', 'category' => 'audio', 'isAi' => false],
            ['from' => 'mp3_stt', 'to' => 'txt', 'category' => 'audio', 'isAi' => false],
        ]);
        self::assertNull($virtualOnly->findPath('mp3', 'txt'));
    }

    public function testNeverInventsOcrOnlyEdges(): void
    {
        // isOcrSupported(jpg,txt) is true from the hard-coded OCR set, but the
        // pair is absent from this fixture matrix → findPath must not invent it.
        $registry = $this->registryFromPairs([
            ['from' => 'jpg', 'to' => 'png', 'category' => 'image', 'isAi' => false],
        ]);

        self::assertTrue($registry->isOcrSupported('jpg', 'txt'));
        self::assertFalse($registry->isSupported('jpg', 'txt'));
        self::assertNull($registry->findPath('jpg', 'txt'));
    }

    /**
     * CNV-71-02 review fix: пустой каталог — не легитимный вырожденный случай,
     * а громкая ошибка (см. {@see ConversionRegistry::loadCatalogMatrix()}), так
     * что `findPath()` до пустой матрицы просто не доходит — исключение летит
     * из построения матрицы раньше.
     */
    public function testEmptyMatrixThrows(): void
    {
        $registry = $this->registryFromPairs([]);

        $this->expectException(\RuntimeException::class);

        $registry->findPath('jpg', 'png');
    }

    public function testTwoRegistryInstancesAgreeOnSameCatalog(): void
    {
        // CNV-71-02: no more cross-request cache to warm — two independent
        // instances just both read the same committed catalog file and must
        // naturally agree; nothing to share/warm between them anymore.
        $registry = $this->newSeedRegistry();

        self::assertTrue($registry->isSupported('docx', 'pdf'));
        $direct = $registry->findPath('docx', 'pdf');
        self::assertNotNull($direct);
        self::assertCount(1, $direct);

        $registryB = $this->newSeedRegistry();
        self::assertTrue($registryB->isSupported('docx', 'pdf'));
        $directB = $registryB->findPath('docx', 'pdf');
        self::assertNotNull($directB);
        self::assertCount(1, $directB);
        self::assertSame($direct[0]['from'], $directB[0]['from']);
        self::assertSame($direct[0]['to'], $directB[0]['to']);
        self::assertSame($direct[0]['isAi'], $directB[0]['isAi']);
    }

    public function testFormatCaseMatchesIsSupportedStyle(): void
    {
        // Registry methods do not normalize case (Manager lowercases before call).
        $registry = $this->newSeedRegistry();

        self::assertFalse($registry->isSupported('JPG', 'PNG'));
        self::assertNull($registry->findPath('JPG', 'PNG'));
        self::assertTrue($registry->isSupported('jpg', 'png'));
        self::assertNotNull($registry->findPath('jpg', 'png'));
    }

    /**
     * @param list<array{from: string, to: string, category: string, isAi: bool}> $pairs
     */
    private function registryFromPairs(array $pairs): ConversionRegistry
    {
        $path             = sys_get_temp_dir() . '/find_path_test_' . bin2hex(random_bytes(8)) . '.json';
        $this->tmpFiles[] = $path;

        $encoded = array_map(
            static fn (array $p): array => [...$p, 'ocrCapable' => false],
            $pairs,
        );
        file_put_contents($path, json_encode($encoded, JSON_THROW_ON_ERROR));

        return new ConversionRegistry(catalogPath: $path);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\WorkerCapability;
use App\Enum\FileCategory;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Phase 0 (CNV-5): {@see ConversionRegistry::findPath()} — BFS over the routing
 * matrix. Manager will prefer {@see ConversionRegistry::isSupported()} before
 * chaining; findPath may still return a length-1 direct edge.
 */
final class ConversionRegistryFindPathTest extends TestCase
{
    use SeedsConversionRegistry;

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
        $registry = $this->registryFromCapabilities([
            $this->cap('image', false, ['jpg' => ['png']]),
        ]);

        self::assertNull($registry->findPath('jpg', 'webp'));
        self::assertNull($registry->findPath('jpg', 'webp', 1));
    }

    public function testTwoHopChainFromSeedMatrix(): void
    {
        $registry = $this->newSeedRegistry();

        // epub→pdf is NOT direct in the seed document matrix; epub→docx→pdf is.
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
        $registry = $this->registryFromCapabilities([
            $this->cap('document', false, [
                'a' => ['b'],
                'b' => ['c'],
                'x' => ['c'],
            ]),
            $this->cap('ai', true, [
                'a' => ['x'],
            ], [
                'a' => 'document',
            ]),
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
        $registry = $this->registryFromCapabilities([
            $this->cap('document', false, [
                'x' => ['c'],
            ]),
            $this->cap('ai', true, [
                'a' => ['x'],
            ], [
                'a' => 'document',
            ]),
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
        $registry = $this->registryFromCapabilities([
            $this->cap('audio', false, [
                'mp3'     => ['mp3_stt', 'wav'],
                'mp3_stt' => ['txt'],
                'wav'     => ['txt'],
            ]),
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
        $virtualOnly = $this->registryFromCapabilities([
            $this->cap('audio', false, [
                'mp3'     => ['mp3_stt'],
                'mp3_stt' => ['txt'],
            ]),
        ]);
        self::assertNull($virtualOnly->findPath('mp3', 'txt'));
    }

    public function testNeverInventsOcrOnlyEdges(): void
    {
        // isOcrSupported(jpg,txt) is true from the hard-coded OCR set, but the
        // pair is absent from this fixture matrix → findPath must not invent it.
        $registry = $this->registryFromCapabilities([
            $this->cap('image', false, ['jpg' => ['png']]),
        ]);

        self::assertTrue($registry->isOcrSupported('jpg', 'txt'));
        self::assertFalse($registry->isSupported('jpg', 'txt'));
        self::assertNull($registry->findPath('jpg', 'txt'));
    }

    public function testEmptyMatrixReturnsNull(): void
    {
        $registry = new ConversionRegistry();

        self::assertNull($registry->findPath('jpg', 'png'));
    }

    public function testUsesSameMatrixAsIsSupportedIncludingWarmCache(): void
    {
        $cache    = new ArrayAdapter();
        $registry = $this->newSeedRegistry($cache);

        // Cold build
        self::assertTrue($registry->isSupported('docx', 'pdf'));
        $direct = $registry->findPath('docx', 'pdf');
        self::assertNotNull($direct);
        self::assertCount(1, $direct);

        // Warm: second instance shares cache — findPath must still match isSupported
        $registryB = $this->newSeedRegistry($cache);
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
     * @param array<string, list<string>> $matrix
     * @param array<string, string>       $matrixCategories
     */
    private function cap(string $workerType, bool $isAi, array $matrix, array $matrixCategories = []): WorkerCapability
    {
        $blob = [
            'workerType'        => $workerType,
            'isAi'              => $isAi,
            'streams'           => [$workerType],
            'routingKeys'       => [$workerType],
            'matrix'            => $matrix,
            'matrix_categories' => $matrixCategories,
            'image'             => null,
            'version'           => 'test',
        ];

        $cap = $this->createStub(WorkerCapability::class);
        $cap->method('getWorkerType')->willReturn($workerType);
        $cap->method('getCapabilities')->willReturn($blob);

        return $cap;
    }

    /** @param WorkerCapability[] $capabilities */
    private function registryFromCapabilities(array $capabilities): ConversionRegistry
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn($capabilities);

        return new ConversionRegistry($repo);
    }
}

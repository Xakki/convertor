<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Enum\FileCategory;
use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Policy tests for {@see ConversionRegistry::reduceCapabilities()} — the pure
 * collision/precedence reduction over a list of (workerType, blob) pairs,
 * exercised here through its public entrypoint
 * {@see ConversionRegistry::getSupportedFormatsFromBlobs()} (no repository, no
 * DB, no container).
 *
 * RENAMED from `ConversionRegistryFallbackTest` (CNV-71-02): that file tested
 * "which source does the routing matrix come from" — DB-empty, DB-unreachable,
 * hardcoded-fallback selection, `cache.app` warm/cold behaviour. CNV-71-02
 * removed that entire question: the routing matrix now has exactly ONE source
 * (the committed static catalog `config/catalog/conversion_pairs.json`, see
 * `ConversionRegistry::loadCatalogMatrix()`), so there is no more
 * source-selection logic to test, and no more cross-request cache to warm/cold.
 * What's left and still genuinely worth covering in isolation is the REDUCTION
 * POLICY itself (non-AI beats AI, {@see ConversionRegistry::NON_AI_PRECEDENCE}
 * tie-break, AI `matrix_categories` resolution, multi-instance union) — that
 * logic still runs for real, at catalog-generation time
 * (`App\Command\GenerateConversionPairsCommand`), and is worth a fast, DB-free
 * regression suite independent of the 394-pair committed catalog's current
 * content. Catalog-loading failure modes (missing/malformed file) have their
 * own test file — {@see ConversionRegistryCatalogLoadingTest}.
 */
final class ConversionRegistryReduceCapabilitiesTest extends TestCase
{
    /**
     * @param list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $formats
     * @return array{category: string, isAi: bool}|null
     */
    private static function find(array $formats, string $from, string $to): ?array
    {
        foreach ($formats as $f) {
            if ($f['from'] === $from && $f['to'] === $to) {
                return ['category' => $f['category'], 'isAi' => $f['isAi']];
            }
        }

        return null;
    }

    public function testReducesPairsFromRegisteredBlobs(): void
    {
        $blob = [
            'workerType'  => 'image',
            'isAi'        => false,
            'streams'     => ['conv.image'],
            'routingKeys' => ['image'],
            'matrix'      => ['jpg' => ['png', 'webp']],
            'image'       => null,
            'version'     => null,
        ];

        $registry = new ConversionRegistry();
        $formats  = $registry->getSupportedFormatsFromBlobs([$blob]);

        // Пары, заявленные воркером, доступны
        self::assertSame(['category' => 'image', 'isAi' => false], self::find($formats, 'jpg', 'png'));
        self::assertSame(['category' => 'image', 'isAi' => false], self::find($formats, 'jpg', 'webp'));

        // Пары, которых нет в блобе, отсутствуют (audio-пара mp3→wav — не в этом воркере)
        self::assertNull(self::find($formats, 'mp3', 'wav'));

        // Без AI-воркера в списке блобов AI-пары отсутствуют; виртуальных ключей нет
        self::assertNull(self::find($formats, 'mp3_stt', 'txt'));
        self::assertNull(self::find($formats, 'mp3', 'txt'));
    }

    /** AI-воркер не вытесняет non-AI на ту же пару. */
    public function testNonAiWinsOverAiForSamePair(): void
    {
        $blobNonAi = [
            'workerType'  => 'image',
            'isAi'        => false,
            'streams'     => [],
            'routingKeys' => [],
            'matrix'      => ['jpg' => ['png']],
        ];
        $blobAi = [
            'workerType'  => 'ai',
            'isAi'        => true,
            'streams'     => [],
            'routingKeys' => [],
            'matrix'      => ['jpg' => ['png']],
        ];

        $registry = new ConversionRegistry();
        // AI-блоб первым в списке (должен быть отсортирован позже reduceCapabilities()).
        $formats = $registry->getSupportedFormatsFromBlobs([$blobAi, $blobNonAi]);

        $pair = self::find($formats, 'jpg', 'png');
        self::assertNotNull($pair);
        self::assertFalse($pair['isAi'], 'non-AI worker must win over AI for the same pair');
    }

    /**
     * registry-03 ревью-фикс: pdf→docx/md/txt легитимно объявлены ОБОИМИ
     * non-AI воркерами — document (plain poppler/pandoc text extraction) и
     * image (OCR-ветка, тоже принимает pdf source); флаг `ocr` выбирает
     * воркер/stream на бэке, оба воркера честно декларируют пару. Реальный
     * баг был в том, что победитель зависел от порядка блобов — проверяем
     * оба порядка и требуем document.
     */
    public function testDocumentWinsOverImageForOverlappingPdfPairs(): void
    {
        $documentBlob = [
            'workerType'  => 'document',
            'isAi'        => false,
            'streams'     => ['document'],
            'routingKeys' => ['document'],
            'matrix'      => ['pdf' => ['docx', 'md', 'txt']],
        ];
        $imageBlob = [
            'workerType'  => 'image',
            'isAi'        => false,
            'streams'     => ['image'],
            'routingKeys' => ['image'],
            'matrix'      => ['pdf' => ['docx', 'md', 'txt']],
        ];

        $registry = new ConversionRegistry();

        foreach ([[$documentBlob, $imageBlob], [$imageBlob, $documentBlob]] as $order) {
            $formats = $registry->getSupportedFormatsFromBlobs($order);

            foreach (['docx', 'md', 'txt'] as $to) {
                $pair = self::find($formats, 'pdf', $to);
                self::assertNotNull($pair, "pdf→{$to} must be present");
                self::assertSame(
                    'document',
                    $pair['category'],
                    "pdf→{$to} must route to document regardless of blob order",
                );
                self::assertFalse($pair['isAi']);
            }
        }
    }

    /**
     * registry-03 ревью-фикс #2: `categoryForStream()` — `FileCategory::from()`,
     * которая принимает ВСЕ 7 кейсов enum'а (включая `archive`/`markup`), не
     * только 5 сегодняшних worker-type. Если добавить новый `FileCategory`-кейс
     * и забыть про `NON_AI_PRECEDENCE`, два незалистенных типа при коллизии
     * молча упадут на `PHP_INT_MAX` и воспроизведут order-dependent баг,
     * который эта константа-список чинит — только за пределами видимых
     * сегодня типов. Тест защищает от такой тихой регрессии.
     */
    public function testNonAiPrecedenceCoversEveryFileCategoryCase(): void
    {
        $precedence = (new \ReflectionClass(ConversionRegistry::class))->getConstant('NON_AI_PRECEDENCE');
        self::assertIsArray($precedence);

        foreach (FileCategory::cases() as $case) {
            self::assertContains(
                $case->value,
                $precedence,
                "FileCategory::{$case->name} ('{$case->value}') has no NON_AI_PRECEDENCE rank",
            );
        }
    }

    /** AI-блоб зарегистрирован — matrix_categories используется для определения FileCategory. */
    public function testBuildsAiPairsFromBlobsWhenAiWorkerRegistered(): void
    {
        $aiBlob = [
            'workerType'  => 'ai',
            'isAi'        => true,
            'streams'     => ['conv.ai'],
            'routingKeys' => ['ai'],
            'matrix'      => [
                'mp3' => ['txt', 'srt', 'vtt'],
                'txt' => ['mp3', 'wav', 'ogg', 'json'],
            ],
            'matrix_categories' => ['mp3' => 'audio', 'txt' => 'document'],
        ];

        $registry = new ConversionRegistry();
        $formats  = $registry->getSupportedFormatsFromBlobs([$aiBlob]);

        // STT-пара
        $sttPair = self::find($formats, 'mp3', 'txt');
        self::assertSame(['category' => 'audio', 'isAi' => true], $sttPair);

        // TTS-пара
        $ttsPair = self::find($formats, 'txt', 'mp3');
        self::assertSame(['category' => 'document', 'isAi' => true], $ttsPair);

        // Embedding
        $embedPair = self::find($formats, 'txt', 'json');
        self::assertSame(['category' => 'document', 'isAi' => true], $embedPair);

        // Виртуальных ключей нет
        self::assertNull(self::find($formats, 'mp3_stt', 'txt'));
    }

    /**
     * AI-блоб зарегистрирован, но лишён matrix_categories:
     *   - пары этого источника отбрасываются (не попадают в матрицу);
     *   - выбрасывается warning в лог;
     *   - нет исключений (graceful degradation).
     */
    public function testAiWorkerWithoutMatrixCategoriesDropsPairsAndLogsWarning(): void
    {
        $aiBlob = [
            'workerType'  => 'ai',
            'isAi'        => true,
            'streams'     => ['conv.ai'],
            'routingKeys' => ['ai'],
            'matrix'      => [
                'mp3' => ['txt', 'srt', 'vtt'],  // нет ключа в matrix_categories → drop
                'txt' => ['mp3', 'wav', 'ogg'],   // нет ключа в matrix_categories → drop
            ],
            // matrix_categories отсутствует намеренно
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))   // по одному warn на каждый from без категории
            ->method('warning')
            ->with($this->stringContains('matrix_categories'));

        $registry = new ConversionRegistry(logger: $logger);
        $formats  = $registry->getSupportedFormatsFromBlobs([$aiBlob]);

        // Обе пары дропнуты — не crash, не mis-categorised
        self::assertNull(self::find($formats, 'mp3', 'txt'), 'pair must be dropped when matrix_categories missing');
        self::assertNull(self::find($formats, 'txt', 'mp3'), 'pair must be dropped when matrix_categories missing');
    }

    /**
     * AI-блоб зарегистрирован с частичным matrix_categories (один ключ пропущен):
     * только источники с известной категорией попадают в матрицу, остальные дропаются.
     */
    public function testAiWorkerWithPartialMatrixCategoriesDropsMissingKeys(): void
    {
        $aiBlob = [
            'workerType'  => 'ai',
            'isAi'        => true,
            'streams'     => ['conv.ai'],
            'routingKeys' => ['ai'],
            'matrix'      => [
                'mp3' => ['txt'],  // есть в matrix_categories → включается
                'wav' => ['txt'],  // НЕТ в matrix_categories → дропается
            ],
            'matrix_categories' => ['mp3' => 'audio'],  // wav отсутствует намеренно
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())   // только wav без категории
            ->method('warning')
            ->with($this->stringContains('matrix_categories'));

        $registry = new ConversionRegistry(logger: $logger);
        $formats  = $registry->getSupportedFormatsFromBlobs([$aiBlob]);

        self::assertSame(
            ['category' => 'audio', 'isAi' => true],
            self::find($formats, 'mp3', 'txt'),
            'mp3→txt must be included (category known)',
        );
        self::assertNull(self::find($formats, 'wav', 'txt'), 'wav→txt must be dropped (category unknown)');
    }

    /**
     * registry-02: два блоба одного workerType (два инстанса, напр. два хоста с
     * одинаковым воркером) — их непересекающиеся пары объединяются (union) в
     * итоговой матрице, а не перетирают друг друга.
     */
    public function testUnionsPairsFromTwoInstancesOfSameWorkerType(): void
    {
        $blobHostA = [
            'workerType'  => 'image',
            'isAi'        => false,
            'streams'     => ['conv.image'],
            'routingKeys' => ['image'],
            'matrix'      => ['jpg' => ['png']],
        ];
        $blobHostB = [
            'workerType'  => 'image',
            'isAi'        => false,
            'streams'     => ['conv.image'],
            'routingKeys' => ['image'],
            'matrix'      => ['webp' => ['gif']],
        ];

        $registry = new ConversionRegistry();
        $formats  = $registry->getSupportedFormatsFromBlobs([$blobHostA, $blobHostB]);

        // Обе пары, объявленные разными инстансами, доступны — union, не перетирание.
        self::assertSame(['category' => 'image', 'isAi' => false], self::find($formats, 'jpg', 'png'), 'pair from instance A must survive');
        self::assertSame(['category' => 'image', 'isAi' => false], self::find($formats, 'webp', 'gif'), 'pair from instance B must survive');
    }
}

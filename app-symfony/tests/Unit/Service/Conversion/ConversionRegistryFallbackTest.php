<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\WorkerCapability;
use App\Enum\FileCategory;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use App\Tests\Support\ConversionRegistrySeedFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Проверяет логику выбора источника матрицы в ConversionRegistry.
 *
 * registry-05: hardcoded fallback (`workerCapabilities()`/`buildMatrixFromHardcode()`)
 * удалён — БД единственный источник. Три вырожденных пути (repository=null,
 * DB-исключение, БД пуста) отдают ЧЕСТНУЮ пустую матрицу, НЕ подставное
 * значение — см. класс-докблок {@see ConversionRegistry::buildRoutingPairs()}.
 * Непустая БД по-прежнему строит матрицу как раньше — эти тесты не изменились.
 */
final class ConversionRegistryFallbackTest extends TestCase
{
    /** repository = null (тестовый конструктор без DI) — пустая матрица, без лога. */
    public function testEmptyMatrixWhenNoRepository(): void
    {
        $registry = new ConversionRegistry();

        self::assertSame([], $registry->getSupportedFormats());
        self::assertFalse($registry->isSupported('mp4', 'avi'));
    }

    /** БД недоступна (exception) — пустая матрица + громкий error-лог, не throw. */
    public function testEmptyMatrixAndLoudErrorWhenDbUnreachable(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willThrowException(new \RuntimeException('Connection refused'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('worker_capabilities'), $this->arrayHasKey('error'));

        $registry = new ConversionRegistry($repo, null, $logger);

        self::assertSame([], $registry->getSupportedFormats());
        self::assertFalse($registry->isSupported('jpg', 'png'));
    }

    /** БД пуста (таблица без строк) — пустая матрица + громкий error-лог, не throw. */
    public function testEmptyMatrixAndLoudErrorWhenDbEmpty(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('worker_capabilities'));

        $registry = new ConversionRegistry($repo, null, $logger);

        self::assertSame([], $registry->getSupportedFormats());
        self::assertFalse($registry->isSupported('docx', 'pdf'));
    }

    /**
     * Кеш не должен замораживать пустой/ошибочный результат (advisor review):
     * без этого guard'а кратковременный DB blip на "холодном" кеше заморозил
     * бы честную пустую матрицу на весь TTL (1ч) — секундный сбой превращался
     * бы в часовой отказ `/formats`. Две отдельные инстанции ConversionRegistry
     * с ОБЩИМ кеш-пулом симулируют два HTTP-запроса: первый ловит blip
     * (не кешируется), второй должен снова обратиться к БД и увидеть уже
     * восстановленные данные — а не застрявший пустой снапшот.
     */
    public function testCacheDoesNotPersistEmptyOrErrorResult(): void
    {
        $cache     = new ArrayAdapter();
        $callCount = 0;

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturnCallback(function () use (&$callCount): array {
            ++$callCount;
            if ($callCount === 1) {
                throw new \RuntimeException('transient DB blip');
            }

            return ConversionRegistrySeedFixture::capabilities();
        });

        // "Запрос 1": холодный кеш, БД временно недоступна → пустая матрица.
        $registryA = new ConversionRegistry($repo, $cache);
        self::assertFalse($registryA->isSupported('jpg', 'png'));
        self::assertSame(1, $callCount);

        // "Запрос 2": тот же кеш-пул, БД уже восстановилась → матрица ДОЛЖНА
        // быть перестроена (не унаследовать замороженный пустой снапшот).
        $registryB = new ConversionRegistry($repo, $cache);
        self::assertTrue($registryB->isSupported('jpg', 'png'));
        self::assertSame(2, $callCount, 'blip must NOT have been cached — DB must be re-queried');
    }

    /** БД содержит данные — матрица строится из БД. */
    public function testBuildsMatrixFromDbWhenNonEmpty(): void
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

        $cap = $this->createStub(WorkerCapability::class);
        $cap->method('getWorkerType')->willReturn('image');
        $cap->method('getCapabilities')->willReturn($blob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$cap]);

        $registry = new ConversionRegistry($repo);

        // Пары, заявленные воркером, доступны
        self::assertTrue($registry->isSupported('jpg', 'png'));
        self::assertTrue($registry->isSupported('jpg', 'webp'));
        self::assertSame('image', $registry->streamFor('jpg', 'png'));

        // Пары, которых нет в DB matrix, должны отсутствовать
        // (audio-пара mp3→wav — не в этом воркере)
        self::assertFalse($registry->isSupported('mp3', 'wav'));

        // Без AI-воркера в БД AI-пары отсутствуют; виртуальных ключей нет
        self::assertFalse($registry->isSupported('mp3_stt', 'txt'), 'virtual STT key must not exist');
        self::assertFalse($registry->isSupported('mp3', 'txt'), 'mp3→txt not in this registry (no AI worker in DB)');
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

        $capNonAi = $this->createStub(WorkerCapability::class);
        $capNonAi->method('getWorkerType')->willReturn('image');
        $capNonAi->method('getCapabilities')->willReturn($blobNonAi);

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($blobAi);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        // AI-воркер первым в массиве (должен быть отсортирован позже)
        $repo->method('findAllCapabilities')->willReturn([$capAi, $capNonAi]);

        $registry = new ConversionRegistry($repo);

        self::assertTrue($registry->isSupported('jpg', 'png'));
        self::assertFalse($registry->isAi('jpg', 'png'), 'non-AI worker must win over AI for the same pair');
    }

    /**
     * registry-03 ревью-фикс: pdf→docx/md/txt легитимно объявлены ОБОИМИ
     * non-AI воркерами — document (plain poppler/pandoc text extraction) и
     * image (OCR-ветка, тоже принимает pdf source); флаг `ocr` выбирает
     * воркер/stream на бэке, оба воркера честно декларируют пару. Реальный
     * баг был в том, что победитель зависел от порядка рядов из БД —
     * проверяем оба порядка и требуем document.
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

        $capDocument = $this->createStub(WorkerCapability::class);
        $capDocument->method('getWorkerType')->willReturn('document');
        $capDocument->method('getCapabilities')->willReturn($documentBlob);

        $capImage = $this->createStub(WorkerCapability::class);
        $capImage->method('getWorkerType')->willReturn('image');
        $capImage->method('getCapabilities')->willReturn($imageBlob);

        foreach ([[$capDocument, $capImage], [$capImage, $capDocument]] as $order) {
            $repo = $this->createStub(WorkerCapabilityRepository::class);
            $repo->method('findAllCapabilities')->willReturn($order);

            $registry = new ConversionRegistry($repo);

            foreach (['docx', 'md', 'txt'] as $to) {
                self::assertTrue($registry->isSupported('pdf', $to));
                self::assertSame(
                    'document',
                    $registry->getCategory('pdf', $to)->value,
                    "pdf→{$to} must route to document regardless of DB row order",
                );
                self::assertFalse($registry->isAi('pdf', $to));
                self::assertSame('document', $registry->streamFor('pdf', $to));
            }
        }
    }

    /**
     * registry-03 ревью-фикс #2: `categoryForStream()` — `FileCategory::from()`,
     * которая принимает ВСЕ 7 кейсов enum'а (включая `archive`/`markup`), не
     * только 5 сегодняшних worker-type. Если добавить новый `FileCategory`-кейс
     * и забыть про `NON_AI_PRECEDENCE`, два незалистенных типа при коллизии
     * молча упадут на `PHP_INT_MAX` и воспроизведут order-dependent баг,
     * который этот константа-список чинит — только за пределами видимых
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

    /** БД содержит AI-воркер — matrix_categories используется для определения FileCategory. */
    public function testBuildsAiPairsFromDbWhenAiWorkerRegistered(): void
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

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $registry = new ConversionRegistry($repo);

        // STT-пара
        self::assertTrue($registry->isSupported('mp3', 'txt'));
        self::assertTrue($registry->isAi('mp3', 'txt'));
        self::assertSame('audio', $registry->getCategory('mp3', 'txt')->value);
        self::assertSame('ai', $registry->streamFor('mp3', 'txt'));

        // TTS-пара
        self::assertTrue($registry->isSupported('txt', 'mp3'));
        self::assertTrue($registry->isAi('txt', 'mp3'));
        self::assertSame('document', $registry->getCategory('txt', 'mp3')->value);

        // Embedding
        self::assertTrue($registry->isSupported('txt', 'json'));
        self::assertTrue($registry->isAi('txt', 'json'));
        self::assertSame('document', $registry->getCategory('txt', 'json')->value);

        // Виртуальных ключей нет
        self::assertFalse($registry->isSupported('mp3_stt', 'txt'));
    }

    /**
     * AI-воркер зарегистрирован, но blob лишён matrix_categories:
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

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))   // по одному warn на каждый from без категории
            ->method('warning')
            ->with($this->stringContains('matrix_categories'));

        $registry = new ConversionRegistry($repo, null, $logger);

        // Обе пары дропнуты — не crash, не mis-categorised
        self::assertFalse($registry->isSupported('mp3', 'txt'), 'pair must be dropped when matrix_categories missing');
        self::assertFalse($registry->isSupported('txt', 'mp3'), 'pair must be dropped when matrix_categories missing');
    }

    /**
     * AI-воркер зарегистрирован с частичным matrix_categories (один ключ пропущен):
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

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())   // только wav без категории
            ->method('warning')
            ->with($this->stringContains('matrix_categories'));

        $registry = new ConversionRegistry($repo, null, $logger);

        self::assertTrue($registry->isSupported('mp3', 'txt'), 'mp3→txt must be included (category known)');
        self::assertTrue($registry->isAi('mp3', 'txt'));
        self::assertFalse($registry->isSupported('wav', 'txt'), 'wav→txt must be dropped (category unknown)');
    }

    /**
     * registry-02: два ряда одного workerType (два инстанса, напр. два хоста с
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

        $capA = $this->createStub(WorkerCapability::class);
        $capA->method('getWorkerType')->willReturn('image');
        $capA->method('getCapabilities')->willReturn($blobHostA);

        $capB = $this->createStub(WorkerCapability::class);
        $capB->method('getWorkerType')->willReturn('image');
        $capB->method('getCapabilities')->willReturn($blobHostB);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capA, $capB]);

        $registry = new ConversionRegistry($repo);

        // Обе пары, объявленные разными инстансами, доступны — union, не перетирание.
        self::assertTrue($registry->isSupported('jpg', 'png'), 'pair from instance A must survive');
        self::assertTrue($registry->isSupported('webp', 'gif'), 'pair from instance B must survive');
        self::assertSame('image', $registry->streamFor('jpg', 'png'));
        self::assertSame('image', $registry->streamFor('webp', 'gif'));
    }

    /** invalidateMatrix() сбрасывает per-request кеш. */
    public function testInvalidateMatrixResetsPerRequestCache(): void
    {
        $callCount = 0;
        $repo      = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturnCallback(function () use (&$callCount): array {
            ++$callCount;

            return ConversionRegistrySeedFixture::capabilities();
        });

        $registry = new ConversionRegistry($repo);

        // (capabilities() непустой намеренно — с пустым результатом каждый вызов
        // сейчас логировал бы error(); здесь важен только счётчик обращений к БД.)
        // Первый доступ — строит матрицу (1 вызов к БД)
        $registry->isSupported('jpg', 'png');
        self::assertSame(1, $callCount);

        // Повторный доступ — per-request кеш, БД не запрашивается
        $registry->isSupported('jpg', 'png');
        self::assertSame(1, $callCount);

        // После invalidate — пересборка (ещё 1 вызов к БД)
        $registry->invalidateMatrix();
        $registry->isSupported('jpg', 'png');
        self::assertSame(2, $callCount);
    }
}

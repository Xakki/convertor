<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Проверяет логику выбора источника матрицы в ConversionRegistry:
 *   - БД пустая → hardcoded fallback;
 *   - БД недоступна (exception) → hardcoded fallback;
 *   - БД содержит записи → матрица строится из БД.
 */
final class ConversionRegistryFallbackTest extends TestCase
{
    /** БД пустая — должен использоваться hardcoded fallback. */
    public function testUsesHardcodedFallbackWhenDbEmpty(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([]);

        $registry = new ConversionRegistry($repo);

        // Пары из hardcoded matrix присутствуют
        self::assertTrue($registry->isSupported('jpg', 'png'));
        self::assertTrue($registry->isSupported('mp3', 'wav'));
        self::assertTrue($registry->isSupported('docx', 'pdf'));
        // AI-пары теперь плоские: mp3→txt (STT, isAi=true, category=audio)
        self::assertTrue($registry->isSupported('mp3', 'txt'));
        self::assertTrue($registry->isAi('mp3', 'txt'));
        self::assertSame('audio', $registry->getCategory('mp3', 'txt')->value);
        // TTS: txt→mp3 (isAi=true, category=document)
        self::assertTrue($registry->isSupported('txt', 'mp3'));
        self::assertTrue($registry->isAi('txt', 'mp3'));
        self::assertSame('document', $registry->getCategory('txt', 'mp3')->value);
        // Виртуальные ключи удалены
        self::assertFalse($registry->isSupported('mp3_stt', 'txt'), 'virtual STT key must not exist');
        self::assertFalse($registry->isSupported('txt_tts', 'mp3'), 'virtual TTS key must not exist');
    }

    /** БД недоступна (exception) — должен использоваться hardcoded fallback. */
    public function testUsesHardcodedFallbackWhenDbUnreachable(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willThrowException(new \RuntimeException('Connection refused'));

        $registry = new ConversionRegistry($repo);

        self::assertTrue($registry->isSupported('jpg', 'png'));
        self::assertSame('image', $registry->streamFor('jpg', 'png'));
        self::assertSame('document', $registry->streamFor('docx', 'pdf'));
    }

    /** repository = null (unit test без DI) — должен использоваться hardcoded fallback. */
    public function testUsesHardcodedFallbackWithNoRepository(): void
    {
        $registry = new ConversionRegistry();

        self::assertTrue($registry->isSupported('mp4', 'avi'));
        self::assertSame('video', $registry->streamFor('mp4', 'avi'));
    }

    /** БД содержит данные — матрица строится из БД, не из hardcode. */
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

    /** AI-пары из DB-пути идентичны AI-парам из hardcoded fallback. */
    public function testDbPathAiPairsMatchHardcodedFallback(): void
    {
        $aiBlob = [
            'workerType'  => 'ai',
            'isAi'        => true,
            'streams'     => ['conv.ai'],
            'routingKeys' => ['ai'],
            'matrix'      => [
                'mp3'  => ['txt', 'srt', 'vtt'],
                'wav'  => ['txt', 'srt', 'vtt'],
                'ogg'  => ['txt', 'srt', 'vtt'],
                'm4a'  => ['txt', 'srt', 'vtt'],
                'opus' => ['txt', 'srt', 'vtt'],
                'flac' => ['txt', 'srt', 'vtt'],
                'txt'  => ['mp3', 'wav', 'ogg', 'json'],
                'md'   => ['mp3', 'wav', 'ogg'],
            ],
            'matrix_categories' => [
                'mp3'  => 'audio',
                'wav'  => 'audio',
                'ogg'  => 'audio',
                'm4a'  => 'audio',
                'opus' => 'audio',
                'flac' => 'audio',
                'txt'  => 'document',
                'md'   => 'document',
            ],
        ];

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $dbRegistry  = new ConversionRegistry($repo);
        $hardcodeReg = new ConversionRegistry();

        $key      = static fn (array $e): string => "{$e['from']}→{$e['to']}|{$e['category']}";
        $aiFilter = static fn (array $e): bool => $e['isAi'];

        $dbKeys       = array_map($key, array_values(array_filter($dbRegistry->getSupportedFormats(), $aiFilter)));
        $hardcodeKeys = array_map($key, array_values(array_filter($hardcodeReg->getSupportedFormats(), $aiFilter)));
        sort($dbKeys);
        sort($hardcodeKeys);

        self::assertSame($hardcodeKeys, $dbKeys, 'AI pairs from DB path must match hardcoded fallback');
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

    /** invalidateMatrix() сбрасывает per-request кеш. */
    public function testInvalidateMatrixResetsPerRequestCache(): void
    {
        $callCount = 0;
        $repo      = $this->createMock(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturnCallback(function () use (&$callCount): array {
            ++$callCount;

            return [];
        });

        $registry = new ConversionRegistry($repo);

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

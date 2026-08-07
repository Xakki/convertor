<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет {@see ConversionRegistry::getCapabilityWarnings()} — admin-видимый
 * сигнал о AI-воркерах, чьи `matrix`-форматы молча дропались бы редукцией
 * (см. `reduceCapabilities()`) из-за отсутствующего/нерезолвящегося
 * `matrix_categories`. До этого сигнал тонул в `logger->warning` и не был
 * виден в admin-панели. Не затронуто CNV-71-02: этот метод по-прежнему читает
 * `WorkerCapabilityRepository` напрямую (live-диагностика воркеров), в
 * отличие от роутинг-матрицы.
 */
final class ConversionRegistryCapabilityWarningsTest extends TestCase
{
    /** Нет репозитория (unit-тест без DI) — warnings пустой. */
    public function testNoWarningsWithoutRepository(): void
    {
        $registry = new ConversionRegistry();

        self::assertSame([], $registry->getCapabilityWarnings());
    }

    /** БД пустая — warnings пустой. */
    public function testNoWarningsWhenDbEmpty(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([]);

        $registry = new ConversionRegistry($repo);

        self::assertSame([], $registry->getCapabilityWarnings());
    }

    /** БД недоступна (exception) — деградация без исключения, warnings пустой. */
    public function testNoWarningsWhenDbUnreachable(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willThrowException(new \RuntimeException('Connection refused'));

        $registry = new ConversionRegistry($repo);

        self::assertSame([], $registry->getCapabilityWarnings());
    }

    /** Non-AI воркер — category всегда резолвится из workerType, warnings не про него. */
    public function testNoWarningsForNonAiWorker(): void
    {
        $blob = [
            'workerType' => 'image',
            'isAi'       => false,
            'matrix'     => ['jpg' => ['png', 'webp']],
        ];

        $cap = $this->createStub(WorkerCapability::class);
        $cap->method('getWorkerType')->willReturn('image');
        $cap->method('getCapabilities')->willReturn($blob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$cap]);

        $registry = new ConversionRegistry($repo);

        self::assertSame([], $registry->getCapabilityWarnings());
    }

    /**
     * AI-воркер, non-empty matrix, ПОЛНОСТЬЮ отсутствует matrix_categories:
     * все from-форматы попадают в droppedFormats.
     */
    public function testReportsAllDroppedFormatsWhenMatrixCategoriesMissing(): void
    {
        $aiBlob = [
            'workerType' => 'ai',
            'isAi'       => true,
            'matrix'     => [
                'mp3' => ['txt', 'srt', 'vtt'],
                'txt' => ['mp3', 'wav', 'ogg'],
            ],
            // matrix_categories отсутствует намеренно
        ];

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $registry = new ConversionRegistry($repo);

        $warnings = $registry->getCapabilityWarnings();

        self::assertCount(1, $warnings);
        self::assertSame('ai', $warnings[0]['workerType']);
        self::assertSame(2, $warnings[0]['droppedCount']);
        self::assertSame(2, $warnings[0]['totalFormats']);
        self::assertEqualsCanonicalizing(['mp3', 'txt'], $warnings[0]['droppedFormats']);
    }

    /**
     * AI-воркер с частичным matrix_categories: только форматы без известной
     * категории попадают в droppedFormats, остальные — нет.
     */
    public function testReportsOnlyDroppedFormatsWhenMatrixCategoriesPartial(): void
    {
        $aiBlob = [
            'workerType' => 'ai',
            'isAi'       => true,
            'matrix'     => [
                'mp3' => ['txt'],
                'wav' => ['txt'],
            ],
            'matrix_categories' => ['mp3' => 'audio'], // wav отсутствует намеренно
        ];

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $registry = new ConversionRegistry($repo);

        $warnings = $registry->getCapabilityWarnings();

        self::assertCount(1, $warnings);
        self::assertSame(['wav'], $warnings[0]['droppedFormats']);
        self::assertSame(1, $warnings[0]['droppedCount']);
        self::assertSame(2, $warnings[0]['totalFormats']);
    }

    /** AI-воркер с ПОЛНЫМ matrix_categories — warnings пустой (ничего не дропнуто). */
    public function testNoWarningsWhenMatrixCategoriesComplete(): void
    {
        $aiBlob = [
            'workerType' => 'ai',
            'isAi'       => true,
            'matrix'     => [
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

        self::assertSame([], $registry->getCapabilityWarnings());
    }

    /** matrix_categories с нерезолвящимся значением (не audio/document) — тоже дроп. */
    public function testUnresolvableCategoryValueCountsAsDropped(): void
    {
        $aiBlob = [
            'workerType'        => 'ai',
            'isAi'              => true,
            'matrix'            => ['mp4' => ['txt']],
            'matrix_categories' => ['mp4' => 'video'], // не audio/document — нерезолвится
        ];

        $capAi = $this->createStub(WorkerCapability::class);
        $capAi->method('getWorkerType')->willReturn('ai');
        $capAi->method('getCapabilities')->willReturn($aiBlob);

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([$capAi]);

        $registry = new ConversionRegistry($repo);

        $warnings = $registry->getCapabilityWarnings();

        self::assertCount(1, $warnings);
        self::assertSame(['mp4'], $warnings[0]['droppedFormats']);
    }
}

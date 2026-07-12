<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Storage;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use App\Service\Storage\FileCleanupService;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Устойчивость авто-очистки к сбою S3: если deleteObject падает (транзиентная
 * ошибка / auth), прогон НЕ прерывается, а строка БД всё равно удаляется —
 * иначе «мёртвая» строка копится вечно (см. FileCleanupService docblock).
 */
final class FileCleanupServiceTest extends TestCase
{
    public function testS3DeleteFailureStillRemovesDbRow(): void
    {
        $input = (new FileStorage())
            ->setOriginalName('in.pdf')
            ->setStoragePath('inputs/x.pdf')
            ->setMimeType('application/pdf')
            ->setSizeBytes(10);

        // Completed + free-tariff + Document → все оси пусты → fallback 240 ч.
        // createdAt −1000 ч → строка устарела → подлежит удалению.
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getId')->willReturn(42);
        $conversion->method('getInputFile')->willReturn($input);
        $conversion->method('getOutputFile')->willReturn(null);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Completed);
        $conversion->method('getCategory')->willReturn(FileCategory::Document);
        $conversion->method('getUser')->willReturn((new User())->setPlan('free'));
        $conversion->method('getCreatedAt')->willReturn(new \DateTimeImmutable('-1000 hours'));

        // findExpiredCandidates: одна пачка, затем пусто (курсор двигается → цикл рвётся).
        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('findExpiredCandidates')->willReturnOnConsecutiveCalls([$conversion], []);

        // Стаб S3Client: deleteObject бросает — сервис обязан проглотить и продолжить.
        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('deleteObject')->willThrowException(new \RuntimeException('S3 down'));
        $storage = new S3Storage($s3Client, 'test_');

        // Несмотря на сбой S3 — строки удаляются и flush выполняется.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('remove'); // conversion + inputFile (output = null)
        $em->expects(self::once())->method('flush');
        $em->expects(self::once())->method('clear');

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $service = new FileCleanupService($registry, $repo, $storage, new NullLogger(), 240);
        $result  = $service->run();

        self::assertSame(1, $result['deleted']);
        self::assertSame(10, $result['bytesFreed']);
    }

    /**
     * Инвариант курсора (CRITICAL): пачка целиком из «пропущенных» (ещё не
     * доросших по своему порогу) строк НЕ останавливает прогон — `afterId`
     * двигается вперёд до проверки возраста, поэтому следующая пачка выбирается, а
     * не та же самая. Иначе — вечный цикл. Проверяем без БД: batchSize=1, три
     * последовательных вызова репозитория (skip → delete → пусто).
     */
    public function testSkippedBatchStillAdvancesCursor(): void
    {
        $freeUser = (new User())->setPlan('free');

        // Свежая строка (createdAt = −1 ч, порог 240) → ПРОПУСК, но курсор двигаем.
        $recent = $this->createStub(Conversion::class);
        $recent->method('getId')->willReturn(5);
        $recent->method('getStatus')->willReturn(ConversionStatus::Completed);
        $recent->method('getCategory')->willReturn(FileCategory::Document);
        $recent->method('getUser')->willReturn($freeUser);
        $recent->method('getCreatedAt')->willReturn(new \DateTimeImmutable('-1 hours'));

        // Старая строка (createdAt = −1000 ч) → удаляется.
        $oldInput = (new FileStorage())
            ->setOriginalName('in.bin')->setStoragePath('inputs/o.bin')
            ->setMimeType('application/octet-stream')->setSizeBytes(7);
        $old = $this->createStub(Conversion::class);
        $old->method('getId')->willReturn(9);
        $old->method('getStatus')->willReturn(ConversionStatus::Completed);
        $old->method('getCategory')->willReturn(FileCategory::Document);
        $old->method('getUser')->willReturn($freeUser);
        $old->method('getCreatedAt')->willReturn(new \DateTimeImmutable('-1000 hours'));
        $old->method('getInputFile')->willReturn($oldInput);
        $old->method('getOutputFile')->willReturn(null);

        // Три вызова: [recent] (skip), [old] (delete), [] (стоп). Если бы курсор не
        // двигался на skip — первая пачка вернулась бы снова, и вызовов было бы != 3.
        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects(self::exactly(3))->method('findExpiredCandidates')
            ->willReturnOnConsecutiveCalls([$recent], [$old], []);

        $s3Client = $this->createStub(S3Client::class);
        $storage  = new S3Storage($s3Client, 'test_');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('remove'); // только old: conv + input
        $em->expects(self::exactly(2))->method('flush');   // по пачке (пустая — до break)

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $service = new FileCleanupService($registry, $repo, $storage, new NullLogger(), 240, [], [], [], 1);
        $result  = $service->run();

        self::assertSame(1, $result['deleted']);
    }
}

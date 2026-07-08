<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Queue;

use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Service\Queue\ConversionResultPersister;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConversionResultPersisterTest extends TestCase
{
    private function makePersister(ManagerRegistry $registry, ?QuotaService $quota = null): ConversionResultPersister
    {
        return new ConversionResultPersister(
            $registry,
            'test-results',
            new NullLogger(),
            $quota ?? $this->createStub(QuotaService::class),
        );
    }

    private function makeRegistry(EntityManagerInterface $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        return $registry;
    }

    public function testUnknownConversionReturnsNormally(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects($this->never())->method('flush');

        $persister = $this->makePersister($this->makeRegistry($em));

        // Must not throw — the consumer ACKs and continues.
        $persister->persist(['conversionId' => 999, 'state' => 'completed', 'outputKey' => 'x.pdf']);

        $this->addToAssertionCount(1);
    }

    public function testMissingConversionIdThrows(): void
    {
        $em        = $this->createStub(EntityManagerInterface::class);
        $persister = $this->makePersister($this->makeRegistry($em));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/conversionId/');

        $persister->persist(['state' => 'completed']);
    }

    public function testIdempotencySkipsTerminalConversion(): void
    {
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Completed);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->never())->method('flush');

        $persister = $this->makePersister($this->makeRegistry($em));
        $persister->persist(['conversionId' => 1, 'state' => 'completed', 'outputKey' => 'x.pdf']);

        $this->addToAssertionCount(1);
    }

    public function testIdempotencySkipsFailedConversionNoDoubleRefund(): void
    {
        // Регрессия (A4, money-path): дубль-доставка задачи, уже в терминальном
        // Failed, НЕ должна повторно вернуть квоту. Guard (ConversionResultPersister.php)
        // покрывает оба терминала — Completed и Failed; это защита от регресса.
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Failed);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->never())->method('flush');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('refund');

        $persister = $this->makePersister($this->makeRegistry($em), $quota);
        $persister->persist(['conversionId' => 1, 'state' => 'failed', 'error' => 'boom']);

        $this->addToAssertionCount(1);
    }

    public function testEmIsObtainedFromRegistryOnEachCall(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->exactly(2))->method('getManager')->willReturn($em);

        $persister = new ConversionResultPersister($registry, 'test-results', new NullLogger(), $this->createStub(QuotaService::class));

        $persister->persist(['conversionId' => 1, 'state' => 'completed', 'outputKey' => 'x.pdf']);
        $persister->persist(['conversionId' => 2, 'state' => 'completed', 'outputKey' => 'y.pdf']);
    }

    public function testFailedStateRefundsQuotaAndFlushesOnce(): void
    {
        $user = new User();

        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Processing);
        $conversion->method('getUser')->willReturn($user);
        $conversion->method('isAi')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->once())->method('flush');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('refund')->with($user, false);

        $persister = $this->makePersister($this->makeRegistry($em), $quota);
        $persister->persist(['conversionId' => 1, 'state' => 'failed', 'error' => 'boom']);
    }
}

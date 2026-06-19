<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Queue;

use App\Entity\Conversion;
use App\Enum\ConversionStatus;
use App\Service\Queue\ConversionResultPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConversionResultPersisterTest extends TestCase
{
    private function makePersister(ManagerRegistry $registry): ConversionResultPersister
    {
        return new ConversionResultPersister($registry, 'test-results', new NullLogger());
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
        $em = $this->createStub(EntityManagerInterface::class);
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

    public function testEmIsObtainedFromRegistryOnEachCall(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->exactly(2))->method('getManager')->willReturn($em);

        $persister = new ConversionResultPersister($registry, 'test-results', new NullLogger());

        $persister->persist(['conversionId' => 1, 'state' => 'completed', 'outputKey' => 'x.pdf']);
        $persister->persist(['conversionId' => 2, 'state' => 'completed', 'outputKey' => 'y.pdf']);
    }
}

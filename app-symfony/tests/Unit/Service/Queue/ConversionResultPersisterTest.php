<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Queue;

use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Event\ConversionCompleted;
use App\Repository\ConversionRepository;
use App\Service\Queue\ConversionResultPersister;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ConversionResultPersisterTest extends TestCase
{
    private function makePersister(
        ManagerRegistry $registry,
        ?QuotaService $quota = null,
        ?EventDispatcher $dispatcher = null,
        ?ConversionRepository $conversionRepository = null,
    ): ConversionResultPersister {
        return new ConversionResultPersister(
            $registry,
            'test-results',
            new NullLogger(),
            $quota                ?? $this->createStub(QuotaService::class),
            $dispatcher           ?? new EventDispatcher(),
            $conversionRepository ?? $this->createStub(ConversionRepository::class),
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
        $conversion->method('getChainId')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->never())->method('flush');

        $dispatcher = $this->createMock(EventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $persister = $this->makePersister($this->makeRegistry($em), null, $dispatcher);
        $persister->persist(['conversionId' => 1, 'state' => 'completed', 'outputKey' => 'x.pdf']);

        $this->addToAssertionCount(1);
    }

    public function testIdempotentCompletedRearmsChainAdvanceWhenNextPending(): void
    {
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Completed);
        $conversion->method('getChainId')->willReturn('chain-rearm');
        $conversion->method('getSequence')->willReturn(1);

        $next = $this->createStub(Conversion::class);
        $next->method('getStatus')->willReturn(ConversionStatus::Pending);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())
            ->method('findNextPendingHop')
            ->with('chain-rearm', 1)
            ->willReturn($next);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->never())->method('flush');

        $dispatched = null;
        $dispatcher = $this->createMock(EventDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatched): object {
                $dispatched = $event;

                return $event;
            },
        );

        $persister = $this->makePersister($this->makeRegistry($em), null, $dispatcher, $repo);
        $persister->persist(['conversionId' => 1, 'state' => 'completed', 'outputKey' => 'x.pdf']);

        self::assertInstanceOf(ConversionCompleted::class, $dispatched);
    }

    public function testIdempotentCompletedDoesNotRearmWithoutPendingNext(): void
    {
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Completed);
        $conversion->method('getChainId')->willReturn('chain-done');
        $conversion->method('getSequence')->willReturn(2);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())
            ->method('findNextPendingHop')
            ->with('chain-done', 2)
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->never())->method('flush');

        $dispatcher = $this->createMock(EventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $persister = $this->makePersister($this->makeRegistry($em), null, $dispatcher, $repo);
        $persister->persist(['conversionId' => 2, 'state' => 'completed', 'outputKey' => 'x.pdf']);
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

        $persister = new ConversionResultPersister(
            $registry,
            'test-results',
            new NullLogger(),
            $this->createStub(QuotaService::class),
            new EventDispatcher(),
            $this->createStub(ConversionRepository::class),
        );

        $persister->persist(['conversionId' => 1, 'state' => 'completed', 'outputKey' => 'x.pdf']);
        $persister->persist(['conversionId' => 2, 'state' => 'completed', 'outputKey' => 'y.pdf']);
    }

    /**
     * requeue-attempt-generation-marker MAJOR #2: a result/fail event carrying an
     * `attempt` OLDER than the row's current attempt targets an attempt an
     * operator requeue already superseded (e.g. a delayed duplicate dlq-fail) —
     * must be a complete no-op: no status change, no refund, no flush. The row
     * is left exactly as the requeue set it (here: Processing, simulating a
     * fresh in-flight attempt).
     */
    public function testStaleAttemptIsIgnoredEntirely(): void
    {
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Processing);
        $conversion->method('getAttempt')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->expects($this->never())->method('flush');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('refund');

        $persister = $this->makePersister($this->makeRegistry($em), $quota);
        $persister->persist([
            'conversionId' => 1,
            'state'        => 'failed',
            'error'        => 'stale duplicate DLQ entry',
            'attempt'      => 0,
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * Boundary: `attempt` equal to (not older than) the row's current attempt is
     * NOT stale — normal finalization (including refund) proceeds as before.
     */
    public function testAttemptMatchingCurrentIsNotStale(): void
    {
        $user = new User();

        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Processing);
        $conversion->method('getAttempt')->willReturn(1);
        $conversion->method('getUser')->willReturn($user);
        $conversion->method('getCategory')->willReturn(FileCategory::Document);
        $conversion->method('isAi')->willReturn(false);
        $conversion->method('getEffectiveBillingMode')->willReturn(BillingMode::PlanQuota);
        $conversion->method('getId')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn): mixed => $fn($em));
        $em->expects($this->once())->method('flush');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('refund')->with($user, FileCategory::Document, false, BillingMode::PlanQuota, 1);

        $persister = $this->makePersister($this->makeRegistry($em), $quota);
        $persister->persist(['conversionId' => 1, 'state' => 'failed', 'error' => 'boom', 'attempt' => 1]);
    }

    /**
     * Backward-compat: `attempt` absent/null (legacy DLQ entries drained before
     * this field existed, and the jobId-keyed result()/fail() path which never
     * sends it) → the stale-guard is skipped entirely, behaving exactly as
     * before this change.
     */
    public function testNullAttemptBypassesStaleGuard(): void
    {
        $user = new User();

        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Processing);
        $conversion->method('getAttempt')->willReturn(3);
        $conversion->method('getUser')->willReturn($user);
        $conversion->method('getCategory')->willReturn(FileCategory::Document);
        $conversion->method('isAi')->willReturn(false);
        $conversion->method('getEffectiveBillingMode')->willReturn(BillingMode::PlanQuota);
        $conversion->method('getId')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn): mixed => $fn($em));
        $em->expects($this->once())->method('flush');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('refund')->with($user, FileCategory::Document, false, BillingMode::PlanQuota, 1);

        $persister = $this->makePersister($this->makeRegistry($em), $quota);
        // No 'attempt' key at all — mirrors fail()/result() (jobId-keyed path).
        $persister->persist(['conversionId' => 1, 'state' => 'failed', 'error' => 'boom']);
    }

    public function testFailedStateRefundsQuotaAndFlushesOnce(): void
    {
        $user = new User();

        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Processing);
        $conversion->method('getUser')->willReturn($user);
        $conversion->method('getCategory')->willReturn(FileCategory::Document);
        $conversion->method('isAi')->willReturn(false);
        $conversion->method('getEffectiveBillingMode')->willReturn(BillingMode::PlanQuota);
        $conversion->method('getId')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conversion);
        // Продакшн оборачивает refund+flush в wrapInTransaction (атомарность
        // decrement'а с переходом в терминальный статус). Мок обязан РЕАЛЬНО
        // выполнить замыкание, иначе внутренние refund()/flush() не сработают.
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn): mixed => $fn($em));
        $em->expects($this->once())->method('flush');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('refund')->with($user, FileCategory::Document, false, BillingMode::PlanQuota, 1);

        $persister = $this->makePersister($this->makeRegistry($em), $quota);
        $persister->persist(['conversionId' => 1, 'state' => 'failed', 'error' => 'boom']);
    }
}

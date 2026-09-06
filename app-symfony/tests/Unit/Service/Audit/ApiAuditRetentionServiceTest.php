<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Audit;

use App\Repository\ApiAuditRecordRepository;
use App\Service\Audit\ApiAuditRetentionService;
use PHPUnit\Framework\TestCase;

final class ApiAuditRetentionServiceTest extends TestCase
{
    public function testPurgeUsesStrictNinetyDayBoundary(): void
    {
        $repository = $this->createMock(ApiAuditRecordRepository::class);
        $repository->expects(self::once())->method('deleteOlderThan')
            ->with(self::callback(static fn (\DateTimeImmutable $threshold): bool => $threshold->format(DATE_ATOM) === '2026-06-07T12:00:00+00:00'))
            ->willReturn(3);

        self::assertSame(3, (new ApiAuditRetentionService($repository))->purge(new \DateTimeImmutable('2026-09-05T12:00:00+00:00')));
    }

    public function testRepeatedPurgeIsIdempotentAtTheRepositoryBoundary(): void
    {
        $repository = $this->createMock(ApiAuditRecordRepository::class);
        $repository->expects(self::exactly(2))->method('deleteOlderThan')->willReturn(0);
        $service = new ApiAuditRetentionService($repository);
        $now     = new \DateTimeImmutable('2026-09-05T12:00:00+00:00');

        self::assertSame(0, $service->purge($now));
        self::assertSame(0, $service->purge($now));
    }
}

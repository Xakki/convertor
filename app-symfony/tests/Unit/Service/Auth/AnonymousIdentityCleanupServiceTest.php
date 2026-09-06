<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Repository\UserRepository;
use App\Service\Auth\AnonymousIdentityCleanupService;
use PHPUnit\Framework\TestCase;

final class AnonymousIdentityCleanupServiceTest extends TestCase
{
    public function testCleanupDeactivatesGuestsAtRetentionBoundary(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())
            ->method('deactivateExpiredAnonymousIpGuests')
            ->with(self::callback(static fn (\DateTimeImmutable $cutoff): bool => $cutoff <= new \DateTimeImmutable('-30 days')
                && $cutoff > new \DateTimeImmutable('-31 days')))
            ->willReturn(3);

        $service = new AnonymousIdentityCleanupService($users, 30);

        self::assertSame(3, $service->run());
    }
}

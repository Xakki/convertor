<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;

final class AnonymousIdentityCleanupService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly int $retentionDays = 30,
    ) {
    }

    public function run(): int
    {
        return $this->users->deactivateExpiredAnonymousIpGuests(
            new \DateTimeImmutable(sprintf('-%d days', max(1, $this->retentionDays))),
        );
    }
}

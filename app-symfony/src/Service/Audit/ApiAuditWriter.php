<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\DTO\ApiAuditEvent;
use App\Repository\ApiAuditRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ApiAuditWriter implements ApiAuditWriterInterface
{
    public function __construct(private readonly ApiAuditRecordRepository $records, private readonly EntityManagerInterface $entityManager)
    {
    }

    public function append(int $ownerId, ApiAuditEvent $event): void
    {
        $owner = $this->entityManager->find(\App\Entity\User::class, $ownerId);
        if (! $owner instanceof \App\Entity\User || $owner->isGuest()) {
            return;
        }
        $this->records->append($owner, $event, true);
    }
}

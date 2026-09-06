<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\ApiAuditEvent;
use App\Entity\ApiAuditRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApiAuditRecord> */
class ApiAuditRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiAuditRecord::class);
    }

    public function append(User $owner, ApiAuditEvent $event, bool $flush = false): ApiAuditRecord
    {
        $record = new ApiAuditRecord($owner, $event);
        $this->getEntityManager()->persist($record);
        if ($flush) {
            $this->getEntityManager()->flush();
        }

        return $record;
    }

    /** @return list<ApiAuditRecord> */
    public function findForOwner(User $owner, int $limit = 20, ?\DateTimeImmutable $before = null, ?int $beforeId = null): array
    {
        $query = $this->createQueryBuilder('a')
            ->where('a.owner = :owner')->setParameter('owner', $owner)
            ->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)));
        if ($before !== null && $beforeId !== null) {
            $query->andWhere('(a.createdAt < :before OR (a.createdAt = :before AND a.id < :beforeId))')
                ->setParameter('before', $before)->setParameter('beforeId', $beforeId);
        }
        /** @var list<ApiAuditRecord> $records */
        $records = $query->getQuery()->getResult();

        return $records;
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $threshold = $threshold->setTimezone(new \DateTimeZone('UTC'));

        return $this->getEntityManager()->createQuery('DELETE FROM App\\Entity\\ApiAuditRecord a WHERE a.createdAt < :threshold')
            ->setParameter('threshold', $threshold)->execute();
    }
}

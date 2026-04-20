<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\ConversionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversion>
 */
class ConversionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversion::class);
    }

    /** @return Conversion[] */
    public function findByUser(User $user, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /** @return Conversion[] */
    public function findPending(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', ConversionStatus::Pending)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countTodayByUser(User $user, bool $isAi): int
    {
        $today = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.user = :user')
            ->andWhere('c.isAi = :isAi')
            ->andWhere('c.createdAt >= :today')
            ->andWhere('c.status != :failed')
            ->setParameter('user', $user)
            ->setParameter('isAi', $isAi)
            ->setParameter('today', $today)
            ->setParameter('failed', ConversionStatus::Failed)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Conversion $conversion, bool $flush = false): void
    {
        $this->getEntityManager()->persist($conversion);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

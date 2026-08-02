<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BalanceTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BalanceTransaction>
 */
class BalanceTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceTransaction::class);
    }

    /**
     * История ledger пользователя (свежие сверху).
     *
     * @return BalanceTransaction[]
     */
    public function findByUser(User $user, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('bt')
            ->where('bt.user = :user')
            ->setParameter('user', $user)
            ->orderBy('bt.createdAt', 'DESC')
            ->addOrderBy('bt.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();
    }

    public function save(BalanceTransaction $transaction, bool $flush = false): void
    {
        $this->getEntityManager()->persist($transaction);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonalApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PersonalApiToken> */
class PersonalApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalApiToken::class);
    }

    /** @return list<PersonalApiToken> */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('t')->where('t.user = :user')->andWhere('t.revokedAt IS NULL')->setParameter('user', $user)->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();
    }

    public function countActiveForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')->where('t.user = :user')->andWhere('t.revokedAt IS NULL')->setParameter('user', $user)->getQuery()->getSingleScalarResult();
    }

    public function findByVerifier(string $verifier): ?PersonalApiToken
    {
        return $this->createQueryBuilder('t')->where('t.verifier = :verifier')->andWhere('t.revokedAt IS NULL')->setParameter('verifier', $verifier)->getQuery()->getOneOrNullResult();
    }

    public function save(PersonalApiToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

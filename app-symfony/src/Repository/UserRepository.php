<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByTelegramId(string $telegramId): ?User
    {
        return $this->findOneBy(['telegramId' => $telegramId]);
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    /**
     * Активный гость по сырому guestId из cookie. Только isGuest+isActive:
     * после merge гость деактивируется и его guestId зануляется, поэтому
     * устаревшая cookie не воскресит удалённого гостя.
     */
    public function findActiveGuestByGuestId(string $guestId): ?User
    {
        return $this->findOneBy(['guestId' => $guestId, 'isGuest' => true, 'isActive' => true]);
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

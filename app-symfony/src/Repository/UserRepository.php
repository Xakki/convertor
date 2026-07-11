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

    // === Поиск/список для admin-панели (эпик admin-panel, подзадача users) ==

    /**
     * Поиск пользователей для admin-панели с пагинацией. `$q` (опц., trim'ится):
     *  - подстрока по email (LIKE `%q%`),
     *  - точное совпадение telegramId,
     *  - при числовом `$q` — ещё и точное совпадение id.
     * Пустой/`null` `$q` → весь список (пагинированный, свежие сверху).
     * Все условия параметризованы. `total` считается по тем же условиям.
     *
     * @return array{items: list<User>, total: int}
     */
    public function searchPaginated(?string $q, int $limit, int $offset): array
    {
        $qb    = $this->createQueryBuilder('u');
        $query = $q !== null ? trim($q) : '';

        if ($query !== '') {
            $or = $qb->expr()->orX(
                $qb->expr()->like('u.email', ':like'),
                $qb->expr()->eq('u.telegramId', ':exact'),
            );
            $qb->setParameter('like', '%' . $query . '%')
                ->setParameter('exact', $query);

            if (ctype_digit($query)) {
                $or->add($qb->expr()->eq('u.id', ':idExact'));
                $qb->setParameter('idExact', (int) $query);
            }

            $qb->where($or);
        }

        $countQb = (clone $qb)->select('COUNT(u.id)');
        $total   = (int) $countQb->getQuery()->getSingleScalarResult();

        /** @var list<User> $items */
        $items = $qb->orderBy('u.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    // === Агрегаты для admin-панели (эпик admin-panel, подзадача stats) ======

    /** Всего пользователей (включая гостей). */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Активные зарегистрированные пользователи (isActive, не гость). */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.isActive = true')
            ->andWhere('u.isGuest = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Гостевые пользователи (анонимные, привязка к cookie). */
    public function countGuests(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.isGuest = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ряд регистраций (не-гостей) по дням за последние $days суток. Пропущенные
     * дни — нули. Параметризованный нативный SQL (DATE() недоступен в DQL).
     *
     * ВНИМАНИЕ (tz): `:start` — PHP-локальная зона против серверного
     * `DATE(created_at)`; корректно, пока tz PHP == tz сессии БД. При проде-
     * расхождении границы суток сместятся (см. аналогичную заметку в
     * ConversionRepository::seriesByDay).
     *
     * @return array{labels: list<string>, counts: list<int>}
     */
    public function signupsByDay(int $days = 7): array
    {
        $days  = max(1, $days);
        $start = (new \DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');

        $sql = 'SELECT DATE(created_at) AS d, COUNT(id) AS cnt
                FROM users
                WHERE is_guest = 0 AND created_at >= :start
                GROUP BY DATE(created_at)';

        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['start' => $start->format('Y-m-d H:i:s')])
            ->fetchAllAssociative();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['d']] = (int) $row['cnt'];
        }

        $labels = [];
        $counts = [];
        for ($i = 0; $i < $days; $i++) {
            $day      = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $labels[] = $day;
            $counts[] = $byDate[$day] ?? 0;
        }

        return ['labels' => $labels, 'counts' => $counts];
    }
}

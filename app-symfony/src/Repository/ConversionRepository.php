<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
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

    /**
     * Конвертации, застрявшие в Pending/Processing дольше порога (DB-сигнал
     * зависших задач для admin-панели, эпик admin-panel, подзадача queues).
     * Сравнение по `updatedAt`: терминальный статус обновляет строку → выпадает
     * из выборки; «висяк» держит старый `updatedAt`.
     *
     * @return Conversion[]
     */
    public function findStuck(\DateTimeImmutable $threshold, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->andWhere('c.updatedAt < :threshold')
            ->setParameter('statuses', [ConversionStatus::Pending->value, ConversionStatus::Processing->value])
            ->setParameter('threshold', $threshold)
            ->orderBy('c.updatedAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /** Число застрявших конвертаций (см. {@see findStuck()}). */
    public function countStuck(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status IN (:statuses)')
            ->andWhere('c.updatedAt < :threshold')
            ->setParameter('statuses', [ConversionStatus::Pending->value, ConversionStatus::Processing->value])
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Поиск/фильтр/пагинация конвертаций для admin-панели логов (эпик
     * admin-panel, подзадача logs). Все условия параметризованы; `total`
     * считается по тем же условиям (COUNT до применения сортировки/лимита).
     * Сортировка — свежие сверху (`createdAt` DESC).
     *
     * Фильтры (все опциональны, null = не фильтровать):
     *  - `status`     — точный `ConversionStatus` (в т.ч. `Failed` = «только ошибки»);
     *  - `user`       — id (числовой q → id ИЛИ email exact) либо email (LIKE `%q%`);
     *  - `fromFormat` / `toFormat` — точное совпадение формата source/target;
     *  - `category`   — точный `FileCategory`;
     *  - `isAi` / `isOcr` — булев флаг;
     *  - `from`       — `createdAt >= from` (ожидается начало суток);
     *  - `to`         — `createdAt < to+1день` (инклюзивный календарный день).
     *
     * Связи `user`/`inputFile`/`outputFile` — все ManyToOne (to-one), поэтому
     * fetch-join безопасен с `setMaxResults` (пагинацию ломает только to-many) и
     * гасит N+1 при сериализации строк.
     *
     * @param array{
     *     status?: ConversionStatus|null,
     *     user?: string|null,
     *     fromFormat?: string|null,
     *     toFormat?: string|null,
     *     category?: FileCategory|null,
     *     isAi?: bool|null,
     *     isOcr?: bool|null,
     *     from?: \DateTimeImmutable|null,
     *     to?: \DateTimeImmutable|null,
     * } $filters
     *
     * @return array{items: list<Conversion>, total: int}
     */
    public function searchPaginated(array $filters, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u');

        if (($status = $filters['status'] ?? null) instanceof ConversionStatus) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $user = isset($filters['user']) ? trim((string) $filters['user']) : '';
        if ($user !== '') {
            if (ctype_digit($user)) {
                $qb->andWhere($qb->expr()->orX('u.id = :uid', 'u.email = :uemail'))
                    ->setParameter('uid', (int) $user)
                    ->setParameter('uemail', $user);
            } else {
                $qb->andWhere('u.email LIKE :ulike')->setParameter('ulike', '%' . $user . '%');
            }
        }

        if (($fromFormat = $filters['fromFormat'] ?? null) !== null && $fromFormat !== '') {
            $qb->andWhere('c.fromFormat = :fromFormat')->setParameter('fromFormat', $fromFormat);
        }
        if (($toFormat = $filters['toFormat'] ?? null) !== null && $toFormat !== '') {
            $qb->andWhere('c.toFormat = :toFormat')->setParameter('toFormat', $toFormat);
        }
        if (($category = $filters['category'] ?? null) instanceof FileCategory) {
            $qb->andWhere('c.category = :category')->setParameter('category', $category);
        }
        if (($isAi = $filters['isAi'] ?? null) !== null) {
            $qb->andWhere('c.isAi = :isAi')->setParameter('isAi', $isAi);
        }
        if (($isOcr = $filters['isOcr'] ?? null) !== null) {
            $qb->andWhere('c.isOcr = :isOcr')->setParameter('isOcr', $isOcr);
        }
        if (($from = $filters['from'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('c.createdAt >= :from')->setParameter('from', $from);
        }
        if (($to = $filters['to'] ?? null) instanceof \DateTimeImmutable) {
            // Инклюзивный календарный день: сравниваем с началом следующих суток.
            $qb->andWhere('c.createdAt < :toExclusive')
                ->setParameter('toExclusive', $to->modify('+1 day'));
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Conversion> $items */
        $items = $qb->addSelect('u')
            ->leftJoin('c.inputFile', 'inp')->addSelect('inp')
            ->leftJoin('c.outputFile', 'outp')->addSelect('outp')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function save(Conversion $conversion, bool $flush = false): void
    {
        $this->getEntityManager()->persist($conversion);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    // === Агрегаты для admin-панели (эпик admin-panel, подзадача stats) ======

    /** Всего конвертаций. */
    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Конвертаций с начала сегодняшнего дня. */
    public function countToday(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.createdAt >= :today')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ряд конвертаций по дням за последние $days суток (включая сегодня),
     * разбитый на обычные / AI. Пропущенные дни заполняются нулями, чтобы
     * график получал сплошную серию.
     *
     * DATE()-группировка недоступна в DQL, поэтому — параметризованный
     * нативный SQL (MariaDB). Форма ответа совместима с Chart.js-моком.
     *
     * ВНИМАНИЕ (tz): `:start` считается в PHP-локальной зоне и сравнивается с
     * серверным `DATE(created_at)`. Корректно, пока tz PHP == tz сессии БД
     * (сейчас совпадают). При расхождении в проде границы суток «поедут» — тогда
     * приводить обе стороны к общей зоне (CONVERT_TZ / хранить UTC).
     *
     * @return array{labels: list<string>, regular: list<int>, ai: list<int>}
     */
    public function seriesByDay(int $days = 7): array
    {
        $days  = max(1, $days);
        $start = (new \DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');

        $sql = 'SELECT DATE(created_at) AS d,
                       SUM(CASE WHEN is_ai = 0 THEN 1 ELSE 0 END) AS regular,
                       SUM(CASE WHEN is_ai = 1 THEN 1 ELSE 0 END) AS ai
                FROM conversions
                WHERE created_at >= :start
                GROUP BY DATE(created_at)';

        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['start' => $start->format('Y-m-d H:i:s')])
            ->fetchAllAssociative();

        /** @var array<string, array{regular: int, ai: int}> $byDate */
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['d']] = [
                'regular' => (int) $row['regular'],
                'ai'      => (int) $row['ai'],
            ];
        }

        $labels  = [];
        $regular = [];
        $ai      = [];
        for ($i = 0; $i < $days; $i++) {
            $day       = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $labels[]  = $day;
            $regular[] = $byDate[$day]['regular'] ?? 0;
            $ai[]      = $byDate[$day]['ai']      ?? 0;
        }

        return ['labels' => $labels, 'regular' => $regular, 'ai' => $ai];
    }

    /**
     * Счётчики по статусам: ключ — значение ConversionStatus, значение — count.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status, COUNT(c.id) AS cnt')
            ->groupBy('c.status')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $status    = $row['status'];
            $key       = $status instanceof ConversionStatus ? $status->value : (string) $status;
            $out[$key] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Топ пар форматов from→to по числу конвертаций.
     *
     * @return list<array{from: string, to: string, count: int}>
     */
    public function topFormatPairs(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.fromFormat AS fromFormat, c.toFormat AS toFormat, COUNT(c.id) AS cnt')
            ->groupBy('c.fromFormat')
            ->addGroupBy('c.toFormat')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => [
            'from'  => (string) $row['fromFormat'],
            'to'    => (string) $row['toFormat'],
            'count' => (int) $row['cnt'],
        ], $rows);
    }

    /**
     * Разбивка AI vs обычные.
     *
     * @return array{ai: int, regular: int}
     */
    public function countByAi(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.isAi AS isAi, COUNT(c.id) AS cnt')
            ->groupBy('c.isAi')
            ->getQuery()
            ->getResult();

        $out = ['ai' => 0, 'regular' => 0];
        foreach ($rows as $row) {
            $out[$row['isAi'] ? 'ai' : 'regular'] = (int) $row['cnt'];
        }

        return $out;
    }

    /** Среднее время обработки, мс (null если данных нет). */
    public function avgProcessingMs(): ?int
    {
        $value = $this->createQueryBuilder('c')
            ->select('AVG(c.processingMs)')
            ->getQuery()
            ->getSingleScalarResult();

        return $value === null ? null : (int) round((float) $value);
    }

    /** Доля упавших конвертаций (status=Failed) от всех, 0..1. */
    public function errorRate(): float
    {
        $total = $this->countTotal();
        if ($total === 0) {
            return 0.0;
        }

        $failed = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status = :failed')
            ->setParameter('failed', ConversionStatus::Failed)
            ->getQuery()
            ->getSingleScalarResult();

        return round($failed / $total, 4);
    }
}

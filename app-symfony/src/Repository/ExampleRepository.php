<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Example;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Example>
 */
class ExampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Example::class);
    }

    /**
     * Все примеры в порядке отображения витрины (карточка admin-managed-examples):
     * `sortOrder` ASC, при равенстве — старые (меньший id) раньше.
     *
     * @return list<Example>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.sortOrder', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whitelist-поиск результата по (category, filename) — та же роль, что и
     * {@see \App\Service\Examples\ExampleCatalog::find()} для публичного
     * файлового эндпоинта: ключ строится ТОЛЬКО из найденной строки, а не из
     * произвольного ввода запроса.
     */
    public function findOneByCategoryAndFilename(string $category, string $filename): ?Example
    {
        return $this->createQueryBuilder('e')
            ->where('e.category = :category')
            ->andWhere('e.filename = :filename')
            ->setParameter('category', $category)
            ->setParameter('filename', $filename)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whitelist-поиск исходника по (category, sourceFilename) — то же для
     * source-эндпоинта (home-10), что {@see findOneByCategoryAndFilename} для
     * результата.
     */
    public function findOneByCategoryAndSourceFilename(string $category, string $sourceFilename): ?Example
    {
        return $this->createQueryBuilder('e')
            ->where('e.category = :category')
            ->andWhere('e.sourceFilename = :sourceFilename')
            ->setParameter('category', $category)
            ->setParameter('sourceFilename', $sourceFilename)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Найти существующую строку по уже известному S3-ключу результата (upsert в SeedExamplesCommand). */
    public function findOneByResultKey(string $resultKey): ?Example
    {
        return $this->findOneBy(['resultKey' => $resultKey]);
    }

    /**
     * Следующий `sortOrder` для новой строки (admin-promote добавляет её в
     * конец витрины). MAX() возвращает null на пустой таблице → старт с 0.
     */
    public function nextSortOrder(): int
    {
        $max = $this->createQueryBuilder('e')
            ->select('MAX(e.sortOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max + 1);
    }

    public function save(Example $example, bool $flush = false): void
    {
        $this->getEntityManager()->persist($example);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Example $example, bool $flush = false): void
    {
        $this->getEntityManager()->remove($example);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

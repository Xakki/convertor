<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConversionToggle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversionToggle>
 */
class ConversionToggleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversionToggle::class);
    }

    public function findPair(string $fromFormat, string $toFormat): ?ConversionToggle
    {
        return $this->findOneBy(['fromFormat' => $fromFormat, 'toFormat' => $toFormat]);
    }

    /**
     * Ключи «from>to» всех явно отключённых пар (enabled=false). Только они меняют
     * поведение — включённые/отсутствующие пары в кеш disabled-set не попадают.
     *
     * @return list<string>
     */
    public function disabledPairKeys(): array
    {
        /** @var list<array{fromFormat: string, toFormat: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.fromFormat', 't.toFormat')
            ->where('t.enabled = false')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => $r['fromFormat'] . '>' . $r['toFormat'], $rows);
    }
}

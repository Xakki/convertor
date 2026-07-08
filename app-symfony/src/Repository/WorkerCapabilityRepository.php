<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkerCapability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerCapability>
 */
class WorkerCapabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerCapability::class);
    }

    /**
     * Обновляет или создаёт запись для данного типа воркера.
     * Повторная регистрация того же workerType обновляет данные, не дублирует ряд.
     *
     * @param array<string, mixed> $capabilities
     */
    public function upsert(string $workerType, array $capabilities): WorkerCapability
    {
        $cap = $this->findOneBy(['workerType' => $workerType]);

        if ($cap === null) {
            $cap = new WorkerCapability($workerType, $capabilities);
            $this->getEntityManager()->persist($cap);
        } else {
            $cap->update($capabilities);
        }

        $this->getEntityManager()->flush();

        return $cap;
    }

    /**
     * Возвращает все зарегистрированные типы воркеров.
     *
     * @return WorkerCapability[]
     */
    public function findAllCapabilities(): array
    {
        return $this->findAll();
    }
}

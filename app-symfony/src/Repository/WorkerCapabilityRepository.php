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
     * Обновляет или создаёт запись для данной пары (workerType, instanceId).
     * Повторная регистрация того же (workerType, instanceId) обновляет данные,
     * не дублирует ряд; разные instanceId одного workerType сосуществуют как
     * отдельные ряды.
     *
     * Реализовано нативным `INSERT ... ON DUPLICATE KEY UPDATE` (одним SQL-запросом,
     * без find-then-update) — снимает TOCTOU-гонку конкурентного register одного
     * ключа (Phase 1 carry-over): два одновременных запроса больше не могут упасть
     * на UNIQUE-конфликте между find и flush.
     *
     * @param array<string, mixed> $capabilities
     */
    public function upsert(string $workerType, string $instanceId, array $capabilities): WorkerCapability
    {
        $em   = $this->getEntityManager();
        $conn = $em->getConnection();
        $now  = new \DateTimeImmutable();

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO worker_capabilities (worker_type, instance_id, capabilities, last_seen)
                VALUES (:workerType, :instanceId, :capabilities, :lastSeen)
                ON DUPLICATE KEY UPDATE
                    capabilities = VALUES(capabilities),
                    last_seen = VALUES(last_seen)
                SQL,
            [
                'workerType'   => $workerType,
                'instanceId'   => $instanceId,
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
                'lastSeen'     => $now->format('Y-m-d H:i:s'),
            ],
        );

        $id = $conn->fetchOne(
            'SELECT id FROM worker_capabilities WHERE worker_type = :workerType AND instance_id = :instanceId',
            ['workerType' => $workerType, 'instanceId' => $instanceId],
        );
        if ($id === false) {
            throw new \RuntimeException('worker_capabilities row missing immediately after upsert');
        }

        $cap = $em->find(WorkerCapability::class, (int) $id);
        if ($cap === null) {
            throw new \RuntimeException('worker_capabilities row missing immediately after upsert');
        }
        // Строка могла уже быть в identity map (напр. загружена ранее в этом же
        // запросе) — ORM не перезатирает поля управляемой сущности данными из
        // повторного SELECT. refresh() гарантирует, что вернутся свежие данные,
        // только что записанные нативным SQL мимо UnitOfWork.
        $em->refresh($cap);

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

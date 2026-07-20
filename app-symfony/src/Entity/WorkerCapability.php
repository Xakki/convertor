<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkerCapabilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запись о возможностях одного типа воркера (один ряд на workerType).
 *
 * Используется ConversionRegistry для построения матрицы конвертаций из БД
 * вместо hardcoded workerCapabilities() (Phase 1: с fallback на hardcode при
 * пустой/недоступной БД). lastSeen — только для мониторинга; liveness не
 * используется для маршрутизации в Phase 1.
 */
#[ORM\Entity(repositoryClass: WorkerCapabilityRepository::class)]
#[ORM\Table(name: 'worker_capabilities')]
class WorkerCapability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /**
     * Суффикс потока — часть после `conv_` (напр. «image», «audio», «document»).
     * Уникальный ключ для upsert.
     */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $workerType;

    /**
     * Весь блоб возможностей воркера из тела register-запроса (isAi, streams,
     * routingKeys, matrix, matrix_categories, image, version).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $capabilities;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeen;

    /**
     * @param array<string, mixed> $capabilities
     */
    public function __construct(string $workerType, array $capabilities)
    {
        $this->workerType   = $workerType;
        $this->capabilities = $capabilities;
        $this->lastSeen     = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getWorkerType(): string
    {
        return $this->workerType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Обновляет возможности и метку lastSeen (при повторной регистрации).
     *
     * @param array<string, mixed> $capabilities
     */
    public function update(array $capabilities): void
    {
        $this->capabilities = $capabilities;
        $this->lastSeen     = new \DateTimeImmutable();
    }

    public function getLastSeen(): \DateTimeImmutable
    {
        return $this->lastSeen;
    }
}

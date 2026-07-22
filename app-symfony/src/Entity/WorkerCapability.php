<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WorkerLivenessStatus;
use App\Repository\WorkerCapabilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запись о возможностях одного инстанса воркера (ряд на пару (workerType, instanceId)).
 *
 * Несколько инстансов одного workerType могут сосуществовать (напр. два хоста
 * с одинаковым воркером, или ffmpeg, регистрирующий audio- и video-стрим раздельно
 * под разными workerType, но с общим instanceId процесса).
 *
 * Используется ConversionRegistry для построения матрицы конвертаций — БД
 * единственный источник (registry-05: hardcoded-фолбэк удалён; пустая/
 * недоступная БД отдаёт честную пустую матрицу, не подставное значение).
 * lastSeen — только для мониторинга; liveness не используется для
 * маршрутизации в Phase 1.
 */
#[ORM\Entity(repositoryClass: WorkerCapabilityRepository::class)]
#[ORM\Table(name: 'worker_capabilities')]
#[ORM\UniqueConstraint(name: 'UNIQ_WORKER_CAPABILITIES_TYPE_INSTANCE', columns: ['worker_type', 'instance_id'])]
class WorkerCapability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /**
     * Суффикс потока — часть после `conv_` (напр. «image», «audio», «document»).
     * Часть составного уникального ключа (workerType, instanceId) для upsert.
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $workerType;

    /**
     * Идентификатор конкретного инстанса воркера (генерируется Python-стороной,
     * стабилен между реконнектами того же процесса). Часть составного
     * уникального ключа (workerType, instanceId).
     */
    #[ORM\Column(type: 'string', length: 128)]
    private string $instanceId;

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
     * Liveness-статус (registry-06) — ОТДЕЛЬНЫЙ факт от {@see $lastSeen}, см.
     * {@see WorkerLivenessStatus}. НЕ входит в критерии выбора воркера для
     * маршрутизации — {@see \App\Service\Conversion\ConversionRegistry}
     * никогда не читает это поле. Дефолт `Alive` — намеренно НЕ конструкторный
     * параметр (см. {@see setStatus()}): реальная строка создаётся нативным
     * SQL в {@see WorkerCapabilityRepository::upsert()}, минуя этот
     * конструктор целиком; дефолт здесь имеет значение только для кода,
     * который строит `WorkerCapability` напрямую в PHP (тестовые фикстуры),
     * а не для реальных БД-строк — их статус всегда приходит из колонки.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: WorkerLivenessStatus::class)]
    private WorkerLivenessStatus $status = WorkerLivenessStatus::Alive;

    /**
     * @param array<string, mixed> $capabilities
     */
    public function __construct(string $workerType, string $instanceId, array $capabilities)
    {
        $this->workerType   = $workerType;
        $this->instanceId   = $instanceId;
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

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function getLastSeen(): \DateTimeImmutable
    {
        return $this->lastSeen;
    }

    public function getStatus(): WorkerLivenessStatus
    {
        return $this->status;
    }

    public function setStatus(WorkerLivenessStatus $status): self
    {
        $this->status = $status;

        return $this;
    }
}

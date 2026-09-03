<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HostTelemetrySnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HostTelemetrySnapshotRepository::class)]
#[ORM\Table(name: 'host_telemetry_snapshots')]
#[ORM\UniqueConstraint(name: 'UNIQ_HOST_TELEMETRY_HOST', columns: ['host_name'])]
class HostTelemetrySnapshot
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private int $id;
    #[ORM\Column(name: 'host_name', type: 'string', length: 253)]
    private string $hostName;
    #[ORM\Column(type: 'integer', nullable: true)] private ?int $cpuCount;
    #[ORM\Column(type: 'bigint', nullable: true)] private ?int $memTotalBytes;
    #[ORM\Column(type: 'bigint', nullable: true)] private ?int $memAvailableBytes;
    #[ORM\Column(type: 'bigint', nullable: true)] private ?int $diskTotalBytes;
    #[ORM\Column(type: 'bigint', nullable: true)] private ?int $diskUsedBytes;
    #[ORM\Column(type: 'float', nullable: true)] private ?float $load1;
    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $workers = [];
    #[ORM\Column(type: 'integer')] private int $contractVersion;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $observedAt;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $receivedAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $hostName, array $data, \DateTimeImmutable $observedAt, \DateTimeImmutable $receivedAt)
    {
        $this->hostName          = $hostName;
        $this->contractVersion   = (int) ($data['contractVersion'] ?? 0);
        $this->cpuCount          = self::intOrNull($data['cpuCount'] ?? null);
        $this->memTotalBytes     = self::intOrNull($data['memTotalBytes'] ?? null);
        $this->memAvailableBytes = self::intOrNull($data['memAvailableBytes'] ?? null);
        $this->diskTotalBytes    = self::intOrNull($data['diskTotalBytes'] ?? null);
        $this->diskUsedBytes     = self::intOrNull($data['diskUsedBytes'] ?? null);
        $this->load1             = is_numeric($data['load1'] ?? null) ? (float) $data['load1'] : null;
        $this->workers           = is_array($data['workers'] ?? null) ? $data['workers'] : [];
        $this->observedAt        = $observedAt;
        $this->receivedAt        = $receivedAt;
    }
    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
    public function getHostName(): string
    {
        return $this->hostName;
    }
    public function getObservedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }
    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return ['contractVersion' => $this->contractVersion,'host' => $this->hostName,'cpuCount' => $this->cpuCount,'memTotalBytes' => $this->memTotalBytes,'memAvailableBytes' => $this->memAvailableBytes,'diskTotalBytes' => $this->diskTotalBytes,'diskUsedBytes' => $this->diskUsedBytes,'load1' => $this->load1,'workers' => $this->workers,'observedAt' => $this->observedAt->format(\DateTimeInterface::ATOM),'receivedAt' => $this->receivedAt->format(\DateTimeInterface::ATOM)];
    }
}

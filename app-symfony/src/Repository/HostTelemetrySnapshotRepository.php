<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HostTelemetrySnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HostTelemetrySnapshot> */
final class HostTelemetrySnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostTelemetrySnapshot::class);
    }
    public function findByExactHost(string $host): ?HostTelemetrySnapshot
    {
        return $this->findOneBy(['hostName' => $host]);
    }
    /** @return list<HostTelemetrySnapshot> */
    public function findAllSnapshots(): array
    {
        return $this->findBy([], ['hostName' => 'ASC']);
    }
    public function save(HostTelemetrySnapshot $snapshot): void
    {
        $data = $snapshot->getData();
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('INSERT INTO host_telemetry_snapshots (host_name, contract_version, cpu_count, mem_total_bytes, mem_available_bytes, disk_total_bytes, disk_used_bytes, load1, workers, observed_at, received_at) VALUES (:host, :version, :cpu, :memTotal, :memAvailable, :diskTotal, :diskUsed, :load1, :workers, :observed, :received) ON DUPLICATE KEY UPDATE contract_version=IF(VALUES(observed_at) > observed_at, VALUES(contract_version), contract_version), cpu_count=IF(VALUES(observed_at) > observed_at, VALUES(cpu_count), cpu_count), mem_total_bytes=IF(VALUES(observed_at) > observed_at, VALUES(mem_total_bytes), mem_total_bytes), mem_available_bytes=IF(VALUES(observed_at) > observed_at, VALUES(mem_available_bytes), mem_available_bytes), disk_total_bytes=IF(VALUES(observed_at) > observed_at, VALUES(disk_total_bytes), disk_total_bytes), disk_used_bytes=IF(VALUES(observed_at) > observed_at, VALUES(disk_used_bytes), disk_used_bytes), load1=IF(VALUES(observed_at) > observed_at, VALUES(load1), load1), workers=IF(VALUES(observed_at) > observed_at, VALUES(workers), workers), received_at=IF(VALUES(observed_at) > observed_at, VALUES(received_at), received_at), observed_at=GREATEST(observed_at, VALUES(observed_at))', [
            'host' => $data['host'], 'version' => $data['contractVersion'], 'cpu' => $data['cpuCount'], 'memTotal' => $data['memTotalBytes'], 'memAvailable' => $data['memAvailableBytes'], 'diskTotal' => $data['diskTotalBytes'], 'diskUsed' => $data['diskUsedBytes'], 'load1' => $data['load1'], 'workers' => json_encode($data['workers'], JSON_THROW_ON_ERROR), 'observed' => $snapshot->getObservedAt()->format('Y-m-d H:i:s'), 'received' => $snapshot->getReceivedAt()->format('Y-m-d H:i:s'),
        ]);
        $this->getEntityManager()->clear();
    }
}

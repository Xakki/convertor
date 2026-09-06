<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

final class WorkerCapabilityFixture
{
    /** @var list<array{workerType: string, instanceId: string}> */
    private array $ownedRows = [];

    private readonly string $instancePrefix;

    public function __construct(
        private readonly WorkerCapabilityRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->instancePrefix = sprintf(
            'cnv-141-normal-%d-%s-',
            getmypid(),
            bin2hex(random_bytes(8)),
        );
    }

    public function addNormal(string $workerType): WorkerCapability
    {
        if (! in_array($workerType, ['document', 'image', 'video'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported normal worker type: %s', $workerType));
        }

        return $this->add(
            $workerType,
            isAi: false,
            matrix: [],
        );
    }

    public function addAi(): WorkerCapability
    {
        return $this->add(
            'ai',
            isAi: true,
            matrix: ['mp3' => ['txt']],
        );
    }

    /**
     * @param array<string, list<string>> $matrix
     */
    private function add(
        string $workerType,
        bool $isAi,
        array $matrix,
    ): WorkerCapability {
        $instanceId = $this->instancePrefix . $workerType . '-' . bin2hex(random_bytes(6));
        $capability = $this->repository->upsert(
            $workerType,
            $instanceId,
            [
                'workerType'  => $workerType,
                'instanceId'  => $instanceId,
                'isAi'        => $isAi,
                'streams'     => [$workerType],
                'routingKeys' => [$workerType],
                'matrix'      => $matrix,
            ],
        );

        $this->ownedRows[] = ['workerType' => $workerType, 'instanceId' => $instanceId];

        return $capability;
    }

    public function cleanup(): void
    {
        if ($this->ownedRows === []) {
            return;
        }

        $where  = [];
        $params = [];
        foreach (array_values($this->ownedRows) as $index => $row) {
            $where[]                      = "(worker_type = :workerType{$index} AND instance_id = :instanceId{$index})";
            $params["workerType{$index}"] = $row['workerType'];
            $params["instanceId{$index}"] = $row['instanceId'];
        }

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM worker_capabilities WHERE ' . implode(' OR ', $where),
            $params,
        );
        $this->ownedRows = [];
    }

    public function assertNoOwnedRowsRemain(): void
    {
        $remaining = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM worker_capabilities WHERE instance_id LIKE :prefix',
            ['prefix' => $this->instancePrefix . '%'],
        );

        Assert::assertSame(0, (int) $remaining, 'CNV-141 fixture rows remain after cleanup');
    }
}

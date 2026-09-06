<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Repository\ApiAuditRecordRepository;

final class ApiAuditRetentionService
{
    public const RETENTION_DAYS = 90;

    public function __construct(private readonly ApiAuditRecordRepository $records)
    {
    }

    public function purge(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $now->setTimezone(new \DateTimeZone('UTC'));

        return $this->records->deleteOlderThan($now->modify('-' . self::RETENTION_DAYS . ' days'));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\DTO\ApiAuditEvent;

interface ApiAuditWriterInterface
{
    public function append(int $ownerId, ApiAuditEvent $event): void;
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ApiAuditRetentionMessage;
use App\Service\Audit\ApiAuditRetentionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ApiAuditRetentionMessageHandler
{
    public function __construct(private readonly ApiAuditRetentionService $retention)
    {
    }
    public function __invoke(ApiAuditRetentionMessage $message): void
    {
        $this->retention->purge();
    }
}

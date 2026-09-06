<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AnonymousIdentityCleanupMessage;
use App\Service\Auth\AnonymousIdentityCleanupService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AnonymousIdentityCleanupMessageHandler
{
    public function __construct(private readonly AnonymousIdentityCleanupService $cleanup)
    {
    }

    public function __invoke(AnonymousIdentityCleanupMessage $message): void
    {
        $this->cleanup->run();
    }
}

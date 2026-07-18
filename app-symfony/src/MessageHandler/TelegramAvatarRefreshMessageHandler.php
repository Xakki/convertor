<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TelegramAvatarRefreshMessage;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramAvatarService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обработчик асинхронного обновления TG-аватара (hardening-09/nit-2). Запускается
 * вне request-цикла воркером `app-queue` (`messenger:consume async`, см.
 * docker/php/supervisor.app.ini). Пользователь мог быть удалён между диспатчем
 * и обработкой — тогда просто ничего не делаем (не фатально).
 */
#[AsMessageHandler]
final class TelegramAvatarRefreshMessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TelegramAvatarService $avatarService,
    ) {
    }

    public function __invoke(TelegramAvatarRefreshMessage $message): void
    {
        $user = $this->userRepository->find($message->userId);
        if ($user === null) {
            return;
        }

        // Best-effort и внутри самого TelegramAvatarService: ловит и логирует
        // любой сбой (нет фото, ошибка TG API/S3), наружу не бросает.
        $this->avatarService->refreshAvatar($user);
    }
}

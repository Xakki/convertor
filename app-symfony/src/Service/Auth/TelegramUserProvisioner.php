<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Находит или создаёт User по Telegram-id (для bot-login callback) и обогащает
 * профиль данными из Telegram: `first_name`, `username` (обновляются на каждом
 * логине — username в TG изменяем) и аватар (best-effort через
 * TelegramAvatarService, кеш в S3). Идентификатор пользователя — telegramId.
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock.
 */
class TelegramUserProvisioner
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly TelegramAvatarService $avatarService,
    ) {
    }

    public function findOrCreateUser(string $telegramId, ?string $username = null, ?string $firstName = null): User
    {
        $user = $this->userRepository->findByTelegramId($telegramId);
        if ($user === null) {
            $user = new User();
            $user->setTelegramId($telegramId);
            $this->em->persist($user);
        }

        // Обновляем профиль и на создании, и на повторном логине (данные в TG
        // меняются). Nullable — пользователь без username остаётся с null.
        $user->setUsername($username);
        $user->setFirstName($firstName);

        $this->em->flush();

        // Аватар — best-effort, не фатально: сам сервис глотает и логирует ошибки.
        $this->avatarService->refreshAvatar($user);

        return $user;
    }
}

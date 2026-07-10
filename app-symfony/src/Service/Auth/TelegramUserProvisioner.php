<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Находит или создаёт User по Telegram-id (для bot-login callback).
 *
 * username / first_name принимаем, но НЕ персистим — в User таких колонок нет,
 * а добавлять их вне scope этой карты (и коллизия с миграцией guest-модели
 * из параллельной карты A). Идентификатор пользователя — telegramId.
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock.
 */
class TelegramUserProvisioner
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findOrCreateUser(string $telegramId, ?string $username = null, ?string $firstName = null): User
    {
        $user = $this->userRepository->findByTelegramId($telegramId);
        if ($user !== null) {
            return $user;
        }

        $user = new User();
        $user->setTelegramId($telegramId);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

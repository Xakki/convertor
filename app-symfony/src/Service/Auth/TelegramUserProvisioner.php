<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Message\TelegramAvatarRefreshMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Находит или создаёт User по Telegram-id (для bot-login callback) и обогащает
 * профиль данными из Telegram: `first_name`, `username` (обновляются на каждом
 * логине — username в TG изменяем) и аватар. Идентификатор пользователя — telegramId.
 *
 * Аватар (hardening-09/nit-2): раньше {@see \App\Service\Auth\TelegramAvatarService::refreshAvatar()}
 * звался СИНХРОННО прямо здесь, в обработке webhook (+3 HTTP к Telegram + S3 put
 * в ответ на webhook — риск таймаута/ретрая). Теперь только диспатчится
 * {@see TelegramAvatarRefreshMessage} (транспорт `async`) — сама загрузка идёт
 * вне request-цикла в {@see \App\MessageHandler\TelegramAvatarRefreshMessageHandler}.
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock.
 */
class TelegramUserProvisioner
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
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

        // flush ДО dispatch: новому User нужен id (auto-increment), чтобы сообщение
        // несло валидный userId.
        $this->em->flush();

        // Аватар — вне hot-path вебхука, асинхронно (см. class doc).
        $this->bus->dispatch(new TelegramAvatarRefreshMessage((int) $user->getId()));

        return $user;
    }
}

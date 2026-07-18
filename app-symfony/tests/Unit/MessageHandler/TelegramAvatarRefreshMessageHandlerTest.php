<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\User;
use App\Message\TelegramAvatarRefreshMessage;
use App\MessageHandler\TelegramAvatarRefreshMessageHandler;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramAvatarService;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты TelegramAvatarRefreshMessageHandler (hardening-09/nit-2): загружает
 * User по id из сообщения и делегирует TelegramAvatarService::refreshAvatar().
 * Пользователь мог быть удалён между dispatch и обработкой — тогда no-op.
 */
final class TelegramAvatarRefreshMessageHandlerTest extends TestCase
{
    public function testLoadsUserAndDelegatesToAvatarService(): void
    {
        $user = (new User())->setTelegramId('12345');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('find')->with(42)->willReturn($user);

        $avatar = $this->createMock(TelegramAvatarService::class);
        $avatar->expects(self::once())->method('refreshAvatar')->with($user);

        (new TelegramAvatarRefreshMessageHandler($repo, $avatar))(new TelegramAvatarRefreshMessage(42));
    }

    public function testMissingUserIsNoOp(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('find')->with(99)->willReturn(null);

        $avatar = $this->createMock(TelegramAvatarService::class);
        $avatar->expects(self::never())->method('refreshAvatar');

        (new TelegramAvatarRefreshMessageHandler($repo, $avatar))(new TelegramAvatarRefreshMessage(99));
    }
}

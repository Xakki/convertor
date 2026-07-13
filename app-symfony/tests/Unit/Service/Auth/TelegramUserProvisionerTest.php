<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramAvatarService;
use App\Service\Auth\TelegramUserProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты TelegramUserProvisioner: username/first_name персистятся и на
 * создании нового юзера, и на повторном логине (обновление). Аватар — best-effort
 * через TelegramAvatarService (здесь стаб, сеть/S3 не трогаем).
 */
final class TelegramUserProvisionerTest extends TestCase
{
    public function testCreatesUserAndPersistsTelegramProfile(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findByTelegramId')->with('555')->willReturn(null);

        $persisted = null;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (User $u) use (&$persisted): void {
                $persisted = $u;
            },
        );
        $em->expects(self::once())->method('flush');

        $avatar = $this->createMock(TelegramAvatarService::class);
        $avatar->expects(self::once())->method('refreshAvatar');

        $provisioner = new TelegramUserProvisioner($repo, $em, $avatar);
        $user        = $provisioner->findOrCreateUser('555', 'ivan', 'Иван');

        self::assertSame($user, $persisted);
        self::assertSame('555', $user->getTelegramId());
        self::assertSame('ivan', $user->getUsername());
        self::assertSame('Иван', $user->getFirstName());
    }

    public function testExistingUserProfileIsRefreshedOnLogin(): void
    {
        $existing = (new User())->setTelegramId('777')->setUsername('old')->setFirstName('Старое');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findByTelegramId')->with('777')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        // Существующего не персистим заново, только flush обновлённых полей.
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');

        $avatar = $this->createMock(TelegramAvatarService::class);
        $avatar->expects(self::once())->method('refreshAvatar')->with($existing);

        $provisioner = new TelegramUserProvisioner($repo, $em, $avatar);
        $user        = $provisioner->findOrCreateUser('777', 'newname', 'Новое');

        self::assertSame($existing, $user);
        self::assertSame('newname', $user->getUsername());
        self::assertSame('Новое', $user->getFirstName());
    }

    public function testNullUsernameStaysNull(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findByTelegramId')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);

        $avatar = $this->createStub(TelegramAvatarService::class);

        $provisioner = new TelegramUserProvisioner($repo, $em, $avatar);
        $user        = $provisioner->findOrCreateUser('999', null, null);

        self::assertNull($user->getUsername());
        self::assertNull($user->getFirstName());
    }
}

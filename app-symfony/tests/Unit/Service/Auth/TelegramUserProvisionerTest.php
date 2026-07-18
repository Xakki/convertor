<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Message\TelegramAvatarRefreshMessage;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramUserProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Юнит-тесты TelegramUserProvisioner: username/first_name персистятся и на
 * создании нового юзера, и на повторном логине (обновление). Аватар (hardening-09/
 * nit-2) — АСИНХРОННО, диспатчем TelegramAvatarRefreshMessage через MessageBusInterface
 * (здесь стаб, сеть/S3/Redis не трогаем) — НЕ прямым вызовом TelegramAvatarService.
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
                // Имитируем присвоение id на flush (реальный ORM делает то же самое).
                (new \ReflectionProperty(User::class, 'id'))->setValue($u, 555001);
            },
        );
        $em->expects(self::once())->method('flush');

        $dispatched = null;
        $bus        = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $provisioner = new TelegramUserProvisioner($repo, $em, $bus);
        $user        = $provisioner->findOrCreateUser('555', 'ivan', 'Иван');

        self::assertSame($user, $persisted);
        self::assertSame('555', $user->getTelegramId());
        self::assertSame('ivan', $user->getUsername());
        self::assertSame('Иван', $user->getFirstName());

        self::assertInstanceOf(TelegramAvatarRefreshMessage::class, $dispatched);
        self::assertSame(555001, $dispatched->userId);
    }

    public function testExistingUserProfileIsRefreshedOnLogin(): void
    {
        $existing = (new User())->setTelegramId('777')->setUsername('old')->setFirstName('Старое');
        (new \ReflectionProperty(User::class, 'id'))->setValue($existing, 777);

        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findByTelegramId')->with('777')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        // Существующего не персистим заново, только flush обновлённых полей.
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');

        $dispatched = null;
        $bus        = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $provisioner = new TelegramUserProvisioner($repo, $em, $bus);
        $user        = $provisioner->findOrCreateUser('777', 'newname', 'Новое');

        self::assertSame($existing, $user);
        self::assertSame('newname', $user->getUsername());
        self::assertSame('Новое', $user->getFirstName());

        self::assertInstanceOf(TelegramAvatarRefreshMessage::class, $dispatched);
        self::assertSame(777, $dispatched->userId);
    }

    public function testNullUsernameStaysNull(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findByTelegramId')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $provisioner = new TelegramUserProvisioner($repo, $em, $bus);
        $user        = $provisioner->findOrCreateUser('999', null, null);

        self::assertNull($user->getUsername());
        self::assertNull($user->getFirstName());
    }

    /**
     * hardening-09/nit-2: сам факт, что аватар больше НЕ обновляется синхронно
     * внутри findOrCreateUser — только диспатчем сообщения. Ничего, кроме
     * MessageBusInterface::dispatch(), не должно быть вызвано для аватара:
     * тест выше уже покрывает это неявно (TelegramAvatarService даже не
     * инжектируется в конструктор), но здесь фиксируем явно через тип сообщения.
     */
    public function testAvatarRefreshIsDispatchedNotCalledInline(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findByTelegramId')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(
            static function (User $u): void {
                (new \ReflectionProperty(User::class, 'id'))->setValue($u, 42);
            },
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TelegramAvatarRefreshMessage::class))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $provisioner = new TelegramUserProvisioner($repo, $em, $bus);
        $provisioner->findOrCreateUser('42', null, null);
    }
}

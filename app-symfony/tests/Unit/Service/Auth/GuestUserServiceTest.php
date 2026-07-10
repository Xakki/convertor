<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\GuestUserService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

/**
 * mergeInto: перенос истории гостя на реального пользователя.
 */
final class GuestUserServiceTest extends TestCase
{
    public function testMergeReassignsConversionsAndDeactivatesGuest(): void
    {
        $guest = $this->makeGuest('g-123', id: 7);
        $real  = $this->makeReal(id: 42);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('findActiveGuestByGuestId')
            ->with('g-123')
            ->willReturn($guest);

        // Ожидаем bulk-UPDATE: createQuery(...)->setParameter(real)->setParameter(guest)->execute().
        $query = $this->createMock(Query::class);
        $query->expects($this->exactly(2))->method('setParameter')->willReturnSelf();
        $query->expects($this->once())->method('execute')->willReturn(3);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('createQuery')
            ->with($this->stringContains('UPDATE'))
            ->willReturn($query);
        $em->expects($this->once())->method('flush');

        (new GuestUserService($users, $em))->mergeInto($real, 'g-123');

        self::assertFalse($guest->isActive(), 'гость деактивирован');
        self::assertNull($guest->getGuestId(), 'guestId занулён, чтобы cookie не воскресила гостя');
    }

    public function testMergeIsNoOpWhenGuestNotFound(): void
    {
        $real = $this->makeReal(id: 42);

        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveGuestByGuestId')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('createQuery');
        $em->expects($this->never())->method('flush');

        (new GuestUserService($users, $em))->mergeInto($real, 'unknown');
    }

    public function testMergeIsNoOpWhenGuestIsSameAsReal(): void
    {
        // Защита от самослияния: guestId, указывающий на самого залогиненного.
        $real = $this->makeReal(id: 42);

        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveGuestByGuestId')->willReturn($real);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('createQuery');
        $em->expects($this->never())->method('flush');

        (new GuestUserService($users, $em))->mergeInto($real, 'g-self');
    }

    private function makeGuest(string $guestId, int $id): User
    {
        $u = (new User())->setIsGuest(true)->setGuestId($guestId);
        $this->setId($u, $id);

        return $u;
    }

    private function makeReal(int $id): User
    {
        $u = new User();
        $this->setId($u, $id);

        return $u;
    }

    private function setId(User $u, int $id): void
    {
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
    }
}

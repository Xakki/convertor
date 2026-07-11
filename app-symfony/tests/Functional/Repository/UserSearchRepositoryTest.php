<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Поиск/пагинация UserRepository::searchPaginated (эпик admin-panel, подзадача
 * users) против РЕАЛЬНОЙ тест-БД (convertor-test). Сеет юзеров с уникальным
 * маркером в email → поиск детерминирован в общей тест-БД; всё удаляется в конце.
 */
final class UserSearchRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $users;

    /** @var list<object> */
    private array $toRemove = [];

    private string $marker;

    protected function setUp(): void
    {
        self::bootKernel();
        $container    = static::getContainer();
        $this->em     = $container->get(EntityManagerInterface::class);
        $this->users  = $container->get(UserRepository::class);
        $this->marker = 'usrsrch-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->toRemove) as $entity) {
            if ($this->em->contains($entity)) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        $this->toRemove = [];

        parent::tearDown();
    }

    public function testSearchByEmailPartialMatch(): void
    {
        $u = $this->persistUser($this->marker . '@example.test');

        $res = $this->users->searchPaginated($this->marker, 20, 0);

        self::assertSame(1, $res['total']);
        self::assertCount(1, $res['items']);
        self::assertSame($u->getId(), $res['items'][0]->getId());
    }

    public function testSearchByTelegramIdExactMatch(): void
    {
        $tgId = (string) random_int(10_000_000_000, 99_999_999_999);
        $u    = $this->persistUser($this->marker . '-tg@example.test', $tgId);

        $res = $this->users->searchPaginated($tgId, 20, 0);

        self::assertSame(1, $res['total']);
        self::assertSame($u->getId(), $res['items'][0]->getId());
    }

    public function testSearchByIdExactMatch(): void
    {
        $u = $this->persistUser($this->marker . '-id@example.test');

        $res = $this->users->searchPaginated((string) $u->getId(), 20, 0);

        self::assertGreaterThanOrEqual(1, $res['total']);
        $ids = array_map(static fn (User $x): int => $x->getId(), $res['items']);
        self::assertContains($u->getId(), $ids);
    }

    public function testPaginationLimitsAndTotalCount(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->persistUser($this->marker . "-{$i}@example.test");
        }

        $page1 = $this->users->searchPaginated($this->marker, 2, 0);
        self::assertSame(3, $page1['total'], 'total игнорирует limit/offset');
        self::assertCount(2, $page1['items'], 'limit ограничивает страницу');

        $page2 = $this->users->searchPaginated($this->marker, 2, 2);
        self::assertSame(3, $page2['total']);
        self::assertCount(1, $page2['items'], 'вторая страница — остаток');
    }

    public function testEmptyQueryListsAllPaginated(): void
    {
        $this->persistUser($this->marker . '-all@example.test');

        $res = $this->users->searchPaginated('', 20, 0);

        // Пустой q → весь список; наш посеянный юзер обязан быть среди total.
        self::assertGreaterThanOrEqual(1, $res['total']);
        self::assertLessThanOrEqual(20, \count($res['items']));
    }

    private function persistUser(string $email, ?string $telegramId = null): User
    {
        $user = (new User())->setEmail($email);
        if ($telegramId !== null) {
            $user->setTelegramId($telegramId);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->toRemove[] = $user;

        return $user;
    }
}

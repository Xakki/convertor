<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oauth;

use App\DTO\OAuthUserInfo;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Service\Oauth\SocialIdentityResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты «ядра корректности» findOrCreateUser на моках EM/репозиториев (без
 * БД). Ветки: existing-by-social, link-by-verified-email, brand-new,
 * unverified-reject, reserved-email-reject, race (unique-violation → resetManager
 * → повторный резолв на СВЕЖЕМ EM).
 */
final class SocialIdentityResolverTest extends TestCase
{
    private const PROVIDER = 'google';

    public function testExistingSocialIdentityReturnsItsUser(): void
    {
        $user     = $this->makeUser(10);
        $identity = (new SocialIdentity())->setUser($user);

        $socialRepo = $this->repoReturning($identity);
        $userRepo   = $this->repoReturning(null);
        $em         = $this->em([SocialIdentity::class => $socialRepo, User::class => $userRepo]);
        $em->expects($this->never())->method('flush');

        $resolver = new SocialIdentityResolver($this->registry($em));
        $result   = $resolver->findOrCreateUser(self::PROVIDER, $this->info('uid-1', 'a@b.com', true));

        self::assertSame($user, $result);
    }

    public function testLinkByVerifiedEmailAttachesToExistingUser(): void
    {
        $existing = $this->makeUser(20);
        $existing->setEmail('found@b.com');

        $socialRepo = $this->repoReturning(null); // нет привязки этой учётки
        $userRepo   = $this->repoReturning($existing); // но есть User с этим email

        $persisted = [];
        $em        = $this->em([SocialIdentity::class => $socialRepo, User::class => $userRepo], $persisted);
        $em->expects($this->once())->method('flush');

        $resolver = new SocialIdentityResolver($this->registry($em));
        $result   = $resolver->findOrCreateUser(self::PROVIDER, $this->info('uid-2', 'Found@b.com', true));

        self::assertSame($existing, $result, 'логинимся в существующего User');
        // Привязана ровно одна новая SocialIdentity к найденному User (User не создаётся).
        $identities = array_filter($persisted, static fn ($e) => $e instanceof SocialIdentity);
        self::assertCount(1, $identities);
        $identity = array_values($identities)[0];
        self::assertSame($existing, $identity->getUser());
        self::assertSame('found@b.com', $identity->getEmail(), 'email нормализован (lowercase)');
        self::assertCount(0, array_filter($persisted, static fn ($e) => $e instanceof User));
    }

    public function testBrandNewUserCreatedWithVerifiedEmail(): void
    {
        $socialRepo = $this->repoReturning(null);
        $userRepo   = $this->repoReturning(null); // никого по email нет

        $persisted = [];
        $em        = $this->em([SocialIdentity::class => $socialRepo, User::class => $userRepo], $persisted);
        $em->expects($this->once())->method('flush');

        $resolver = new SocialIdentityResolver($this->registry($em));
        $result   = $resolver->findOrCreateUser(self::PROVIDER, $this->info('uid-3', 'new@b.com', true, 'nick', 'New Name'));

        self::assertFalse($result->isGuest());
        self::assertTrue($result->isActive());
        self::assertSame('new@b.com', $result->getEmail(), 'verified email осел в users.email');
        self::assertSame('nick', $result->getUsername());
        $identities = array_filter($persisted, static fn ($e) => $e instanceof SocialIdentity);
        self::assertCount(1, $identities);
        self::assertSame('New Name', array_values($identities)[0]->getDisplayName());
    }

    public function testUnverifiedEmailIsNotLinkedAndNotStoredOnUser(): void
    {
        $socialRepo = $this->repoReturning(null);
        // Инвариант: при неверифицированном email lookup по email НЕ делается —
        // иначе можно угнать чужой аккаунт непроверенным адресом.
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->expects($this->never())->method('findOneBy');

        $persisted = [];
        $em        = $this->em([SocialIdentity::class => $socialRepo, User::class => $userRepo], $persisted);
        $em->expects($this->once())->method('flush');

        $resolver = new SocialIdentityResolver($this->registry($em));
        $result   = $resolver->findOrCreateUser(self::PROVIDER, $this->info('uid-4', 'unverified@b.com', false));

        self::assertNull($result->getEmail(), 'неверифицированный email НЕ попадает в users.email');
        // SocialIdentity.email = синтетический плейсхолдер.
        $identity = array_values(array_filter($persisted, static fn ($e) => $e instanceof SocialIdentity))[0];
        self::assertSame('google:uid-4@google.oauth.local', $identity->getEmail());
    }

    public function testReservedSyntheticEmailIsRejectedForLinking(): void
    {
        $socialRepo = $this->repoReturning(null);
        // Даже с emailVerified=true, зарезервированный (.oauth.local) адрес не годен
        // для линковки → lookup по email не делается, User создаётся без email.
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->expects($this->never())->method('findOneBy');

        $persisted = [];
        $em        = $this->em([SocialIdentity::class => $socialRepo, User::class => $userRepo], $persisted);
        $em->expects($this->once())->method('flush');

        $resolver = new SocialIdentityResolver($this->registry($em));
        $result   = $resolver->findOrCreateUser(self::PROVIDER, $this->info('uid-5', 'evil@x.oauth.local', true));

        self::assertNull($result->getEmail());
    }

    public function testRaceUniqueViolationResetsManagerAndReResolvesOnFreshEm(): void
    {
        $racedUser     = $this->makeUser(30);
        $racedIdentity = (new SocialIdentity())->setUser($racedUser);

        // EM1: первый резолв связи пуст → пытаемся создать → flush падает
        // UniqueConstraintViolationException (параллельный callback успел вставить).
        $socialRepo1 = $this->repoReturning(null);
        $em1         = $this->em([SocialIdentity::class => $socialRepo1, User::class => $this->repoReturning(null)]);
        $violation   = (new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor();
        $em1->expects($this->once())->method('flush')->willThrowException($violation);

        // EM2 (после resetManager): та же связь теперь ЕСТЬ → возвращаем её User.
        $socialRepo2 = $this->repoReturning($racedIdentity);
        $em2         = $this->em([SocialIdentity::class => $socialRepo2, User::class => $this->repoReturning(null)]);
        $em2->expects($this->never())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        // Ключевое: getManager отдаёт РАЗНЫЕ EM до и после сброса — иначе тест не
        // проверил бы путь через свежий менеджер (false-green трюк из ревью).
        $registry->method('getManager')->willReturnOnConsecutiveCalls($em1, $em2);
        $registry->expects($this->once())->method('resetManager');

        $resolver = new SocialIdentityResolver($registry);
        $result   = $resolver->findOrCreateUser(self::PROVIDER, $this->info('uid-6', null, false));

        self::assertSame($racedUser, $result, 'после гонки логинимся в победившего User со свежего EM');
    }

    // --- helpers ---------------------------------------------------------

    private function info(string $uid, ?string $email, bool $verified, ?string $username = null, ?string $display = null): OAuthUserInfo
    {
        return new OAuthUserInfo($uid, $email, $verified, $username, $display);
    }

    private function repoReturning(?object $result): EntityRepository
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($result);

        return $repo;
    }

    /**
     * @param array<class-string, EntityRepository> $repos
     * @param array<int, object>                    $persisted collected by reference
     */
    private function em(array $repos, array &$persisted = []): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $cls): EntityRepository => $repos[$cls]);
        $em->method('persist')->willReturnCallback(static function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        return $em;
    }

    private function registry(EntityManagerInterface $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        return $registry;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}

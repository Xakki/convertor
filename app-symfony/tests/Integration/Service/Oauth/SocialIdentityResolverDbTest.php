<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Oauth;

use App\DTO\OAuthUserInfo;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Service\Oauth\SocialIdentityResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findOrCreateUser против РЕАЛЬНОЙ БД (convertor-test): проверяет то, что моки
 * unit-теста доказать не могут — что маппинг сущности рабочий, а UNIQUE(provider,
 * provider_uid) и ON DELETE CASCADE ДЕЙСТВИТЕЛЬНО существуют в схеме (на этот
 * констрейнт опирается весь race-путь; unit-тест лишь эмулирует его исключением).
 */
#[Group('integration')]
final class SocialIdentityResolverDbTest extends KernelTestCase
{
    public function testCreateThenReResolveBySocialIdentityRoundTrips(): void
    {
        self::bootKernel();
        $resolver = new SocialIdentityResolver($this->registry());

        $uid  = 'itest-' . bin2hex(random_bytes(6));
        $info = new OAuthUserInfo($uid, $uid . '@example.test', true, 'nick', 'Nick Name');

        $created = $resolver->findOrCreateUser('google', $info);
        self::assertNotNull($created->getId(), 'новый User персистнут');
        self::assertFalse($created->isGuest());
        self::assertSame($uid . '@example.test', $created->getEmail(), 'verified email осел в users.email');

        // Повторный резолв той же учётки провайдера → ТОТ ЖЕ User (ветка by-social
        // против реальной БД, не мок).
        $again = $resolver->findOrCreateUser('google', $info);
        self::assertSame($created->getId(), $again->getId());

        $this->cleanupUser($created->getId());
    }

    public function testDuplicateProviderUidHitsUniqueConstraint(): void
    {
        self::bootKernel();
        $em = self::em();

        $user = (new User())->setIsGuest(false);
        $em->persist($user);

        $uid = 'itest-' . bin2hex(random_bytes(6));
        $em->persist($this->identity($user, 'google', $uid));
        $em->flush();

        // Вторая связь с тем же (provider, provider_uid) → UNIQUE обязан выстрелить.
        $em->persist($this->identity($user, 'google', $uid));

        try {
            $em->flush();
            self::fail('UNIQUE(provider, provider_uid) не сработал — констрейнта нет в схеме');
        } catch (UniqueConstraintViolationException) {
            self::assertTrue(true);
        }

        // EM закрыт после провала flush — чистим на свежем.
        $this->cleanupUser($user->getId());
    }

    public function testCascadeDeleteRemovesIdentities(): void
    {
        self::bootKernel();
        $em = self::em();

        $user = (new User())->setIsGuest(false);
        $em->persist($user);
        $em->persist($this->identity($user, 'github', 'itest-' . bin2hex(random_bytes(6))));
        $em->flush();

        $userId     = $user->getId();
        $identityId = $em->getRepository(SocialIdentity::class)->findOneBy(['user' => $user])?->getId();
        self::assertNotNull($identityId);

        // Нативный DELETE users → ON DELETE CASCADE в БД должен снести social_identities.
        $em->getConnection()->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
        $em->clear();

        self::assertNull(
            $em->getRepository(SocialIdentity::class)->find($identityId),
            'ON DELETE CASCADE не снёс SocialIdentity',
        );
    }

    /**
     * Кросс-провайдерная гонка: google и github резолвят ОДИН verified-email
     * одновременно, оба пытаются создать НОВОГО User. «Проигравший» (github) обязан
     * поймать UNIQUE(users.email) на flush и повторно резолвиться на СВЕЖЕМ EM
     * ({@see SocialIdentityResolver::resolveAfterRace()}), приземлившись на User
     * победителя.
     *
     * Гонку в однопроцессном тесте нельзя получить обычным двойным вызовом
     * findOrCreateUser() — второй вызов просто найдёт УЖЕ закоммиченного User по
     * email (ветка 2, без исключения). Поэтому эмулируем её onFlush-листенером:
     * ровно в момент, когда резолвер (уже пройдя свой SELECT по email и не найдя
     * никого) готовится выполнить INSERT нового User, мы через ОТДЕЛЬНОЕ
     * автокоммитное DBAL-соединение вставляем «победившего» User + google-identity.
     * Отдельное соединение критично: оно переживает откат транзакции проигравшего
     * flush (тот же INSERT через $em->getConnection() был бы частью ЭТОЙ же
     * транзакции и откатился бы вместе с ней).
     */
    public function testCrossProviderEmailRaceEndsWithSingleUserAndTwoIdentities(): void
    {
        self::bootKernel();
        $resolver = new SocialIdentityResolver($this->registry());

        $email     = 'itest-race-' . bin2hex(random_bytes(6)) . '@example.test';
        $uidGoogle = 'itest-' . bin2hex(random_bytes(6));
        $uidGithub = 'itest-' . bin2hex(random_bytes(6));

        $infoGithub = new OAuthUserInfo($uidGithub, $email, true, 'nick-h', 'Nick H');

        $em      = self::em();
        $rawConn = DriverManager::getConnection($em->getConnection()->getParams());

        $listener = new class ($email, $uidGoogle, $rawConn) {
            public bool $armed        = true;
            public ?int $winnerUserId = null;

            public function __construct(
                private readonly string $email,
                private readonly string $uidGoogle,
                private readonly Connection $rawConn,
            ) {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if (! $this->armed) {
                    return;
                }

                $uow = $args->getObjectManager()->getUnitOfWork();
                foreach ($uow->getScheduledEntityInsertions() as $entity) {
                    if (! $entity instanceof User || $entity->getEmail() !== $this->email) {
                        continue;
                    }

                    $this->armed = false;

                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    // Raw INSERT must list every NOT NULL column without a DB
                    // default (Version20260801214439 dropped column defaults;
                    // Doctrine still sends PHP defaults, this race stub does not).
                    $this->rawConn->insert('users', [
                        'email'                      => $this->email,
                        'plan'                       => 'free',
                        'light_daily_conversions'    => 0,
                        'light_monthly_conversions'  => 0,
                        'medium_daily_conversions'   => 0,
                        'medium_monthly_conversions' => 0,
                        'heavy_daily_conversions'    => 0,
                        'heavy_monthly_conversions'  => 0,
                        'ai_daily_conversions'       => 0,
                        'ai_monthly_conversions'     => 0,
                        'quota_reset_at'             => $now,
                        'monthly_reset_at'           => $now,
                        'created_at'                 => $now,
                        'is_active'                  => 1,
                        'is_guest'                   => 0,
                        'is_admin'                   => 0,
                    ]);
                    $this->winnerUserId = (int) $this->rawConn->lastInsertId();

                    $this->rawConn->insert('social_identities', [
                        'user_id'      => $this->winnerUserId,
                        'provider'     => 'google',
                        'provider_uid' => $this->uidGoogle,
                        'email'        => $this->email,
                        'created_at'   => $now,
                    ]);

                    break;
                }
            }
        };

        $em->getEventManager()->addEventListener(Events::onFlush, $listener);

        $result = $resolver->findOrCreateUser('github', $infoGithub);

        self::assertFalse($listener->armed, 'onFlush-листенер обязан был сработать — гонка не воспроизвелась');
        self::assertNotNull($listener->winnerUserId, 'победивший User должен быть создан через отдельное соединение');
        self::assertSame(
            $listener->winnerUserId,
            $result->getId(),
            'после гонки резолвер логинится в победившего User, а не создаёт второго',
        );

        $usersCount = (int) $rawConn->fetchOne('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
        self::assertSame(1, $usersCount, 'ровно один User с этим email — гонка не породила дубль');

        $identitiesCount = (int) $rawConn->fetchOne(
            'SELECT COUNT(*) FROM social_identities WHERE user_id = ?',
            [$listener->winnerUserId],
        );
        self::assertSame(2, $identitiesCount, 'обе SocialIdentity (google + github) привязаны к победившему User');

        $providers = $rawConn->fetchFirstColumn(
            'SELECT provider FROM social_identities WHERE user_id = ? ORDER BY provider',
            [$listener->winnerUserId],
        );
        self::assertSame(['github', 'google'], $providers);

        $this->cleanupUser($listener->winnerUserId);
        $rawConn->close();
    }

    private function identity(User $user, string $provider, string $uid): SocialIdentity
    {
        return (new SocialIdentity())
            ->setUser($user)
            ->setProvider($provider)
            ->setProviderUid($uid)
            ->setEmail($provider . ':' . $uid . '@' . $provider . '.oauth.local');
    }

    private function cleanupUser(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        // Свежий EM (мог быть закрыт после ожидаемого провала flush).
        $conn = self::em()->getConnection();
        $conn->executeStatement('DELETE FROM social_identities WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
    }

    private static function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        if (! $em->isOpen()) {
            self::getContainer()->get('doctrine')->resetManager();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            assert($em instanceof EntityManagerInterface);
        }

        return $em;
    }

    private function registry(): ManagerRegistry
    {
        $registry = static::getContainer()->get('doctrine');
        assert($registry instanceof ManagerRegistry);

        return $registry;
    }
}

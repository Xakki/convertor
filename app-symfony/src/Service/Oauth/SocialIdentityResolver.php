<?php

declare(strict_types=1);

namespace App\Service\Oauth;

use App\DTO\OAuthUserInfo;
use App\Entity\SocialIdentity;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ядро корректности OAuth-логина: сопоставить нормализованный профиль провайдера
 * ({@see OAuthUserInfo}) с нашим {@see User}, создавая связь {@see SocialIdentity}.
 *
 * Порядок (см. «Ядро корректности» в oauth-00-epic):
 *  1. Найден SocialIdentity по (provider, provider_uid) → логиним его User.
 *  2. Иначе, если email VERIFIED и не зарезервирован → найден User по email →
 *     линкуем к нему новую SocialIdentity (кросс-провайдерная привязка).
 *  3. Иначе → создаём passwordless-User (не гость, активный) + SocialIdentity.
 *
 * Гонки: два одновременных callback'а могут вставить одну и ту же связь. Полагаемся
 * на UNIQUE(provider, provider_uid): проигравший ловит {@see UniqueConstraintViolationException},
 * сбрасывает закрытый после провала flush EM ({@see ManagerRegistry::resetManager()},
 * паттерн {@see \App\Service\Queue\ConversionResultPersister}) и повторно резолвит
 * связь на СВЕЖЕМ EM.
 *
 * Безопасность линковки по email: линкуем к существующему User ТОЛЬКО по
 * verified-email провайдера, никогда по неверифицированному/синтетическому —
 * иначе чужой аккаунт можно угнать, зарегистрировав у провайдера непроверенный
 * адрес жертвы. User.email заполняется лишь verified-адресом, поэтому в
 * `users.email` неверифицированный адрес не попадёт (инвариант ветки 2).
 */
// Не final — функциональные тесты контроллера подменяют его в контейнере (стаб),
// чтобы не тянуть БД; ядро покрыто отдельными unit-тестами на моках.
class SocialIdentityResolver
{
    /** Синтетический домен для профилей без email — никогда не совпадёт с реальным. */
    private const SYNTHETIC_EMAIL_DOMAIN = '.oauth.local';

    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function findOrCreateUser(string $provider, OAuthUserInfo $info): User
    {
        $em = $this->managerFromRegistry();

        // 1. Уже привязанная учётка провайдера.
        $existing = $this->findIdentity($em, $provider, $info->providerUid);
        if ($existing !== null) {
            return $existing->getUser();
        }

        $linkEmail = $this->linkableEmail($info);

        try {
            // 2. Кросс-провайдерная привязка по verified-email.
            if ($linkEmail !== null) {
                $user = $em->getRepository(User::class)->findOneBy(['email' => $linkEmail]);
                if ($user !== null) {
                    $this->attachIdentity($em, $user, $provider, $info);

                    return $user;
                }
            }

            // 3. Новый passwordless-User.
            return $this->createUser($em, $provider, $info, $linkEmail);
        } catch (UniqueConstraintViolationException) {
            return $this->resolveAfterRace($provider, $info, $linkEmail);
        }
    }

    /**
     * Проигравший гонку: EM после провала flush закрыт — сбрасываем его и резолвим
     * заново на свежем EM. Доминирующий случай — дубль (provider, provider_uid)
     * от параллельного callback'а: связь уже есть → возвращаем её User. Более
     * редкий случай — гонка на users.email (оба создали User с одним verified-email):
     * связь нашей учётки ещё не создана → находим победившего User по email и
     * привязываем нашу SocialIdentity к нему.
     */
    private function resolveAfterRace(string $provider, OAuthUserInfo $info, ?string $linkEmail): User
    {
        $this->registry->resetManager();
        $em = $this->managerFromRegistry();

        $again = $this->findIdentity($em, $provider, $info->providerUid);
        if ($again !== null) {
            return $again->getUser();
        }

        if ($linkEmail !== null) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $linkEmail]);
            if ($user !== null) {
                $this->attachIdentity($em, $user, $provider, $info);

                return $user;
            }
        }

        throw new \RuntimeException(sprintf(
            'OAuth resolve race unresolved for provider "%s" uid "%s"',
            $provider,
            $info->providerUid,
        ));
    }

    private function findIdentity(EntityManagerInterface $em, string $provider, string $providerUid): ?SocialIdentity
    {
        /** @var SocialIdentity|null $identity */
        $identity = $em->getRepository(SocialIdentity::class)
            ->findOneBy(['provider' => $provider, 'providerUid' => $providerUid]);

        return $identity;
    }

    private function attachIdentity(EntityManagerInterface $em, User $user, string $provider, OAuthUserInfo $info): void
    {
        $em->persist($this->buildIdentity($user, $provider, $info));
        $em->flush();
    }

    private function createUser(EntityManagerInterface $em, string $provider, OAuthUserInfo $info, ?string $linkEmail): User
    {
        $user = new User();
        $user->setIsGuest(false);
        $user->setIsActive(true);
        // users.email заполняем ТОЛЬКО verified-адресом (инвариант ветки link-by-email).
        if ($linkEmail !== null) {
            $user->setEmail($linkEmail);
        }
        $user->setUsername($info->username);
        $user->setFirstName($info->displayName);
        $em->persist($user);

        $em->persist($this->buildIdentity($user, $provider, $info));
        $em->flush();

        return $user;
    }

    private function buildIdentity(User $user, string $provider, OAuthUserInfo $info): SocialIdentity
    {
        return (new SocialIdentity())
            ->setUser($user)
            ->setProvider($provider)
            ->setProviderUid($info->providerUid)
            ->setEmail($this->identityEmail($provider, $info))
            ->setUsername($info->username)
            ->setDisplayName($info->displayName);
    }

    /**
     * Адрес, по которому МОЖНО линковать к существующему User: verified, непустой,
     * не зарезервированный/синтетический. Иначе null (линковка по email запрещена).
     */
    private function linkableEmail(OAuthUserInfo $info): ?string
    {
        if (! $info->emailVerified || $info->email === null) {
            return null;
        }

        $email = $this->normalizeEmail($info->email);
        if ($email === '' || $this->isReservedEmail($email)) {
            return null;
        }

        return $email;
    }

    /** email для строки SocialIdentity: verified-адрес или синтетический плейсхолдер. */
    private function identityEmail(string $provider, OAuthUserInfo $info): string
    {
        $linkEmail = $this->linkableEmail($info);
        if ($linkEmail !== null) {
            return $linkEmail;
        }

        return sprintf('%s:%s@%s%s', $provider, $info->providerUid, $provider, self::SYNTHETIC_EMAIL_DOMAIN);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Зарезервированные/системные адреса, которым нельзя доверять при линковке:
     * синтетический oauth.local-домен (наш плейсхолдер) и заведомо невалидные без `@`.
     */
    private function isReservedEmail(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return true;
        }

        return str_ends_with($email, self::SYNTHETIC_EMAIL_DOMAIN);
    }

    private function managerFromRegistry(): EntityManagerInterface
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты гейта админки (эпик admin-panel, подзадача auth).
 *
 * Требуют реальную тест-БД (convertor-test): и loginUser (firewall `main`,
 * reload юзера по id), и JWT (провайдер грузит User по sub=id) читают строки.
 *
 * Option B (JSON-API + client render): веб `^/admin` — ОТКРЫТАЯ оболочка (200
 * для всех, доступ режут client-guard + admin-API). Единственная реальная
 * граница безопасности — API-путь `^/api/v1/admin/ping` (firewall `api`,
 * stateless JWT): минтим настоящий Bearer и убеждаемся, что не-админ 403, админ
 * 200, неаутентифицированный 401.
 */
final class AdminAccessControlTest extends WebTestCase
{
    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $container = static::getContainer();
            /** @var EntityManagerInterface $em */
            $em = $container->get(EntityManagerInterface::class);
            foreach ($this->createdUserIds as $id) {
                $user = $em->find(User::class, $id);
                if ($user !== null) {
                    $em->remove($user);
                }
            }
            $em->flush();
        }

        parent::tearDown();

        $this->createdUserIds = [];
    }

    public function testGetRolesReturnsAdminRoleForAdmin(): void
    {
        $admin = (new User())->setIsAdmin(true);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertContains('ROLE_USER', $admin->getRoles());

        $plain = new User();
        self::assertNotContains('ROLE_ADMIN', $plain->getRoles());

        $guest = (new User())->setIsGuest(true)->setIsAdmin(true);
        // Гость остаётся ТОЛЬКО ROLE_GUEST даже при флаге isAdmin.
        self::assertSame(['ROLE_GUEST'], $guest->getRoles());
    }

    public function testAdminPageIsOpenShellForAnonymousNavigation(): void
    {
        // Option B: страница `/admin` — открытая оболочка, достижимая обычной
        // навигацией браузера (Bearer при навигации не передаётся). 200 для всех;
        // доступ режут client-guard + admin-API, а НЕ firewall на странице.
        $client = static::createClient();
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Панель администратора', $html);
        // Навигация на будущие панели + секции-заглушки.
        self::assertStringContainsString('Пользователи', $html);
        self::assertStringContainsString('Очереди', $html);
        self::assertStringContainsString('id="overview"', $html);
        // Client-guard-стаб присутствует (тянет JWT через refresh, редиректит не-админа).
        self::assertStringContainsString('/api/v1/auth/refresh', $html);
        self::assertStringContainsString('ROLE_ADMIN', $html);
    }

    public function testAdminPageOpenShellAlsoForRegularUser(): void
    {
        // Даже залогиненный не-админ получает саму страницу (оболочка открыта);
        // граница — на admin-API, не на HTML.
        $client = static::createClient();
        $client->loginUser($this->persistUser(false));

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function testAdminApiUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        // Ни Bearer, ни guest-cookie. GuestAuthenticator скоупен на convert/quota
        // и на admin-путях НЕ срабатывает → запрос неаутентифицирован → 401
        // (гость вообще не может дойти до ^/api/v1/admin — это сильнее, чем 403).
        $client->request('GET', '/api/v1/admin/ping');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAdminApiForbiddenForGuestRole(): void
    {
        $client = static::createClient();
        // guest-User (ROLE_GUEST) с JWT: role_hierarchy не даёт ROLE_ADMIN → 403.
        $guest = (new User())->setIsGuest(true)->setGuestId('admin-gate-guest-' . uniqid());
        $token = $this->persistAndJwt($guest);

        $client->request('GET', '/api/v1/admin/ping', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAdminApiForbiddenForRegularUserJwt(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/ping', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAdminApiAllowedForAdminJwt(): void
    {
        $client = static::createClient();
        $admin  = $this->persistUser(true);
        $token  = $this->jwtFor($admin);

        // JWT-payload содержит роли в claim `roles` (дефолт LexikJWT) — ROLE_ADMIN
        // среди них. Именно этот claim используется server-side гейтом.
        $payload = $this->decodeJwtPayload($token);
        self::assertArrayHasKey('roles', $payload);
        self::assertContains('ROLE_ADMIN', $payload['roles']);

        $client->request('GET', '/api/v1/admin/ping', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['ok']);
    }

    private function persistUser(bool $admin): User
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = (new User())->setIsAdmin($admin);
        $em->persist($user);
        $em->flush();

        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    private function jwtFor(User $user): string
    {
        $container = static::getContainer();
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = $container->get(JWTTokenManagerInterface::class);

        return $jwt->create($user);
    }

    private function persistAndJwt(User $user): string
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = $user->getId();

        return $this->jwtFor($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertNotFalse($json);

        $payload = json_decode((string) $json, true);
        self::assertIsArray($payload);

        return $payload;
    }
}

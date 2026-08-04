<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты admin-users-эндпоинтов (эпик admin-panel, подзадача
 * users). Граница — ROLE_ADMIN на JWT-firewall (Option B): не-админ 403, админ
 * 200. Мутации (ban/unban/reset-quota/plan) проверяются перечиткой из БД.
 * «Забаненный не проходит auth» покрыт AuthRefreshControllerTest (реальная
 * точка — POST /api/v1/auth/refresh). Требуют тест-БД convertor-test.
 */
final class UserControllerTest extends WebTestCase
{
    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
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

    public function testEndpointsForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser());
        $target = $this->persistUser();

        $cases = [
            ['GET', '/api/v1/admin/users'],
            ['POST', '/api/v1/admin/users/' . $target->getId() . '/ban'],
            ['POST', '/api/v1/admin/users/' . $target->getId() . '/unban'],
            ['POST', '/api/v1/admin/users/' . $target->getId() . '/reset-quota'],
            ['POST', '/api/v1/admin/users/' . $target->getId() . '/plan'],
        ];

        foreach ($cases as [$method, $url]) {
            $client->request($method, $url, server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
            self::assertSame(403, $client->getResponse()->getStatusCode(), "{$method} {$url}");
        }
    }

    public function testListUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/users');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListReturnsPaginationMetadataForAdmin(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();

        $client->request('GET', '/api/v1/admin/users', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (['items', 'page', 'pageSize', 'total', 'pages'] as $key) {
            self::assertArrayHasKey($key, $data, $key);
        }
        self::assertIsArray($data['items']);
        self::assertSame(1, $data['page']);
    }

    public function testBanAndUnbanFlipIsActive(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $target = $this->persistUser();
        $id     = $target->getId();

        $client->request('POST', "/api/v1/admin/users/{$id}/ban", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($id)->isActive(), 'ban → isActive=false');

        $client->request('POST', "/api/v1/admin/users/{$id}/unban", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($id)->isActive(), 'unban → isActive=true');
    }

    public function testResetQuotaZeroesCounters(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $target = $this->persistUser();
        $target->setLightDailyConversions(5)->setAiDailyConversions(3)
            ->setLightMonthlyConversions(2)
            ->setQuotaResetAt(new \DateTimeImmutable('-2 days'))
            ->setMonthlyResetAt(new \DateTimeImmutable('-31 days'));
        $this->flush();
        $id = $target->getId();

        $client->request('POST', "/api/v1/admin/users/{$id}/reset-quota", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $fresh = $this->reload($id);
        self::assertSame(0, $fresh->getLightDailyConversions());
        self::assertSame(0, $fresh->getAiDailyConversions());
        self::assertSame(0, $fresh->getLightMonthlyConversions());
        self::assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            $fresh->getQuotaResetAt()->format('Y-m-d'),
            'quotaResetAt подвинут на сегодня',
        );
        self::assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            $fresh->getMonthlyResetAt()->format('Y-m-d'),
            'monthlyResetAt подвинут на сегодня',
        );
    }

    public function testResetQuotaResponseIncludesTierCounterShape(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $target = $this->persistUser();
        $target->setLightDailyConversions(4)->setAiMonthlyConversions(2);
        $this->flush();
        $id = $target->getId();

        $client->request('POST', "/api/v1/admin/users/{$id}/reset-quota", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        $this->assertQuotaCountersShape($data['quotaCounters']);
        self::assertSame(0, $data['quotaCounters']['light']['daily']);
        self::assertSame(0, $data['quotaCounters']['ai']['monthly']);
    }

    public function testListItemsExposeQuotaCountersShape(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $target = $this->persistUser();
        $target->setMediumDailyConversions(1)->setHeavyMonthlyConversions(2);
        $this->flush();

        $client->request('GET', '/api/v1/admin/users', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertNotEmpty($data['items']);

        $item = null;
        foreach ($data['items'] as $row) {
            if (($row['id'] ?? null) === $target->getId()) {
                $item = $row;
                break;
            }
        }
        self::assertIsArray($item);
        $this->assertQuotaCountersShape($item['quotaCounters']);
        self::assertSame(1, $item['quotaCounters']['medium']['daily']);
        self::assertSame(2, $item['quotaCounters']['heavy']['monthly']);
    }

    public function testListDefaultExcludesGuests(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $reg    = $this->persistUser();
        $guest  = $this->persistGuest();
        $server = ['HTTP_AUTHORIZATION' => "Bearer {$token}"];

        $client->request('GET', '/api/v1/admin/users', ['q' => (string) $reg->getId()], server: $server);
        self::assertResponseIsSuccessful();
        $regData = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($regData);
        $regIds = array_map(static fn (array $r): int => (int) $r['id'], $regData['items']);
        self::assertContains($reg->getId(), $regIds);
        foreach ($regData['items'] as $row) {
            self::assertFalse($row['isGuest'], 'дефолтный список без анонимов');
        }

        $client->request('GET', '/api/v1/admin/users', ['q' => (string) $guest->getId()], server: $server);
        self::assertResponseIsSuccessful();
        $guestData = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($guestData);
        $guestIds = array_map(static fn (array $r): int => (int) $r['id'], $guestData['items']);
        self::assertNotContains($guest->getId(), $guestIds);
    }

    public function testListGuest1ReturnsOnlyGuests(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $reg    = $this->persistUser();
        $guest  = $this->persistGuest();

        $client->request(
            'GET',
            '/api/v1/admin/users',
            ['guest' => '1', 'q' => (string) $guest->getId()],
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $data['items']);
        self::assertContains($guest->getId(), $ids);
        self::assertNotContains($reg->getId(), $ids);
        foreach ($data['items'] as $row) {
            self::assertTrue($row['isGuest'], 'guest=1 — только анонимы');
        }
    }

    /**
     * @param array<string, mixed> $counters
     */
    private function assertQuotaCountersShape(array $counters): void
    {
        foreach (['light', 'medium', 'heavy', 'ai'] as $tier) {
            self::assertArrayHasKey($tier, $counters);
            self::assertSame(['daily', 'monthly'], array_keys($counters[$tier]));
        }
    }

    public function testChangePlanValidatesAndPersists(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();
        $target = $this->persistUser();
        $id     = $target->getId();
        $server = ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'];

        // Валидный план (сидируется миграцией) → 200 + смена.
        $client->request('POST', "/api/v1/admin/users/{$id}/plan", server: $server, content: json_encode(['plan' => 'pro']));
        self::assertResponseIsSuccessful();
        self::assertSame('pro', $this->reload($id)->getPlan());

        // Несуществующий план → 400, значение не меняется.
        $client->request('POST', "/api/v1/admin/users/{$id}/plan", server: $server, content: json_encode(['plan' => 'nonexistent-plan']));
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('pro', $this->reload($id)->getPlan());
    }

    public function testMutationOnMissingUserReturns404(): void
    {
        $client = static::createClient();
        $token  = $this->adminToken();

        $client->request('POST', '/api/v1/admin/users/999999999/ban', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    private function adminToken(): string
    {
        return $this->jwtFor($this->persistUser(true));
    }

    private function persistUser(bool $admin = false): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setIsAdmin($admin);
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    private function persistGuest(): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setIsGuest(true)
            ->setGuestId('admin-usr-g-' . bin2hex(random_bytes(8)));
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    private function flush(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function reload(int $id): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}

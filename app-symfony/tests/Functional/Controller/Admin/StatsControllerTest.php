<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты admin-stats-эндпоинта (эпик admin-panel, подзадача
 * stats). Граница — ROLE_ADMIN на JWT-firewall (Option B): не-админ 403,
 * админ 200 со структурированным JSON. Требуют тест-БД convertor-test.
 */
final class StatsControllerTest extends WebTestCase
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

    public function testStatsForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/stats', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testStatsUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/stats');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testStatsReturnsStructuredJsonForAdmin(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('GET', '/api/v1/admin/stats', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        // Totals-блок: реальные агрегаты конвертаций + юзеров.
        self::assertArrayHasKey('totals', $data);
        foreach (['conversions', 'conversionsToday', 'errorRate', 'users', 'activeUsers', 'guestUsers'] as $key) {
            self::assertArrayHasKey($key, $data['totals'], "totals.{$key}");
        }
        self::assertIsInt($data['totals']['conversions']);
        self::assertIsInt($data['totals']['users']);

        // Revenue — плейсхолдер (Payment не персистится).
        self::assertSame(0, $data['revenue']['value']);
        self::assertTrue($data['revenue']['placeholder']);

        // Chart-серия: параллельные ряды меток/regular/ai одной длины.
        self::assertArrayHasKey('chart', $data);
        self::assertSame(
            \count($data['chart']['labels']),
            \count($data['chart']['regular']),
            'labels и regular одной длины',
        );
        self::assertSame(
            \count($data['chart']['labels']),
            \count($data['chart']['ai']),
            'labels и ai одной длины',
        );
        self::assertCount($data['days'], $data['chart']['labels']);

        self::assertArrayHasKey('byStatus', $data);
        self::assertArrayHasKey('byFormat', $data);
        self::assertArrayHasKey('ai', $data);
    }

    public function testStatsDaysParamIsClamped(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        // 9999 > DAYS_MAX(90) → окно ограничивается 90.
        $client->request('GET', '/api/v1/admin/stats?days=9999', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(90, $data['days']);
        self::assertCount(90, $data['chart']['labels']);
    }

    private function persistUser(bool $admin): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setIsAdmin($admin);
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    private function jwtFor(User $user): string
    {
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwt->create($user);
    }
}

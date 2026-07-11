<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты admin-queues-эндпоинта (эпик admin-panel, подзадача
 * queues). Граница — ROLE_ADMIN на JWT-firewall (Option B): не-админ 403, админ
 * 200. В тест-окружении хост metrics-exporter не резолвится → провайдер отдаёт
 * exporterAvailable=false и НЕ роняет эндпоинт (200, без 500) — это и проверка
 * graceful-деградации. Требуют тест-БД convertor-test.
 */
final class QueueControllerTest extends WebTestCase
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

    public function testQueuesForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/queues', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testQueuesUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/queues');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testQueuesReturnsStructuredJsonForAdmin(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('GET', '/api/v1/admin/queues', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        // Недоступный exporter НЕ должен давать 500 — только 200 с дег-стейтом.
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('exporterAvailable', $data);
        self::assertIsBool($data['exporterAvailable']);

        // Каждый канонический тип conv.<type> присутствует в таблице.
        self::assertArrayHasKey('streams', $data);
        $types = array_column($data['streams'], 'type');
        foreach (['document', 'image', 'audio', 'video', 'data', 'ai'] as $type) {
            self::assertContains($type, $types, "тип {$type} присутствует");
        }

        // DB-сигнал доступен независимо от exporter'а.
        self::assertArrayHasKey('dbStuck', $data);
        self::assertArrayHasKey('count', $data['dbStuck']);
        self::assertIsInt($data['dbStuck']['count']);
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

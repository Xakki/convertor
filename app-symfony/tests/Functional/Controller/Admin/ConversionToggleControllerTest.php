<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\ConversionToggle;
use App\Entity\User;
use App\Repository\ConversionToggleRepository;
use App\Service\Conversion\ConversionToggleService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты тумблера конвертаций (эпик admin-panel, подзадача
 * conv-toggle). Граница — ROLE_ADMIN (Option B): не-админ 403, админ 200.
 * Проверяем персист + инвалидацию кеша: POST выкл → GET показывает disabled →
 * POST вкл → GET снова enabled (всё в одном kernel-процессе WebTestCase).
 * Требуют тест-БД convertor-test с применённой миграцией conversion_toggles.
 */
final class ConversionToggleControllerTest extends WebTestCase
{
    private const FROM = 'jpg';
    private const TO   = 'png';

    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Убираем созданный toggle-ряд, чтобы прогон был идемпотентным.
        $repo   = static::getContainer()->get(ConversionToggleRepository::class);
        $toggle = $repo->findPair(self::FROM, self::TO);
        if ($toggle instanceof ConversionToggle) {
            $em->remove($toggle);
        }

        foreach ($this->createdUserIds as $id) {
            $user = $em->find(User::class, $id);
            if ($user !== null) {
                $em->remove($user);
            }
        }
        $em->flush();

        // Сброс кеша (в test-env cache.app = ArrayAdapter) после удаления ряда —
        // иначе следующий прогон увидит устаревший disabled-set при упавшем тесте.
        static::getContainer()->get(ConversionToggleService::class)->invalidate();

        parent::tearDown();
        $this->createdUserIds = [];
    }

    public function testListForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/conversions-toggle', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testListUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/conversions-toggle');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListReturnsConversionsWithEnabledState(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('GET', '/api/v1/admin/conversions-toggle', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data['items']);
        self::assertNotEmpty($data['items']);
        foreach (['from', 'to', 'category', 'isAi', 'enabled'] as $key) {
            self::assertArrayHasKey($key, $data['items'][0]);
        }
    }

    public function testSetPersistsAndInvalidatesCache(): void
    {
        $client = static::createClient();
        // Без reboot контейнер/сервис/кеш живут между запросами — тест реально
        // проверяет и cache->delete(), и сброс per-request memo при флипе
        // (иначе kernel-reboot даёт свежий сервис на каждый запрос).
        $client->disableReboot();
        $token = $this->jwtFor($this->persistUser(true));
        $auth  = ['HTTP_AUTHORIZATION' => "Bearer {$token}"];

        // Изначально пара включена.
        self::assertTrue($this->enabledInList($client, $auth, self::FROM, self::TO));

        // Выключаем → персист + инвалидация.
        $this->post($client, $auth, false);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->enabledInList($client, $auth, self::FROM, self::TO), 'после выкл — disabled в списке');

        // Включаем обратно → снова enabled (кеш инвалидирован).
        $this->post($client, $auth, true);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->enabledInList($client, $auth, self::FROM, self::TO), 'после вкл — снова enabled');
    }

    public function testSetUnknownPairIsNotFound(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/conversions-toggle',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['from' => 'nope', 'to' => 'zzz', 'enabled' => false]),
        );
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, string> $auth
     */
    private function post(object $client, array $auth, bool $enabled): void
    {
        $client->request(
            'POST',
            '/api/v1/admin/conversions-toggle',
            server: $auth + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['from' => self::FROM, 'to' => self::TO, 'enabled' => $enabled]),
        );
    }

    /**
     * @param array<string, string> $auth
     */
    private function enabledInList(object $client, array $auth, string $from, string $to): bool
    {
        $client->request('GET', '/api/v1/admin/conversions-toggle', server: $auth);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        foreach ($data['items'] as $item) {
            if ($item['from'] === $from && $item['to'] === $to) {
                return (bool) $item['enabled'];
            }
        }

        self::fail("Пара {$from}→{$to} не найдена в списке");
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

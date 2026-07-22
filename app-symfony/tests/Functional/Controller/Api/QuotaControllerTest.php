<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * home-13: GET /api/v1/quota для залогиненного юзера — проверяем НОВЫЕ поля
 * (conversions_limit, ai_conversions_limit, max_upload_bytes), добавленные
 * для виджета квот на фронте. Гостевая ветка (форс ai=0/0, plan=guest) —
 * покрыта отдельно в GuestAuthenticationTest.
 *
 * Свежий User → plan='free' (default), сид из миграции Version20260419000001:
 * free = daily_limit 2, daily_ai_limit 1, max_file_size_mb 50.
 */
final class QuotaControllerTest extends WebTestCase
{
    public function testRegisteredUserGetsLimitsAndMaxUploadBytes(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $em->persist($user);
        $em->flush();

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client->request('GET', '/api/v1/quota', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        self::assertSame('free', $data['plan']);
        self::assertSame(2, $data['conversions']);
        self::assertSame(1, $data['ai_conversions']);
        self::assertSame(2, $data['conversions_limit']);
        self::assertSame(1, $data['ai_conversions_limit']);
        self::assertSame(50 * 1024 * 1024, $data['max_upload_bytes']);

        $em->remove($user);
        $em->flush();
    }

    public function testUnlimitedPlanRendersMinusOneLimits(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        // 'pro' — засеян той же миграцией с daily_limit=-1 (безлимит обычных
        // конверсий), daily_ai_limit=100 (НЕ безлимит) — так тест покрывает и
        // -1-рендер, и конечный лимит в одном запросе. Если сид поменяется,
        // тест упадёт явно, а не молча.
        $user = (new User())->setPlan('pro');
        $em->persist($user);
        $em->flush();

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client->request('GET', '/api/v1/quota', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertSame('pro', $data['plan']);
        self::assertSame(-1, $data['conversions']);
        self::assertSame(100, $data['ai_conversions']);
        self::assertSame(-1, $data['conversions_limit']);
        self::assertSame(100, $data['ai_conversions_limit']);

        $em->remove($user);
        $em->flush();
    }
}

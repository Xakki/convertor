<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/quota — 4 тира × 2 окна (CNV-30).
 */
final class QuotaControllerTest extends WebTestCase
{
    public function testRegisteredUserGetsTierLimitsAndMaxUploadBytes(): void
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
        self::assertArrayHasKey('tiers', $data);
        self::assertSame(3, $data['tiers']['light']['daily']['remaining']);
        self::assertSame(30, $data['tiers']['light']['monthly']['limit']);
        self::assertSame(2, $data['tiers']['medium']['daily']['remaining']);
        self::assertSame(0, $data['tiers']['heavy']['daily']['limit']);
        self::assertSame(0, $data['tiers']['ai']['daily']['limit']);
        self::assertSame(50 * 1024 * 1024, $data['max_upload_bytes']);
        self::assertArrayHasKey('balance_cents', $data);
        self::assertArrayHasKey('pay_per_use_cents', $data);
        self::assertArrayHasKey('pay_per_use_ai_cents', $data);
        self::assertSame(0, $data['balance_cents']);
        self::assertSame(5, $data['pay_per_use_cents']);
        self::assertSame(15, $data['pay_per_use_ai_cents']);

        foreach (['light', 'medium', 'heavy', 'ai'] as $tier) {
            self::assertArrayHasKey($tier, $data['tiers']);
            foreach (['daily', 'monthly'] as $window) {
                self::assertArrayHasKey($window, $data['tiers'][$tier]);
                foreach (['used', 'limit', 'remaining'] as $field) {
                    self::assertArrayHasKey($field, $data['tiers'][$tier][$window], "{$tier}.{$window}.{$field}");
                }
            }
        }

        $em->remove($user);
        $em->flush();
    }

    public function testUnlimitedPlanRendersMinusOneOnLightTier(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

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
        self::assertSame(-1, $data['tiers']['light']['daily']['remaining']);
        self::assertSame(-1, $data['tiers']['light']['monthly']['limit']);
        self::assertSame(80, $data['tiers']['ai']['daily']['limit']);

        $em->remove($user);
        $em->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * CNV-30: logged-in free user hits quota-0 (429) on video/AI; guest still 403.
 */
final class ConversionQuotaEnforcementTest extends WebTestCase
{
    public function testFreeUserVideoConversionReturns429QuotaExceeded(): void
    {
        $this->assertFreeUserQuota429('mp4', 'mkv', $this->mp4Bytes(), 'Daily heavy');
    }

    public function testFreeUserAiConversionReturns429QuotaExceeded(): void
    {
        $this->assertFreeUserQuota429('mp3', 'txt', $this->mp3Bytes(), 'Daily ai');
    }

    public function testGuestAiStillReturns403AuthRequiredNot429(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'txt'],
            ['file'      => $this->upload('mp3', $this->mp3Bytes())],
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('auth_required', $data['error']);
    }

    public function testGuestVideoStillReturns403AuthRequiredNot429(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'mkv'],
            ['file'      => $this->upload('mp4', $this->mp4Bytes())],
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('auth_required', $data['error']);
    }

    private function assertFreeUserQuota429(string $from, string $to, string $bytes, string $messageNeedle): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $em->persist($user);
        $em->flush();

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => $to],
            ['file'      => $this->upload($from, $bytes)],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString($messageNeedle, (string) $data['error']);

        $em->remove($user);
        $em->flush();
    }

    private function upload(string $ext, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, "sample.{$ext}", null, null, true);
    }

    private function mp3Bytes(): string
    {
        return "\xFF\xFB\x90\x64" . str_repeat("\x00", 64);
    }

    private function mp4Bytes(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
    }
}

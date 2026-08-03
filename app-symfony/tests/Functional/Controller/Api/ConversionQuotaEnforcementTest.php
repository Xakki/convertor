<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Messenger\Transport\CleanRedisTransport;
use App\Repository\ConversionRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Billing\BalanceService;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * CNV-30: logged-in free user hits quota-0 (429) on video/AI; guest still 403.
 * CNV-28: prepaid pay-per-use сверх дневной квоты при достаточном balance_cents.
 */
final class ConversionQuotaEnforcementTest extends WebTestCase
{
    /** @var list<array{class: class-string, id: int}> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== [] && static::$kernel !== null) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $ref) {
                $fresh = $em->find($ref['class'], $ref['id']);
                if ($fresh === null) {
                    continue;
                }
                if ($fresh instanceof User) {
                    $em->createQuery('DELETE FROM App\Entity\BalanceTransaction bt WHERE bt.user = :user')
                        ->setParameter('user', $fresh)
                        ->execute();
                }
                $em->remove($fresh);
            }
            $em->flush();
            $this->toRemove = [];
        }

        parent::tearDown();
    }

    public function testRegisteredUserOverDailyQuotaUsesPrepaidBalance(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $initialBalance = 100;
        $user           = (new User())
            ->setLightDailyConversions(3)
            ->setBalanceCents($initialBalance);
        $em->persist($user);
        $em->flush();
        $this->track($user);

        $token = $container->get(JWTTokenManagerInterface::class)->create($user);

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        try {
            /** @var RedisConnectionFactory $redisFactory */
            $redisFactory = $container->get(RedisConnectionFactory::class);
            $redis        = $redisFactory->create();
            $redis->ping();
            $stream = 'conv.__prepaid_quota_e2e__';
            $redis->del($stream);
            /** @var SerializerInterface $serializer */
            $serializer = $container->get('messenger.transport.symfony_serializer');
            $container->set(
                'messenger.transport.conv_document',
                new CleanRedisTransport($redisFactory, $serializer, $stream, 'convertor'),
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB not reachable: ' . $e->getMessage());
        }

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'md'],
            ['file'      => $this->upload('txt', "hello\n")],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $client->getResponse();
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('conversion_id', $body);

        /** @var ConversionRepository $conversions */
        $conversions = $container->get(ConversionRepository::class);
        $conversion  = $conversions->find((int) $body['conversion_id']);
        self::assertInstanceOf(Conversion::class, $conversion);
        self::assertSame(BillingMode::PrepaidBalance, $conversion->getBillingMode());
        $this->track($conversion->getInputFile());
        $this->track($conversion);

        $em->refresh($user);
        $payPerUse = $container->get(BalanceService::class)->getPayPerUseCostCents(false);
        self::assertSame($initialBalance - $payPerUse, $user->getBalanceCents());
    }

    public function testFreeUserVideoConversionReturns429InsufficientBalance(): void
    {
        $this->assertFreeUserInsufficientBalance('mp4', 'mkv', $this->mp4Bytes());
    }

    public function testFreeUserAiConversionReturns429InsufficientBalance(): void
    {
        $this->assertFreeUserInsufficientBalance('mp3', 'txt', $this->mp3Bytes());
    }

    public function testGuestOverLightQuotaReturns429WithDailyMessage(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $guestId = 'guest-quota-' . bin2hex(random_bytes(8));
        $guest   = (new User())
            ->setIsGuest(true)
            ->setGuestId($guestId)
            ->setLightDailyConversions(3);
        $em->persist($guest);
        $em->flush();

        $tokens = static::getContainer()->get(GuestTokenService::class);
        $client->getCookieJar()->set(new Cookie(GuestCookieFactory::NAME, $tokens->sign($guestId)));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'md'],
            ['file'      => $this->upload('txt', "hello\n")],
        );

        self::assertSame(429, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertStringContainsString('Daily light', (string) $data['error']);

        $em->remove($guest);
        $em->flush();
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

    private function assertFreeUserInsufficientBalance(string $from, string $to, string $bytes): void
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
        self::assertSame('insufficient_balance', $data['error']);

        $em->remove($user);
        $em->flush();
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

    /** @param class-string $class */
    private function track(object $entity): void
    {
        $this->toRemove[] = ['class' => $entity::class, 'id' => $entity->getId()];
    }
}

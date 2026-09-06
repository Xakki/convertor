<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Messenger\Transport\CleanRedisTransport;
use App\Repository\UserRepository;
use App\Service\Auth\AnonymousIdentityService;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Storage\S3Storage;
use App\Tests\Support\WorkerCapabilityFixture;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Guest conversion E2E coverage for both anonymous-IP and signed-cookie owners.
 *
 * Messenger uses a live, isolated KeyDB stream; S3 is stubbed so the test never
 * submits a fake object to a real worker or storage bucket.
 */
final class GuestConvertCookieE2eTest extends WebTestCase
{
    private const ISOLATED_STREAM     = 'conv.__guest_cookie_e2e__';
    private const ANONYMOUS_IP_PREFIX = '2001:db8:87::';

    /** @var list<array{class: class-string, id: int}> */
    private array $toRemove = [];

    private ?\Redis $redis = null;

    private ?WorkerCapabilityFixture $workerCapabilityFixture = null;

    protected function tearDown(): void
    {
        if ($this->redis !== null) {
            $this->redis->del(self::ISOLATED_STREAM);
            $this->redis = null;
        }

        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $ref) {
                $fresh = $em->find($ref['class'], $ref['id']);
                if ($fresh !== null) {
                    $em->remove($fresh);
                }
            }
            $em->flush();
            $this->toRemove = [];
        }

        if ($this->workerCapabilityFixture !== null && static::$kernel !== null) {
            $this->workerCapabilityFixture->cleanup();
            $this->workerCapabilityFixture->assertNoOwnedRowsRemain();
            $this->workerCapabilityFixture = null;
        }

        parent::tearDown();
    }

    public function testNoCookieConvertUsesAnonymousIpOwnerWithoutCookie(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var AnonymousIdentityService $identity */
        $identity = $container->get(AnonymousIdentityService::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $this->configureConversionPipeline($container);
        $this->normalQueueFixture('image');

        [$clientIp, $expectedIdentity] = $this->unusedAnonymousIp($users, $identity);
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'   => 'txt'],
            ['file'        => $this->uploadedJpg('sample.jpg')],
            ['REMOTE_ADDR' => $clientIp],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode());
        $this->assertNoGuestCookieHeader(
            $client,
            'anonymous-IP guest convert must not emit guest_id, including an empty clearing cookie',
        );

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('conversion_id', $body);
        $conversion = $em->find(Conversion::class, (int) $body['conversion_id']);
        self::assertNotNull($conversion);
        $owner = $conversion->getUser();
        self::assertTrue($owner->isGuest());
        self::assertTrue($owner->isAnonymousIp());

        $expectedIdentity = $identity->fromRequest(Request::create(
            '/api/v1/convert',
            'POST',
            server: ['REMOTE_ADDR' => $clientIp],
        ));
        self::assertNotNull($expectedIdentity);
        self::assertSame($expectedIdentity, $owner->getGuestId());
        self::assertSame($owner->getId(), $users->findActiveAnonymousIpByIdentity($expectedIdentity)?->getId());
        $this->trackConversion($conversion);
        $this->track($owner);

        $entries = $this->redis->xRange(self::ISOLATED_STREAM, '-', '+');
        self::assertIsArray($entries);
        self::assertCount(1, $entries, 'Messenger must XADD exactly one job to the isolated stream');
    }

    public function testValidGuestCookieReusesOwnerWithoutResetCookie(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var GuestTokenService $tokens */
        $tokens = $container->get(GuestTokenService::class);
        $this->configureConversionPipeline($container);
        $this->normalQueueFixture('image');

        $guest = (new User())->setIsGuest(true)->setGuestId($tokens->generateGuestId());
        $em->persist($guest);
        $em->flush();
        $this->track($guest);
        $guestId = $guest->getId();
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(
            GuestCookieFactory::NAME,
            $tokens->sign((string) $guest->getGuestId()),
        ));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'   => 'txt'],
            ['file'        => $this->uploadedJpg('sample.jpg')],
            ['REMOTE_ADDR' => '198.51.100.44'],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode());
        $this->assertNoGuestCookieHeader(
            $client,
            'valid guest_id owner must not emit a replacement or clearing guest_id cookie',
        );
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $conversion = $em->find(Conversion::class, (int) $body['conversion_id']);
        self::assertNotNull($conversion);
        self::assertSame($guestId, $conversion->getUser()->getId());
        self::assertFalse($conversion->getUser()->isAnonymousIp());
        $this->trackConversion($conversion);
    }

    private function configureConversionPipeline(ContainerInterface $container): void
    {
        try {
            /** @var RedisConnectionFactory $redisFactory */
            $redisFactory = $container->get(RedisConnectionFactory::class);
            $this->redis  = $redisFactory->create();
            $this->redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB not reachable: ' . $e->getMessage());
        }

        $this->redis->del(self::ISOLATED_STREAM);
        /** @var SerializerInterface $serializer */
        $serializer = $container->get('messenger.transport.symfony_serializer');
        $container->set(
            'messenger.transport.conv_image',
            new CleanRedisTransport($redisFactory, $serializer, self::ISOLATED_STREAM, 'convertor'),
        );
        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));
    }

    private function normalQueueFixture(string $workerType): void
    {
        $this->workerCapabilityFixture ??= new WorkerCapabilityFixture(
            static::getContainer()->get(\App\Repository\WorkerCapabilityRepository::class),
            static::getContainer()->get(EntityManagerInterface::class),
        );
        $this->workerCapabilityFixture->addNormal($workerType);
    }

    public function testAnonymousIpSetupSkipsExistingIdentityCollision(): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var AnonymousIdentityService $identity */
        $identity = $container->get(AnonymousIdentityService::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);

        [$collidingIp, $collidingIdentity] = $this->unusedAnonymousIp($users, $identity);
        $collision                         = (new User())
            ->setIsGuest(true)
            ->setAnonymousIp(true)
            ->setGuestId($collidingIdentity);
        $em->persist($collision);
        $em->flush();
        $this->track($collision);

        [$selectedIp, $selectedIdentity] = $this->unusedAnonymousIp($users, $identity);

        self::assertNotSame($collidingIp, $selectedIp);
        self::assertNotSame($collidingIdentity, $selectedIdentity);
        self::assertNull($users->findOneBy(['guestId' => $selectedIdentity]));
    }

    private function unusedAnonymousIp(
        UserRepository $users,
        AnonymousIdentityService $identity,
        int $start = 1,
    ): array {
        for ($suffix = $start; $suffix <= 65535; ++$suffix) {
            $clientIp         = self::ANONYMOUS_IP_PREFIX . dechex($suffix);
            $expectedIdentity = $identity->fromRequest(Request::create(
                '/api/v1/convert',
                'POST',
                server: ['REMOTE_ADDR' => $clientIp],
            ));
            self::assertNotNull($expectedIdentity);

            // Check every row: a stale/inactive identity must never be reused.
            if ($users->findOneBy(['guestId' => $expectedIdentity]) === null) {
                return [$clientIp, $expectedIdentity];
            }
        }

        self::fail('No unused deterministic anonymous-IP identity available');
    }

    private function assertNoGuestCookieHeader(KernelBrowser $client, string $message): void
    {
        $cookieNames = array_map(
            static fn (\Symfony\Component\HttpFoundation\Cookie $cookie): string => $cookie->getName(),
            $client->getResponse()->headers->getCookies(),
        );

        self::assertNotContains(GuestCookieFactory::NAME, $cookieNames, $message);
    }

    private function trackConversion(Conversion $conversion): void
    {
        $this->track($conversion);
        $input = $conversion->getInputFile();
        if ($input instanceof FileStorage && $input->getId() !== null) {
            $this->track($input);
        }
    }

    private function track(object $entity): void
    {
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        if (is_int($id)) {
            $this->toRemove[] = ['class' => $entity::class, 'id' => $id];
        }
    }

    private function uploadedJpg(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'guest_e2e_');
        self::assertNotFalse($path);
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

}

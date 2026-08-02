<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Messenger\Transport\CleanRedisTransport;
use App\Repository\UserRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * E2E success-путь гостевого convert: HTTP 202 + подписанная cookie `guest_id` +
 * ровно одна новая guest-строка в `users`.
 *
 * Закрывает coverage-gap object-identity: unit-тесты по отдельности не ловят
 * рефактор, где GuestAuthenticator/ConversionManager/GuestCookieResponseListener
 * теряют один и тот же User-инстанс — строка пишется, cookie не эмитится.
 *
 * Живой Messenger (`conv+redis://` → KeyDB db3 на тест-стенде), НЕ in-memory.
 * Стрим подменяется на одноразовый `conv.__guest_cookie_e2e__`, чтобы не
 * мусорить рабочие `conv.*` и не кормить воркеров фейковым S3-ключом.
 * S3 — stub через `container->set(S3Storage::class, …)` (как FileCleanupServiceTest).
 */
final class GuestConvertCookieE2eTest extends WebTestCase
{
    private const ISOLATED_STREAM = 'conv.__guest_cookie_e2e__';

    /** @var list<array{class: class-string, id: int}> */
    private array $toRemove = [];

    private ?\Redis $redis = null;

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

        parent::tearDown();
    }

    public function testGuestConvertReturns202MintsSignedCookieAndOneGuestRow(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var GuestTokenService $tokens */
        $tokens = $container->get(GuestTokenService::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);

        // KeyDB обязателен: живой Messenger-транспорт, не stub MessageBus.
        try {
            /** @var RedisConnectionFactory $redisFactory */
            $redisFactory = $container->get(RedisConnectionFactory::class);
            $this->redis  = $redisFactory->create();
            $this->redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB not reachable: ' . $e->getMessage());
        }

        $this->redis->del(self::ISOLATED_STREAM);

        // Изолированный стрим вместо conv.image — воркеры не подхватят фейковый job.
        /** @var SerializerInterface $serializer */
        $serializer = $container->get('messenger.transport.symfony_serializer');
        $container->set(
            'messenger.transport.conv_image',
            new CleanRedisTransport($redisFactory, $serializer, self::ISOLATED_STREAM, 'convertor'),
        );

        // S3 stub: putObject no-op (реальный MinIO не трогаем).
        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $guestsBefore = $this->guestCount($em);

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'txt'],
            ['file'      => $this->uploadedJpg('sample.jpg')],
        );

        $response = $client->getResponse();
        self::assertSame(
            202,
            $response->getStatusCode(),
            'guest jpg→txt must return 202, got ' . $response->getStatusCode() . ': ' . $response->getContent(),
        );

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('conversion_id', $body);
        $conversionId = (int) $body['conversion_id'];
        self::assertGreaterThan(0, $conversionId);

        // Ровно одна новая guest-строка.
        self::assertSame(
            $guestsBefore + 1,
            $this->guestCount($em),
            'successful guest convert must create exactly one new guest users row',
        );

        // Подписанная cookie guest_id на том же ответе (object-identity invariant).
        $cookie = $this->guestCookieFromResponse($client);
        self::assertNotNull($cookie, '202 guest convert must Set-Cookie guest_id');
        $rawGuestId = $tokens->verify((string) $cookie->getValue());
        self::assertNotNull($rawGuestId, 'guest_id cookie must carry a valid HMAC signature');

        $guest = $users->findActiveGuestByGuestId($rawGuestId);
        self::assertNotNull($guest, 'signed guest_id must resolve to the persisted guest User');
        self::assertTrue($guest->isGuest());

        // Живой XADD в изолированный стрим (не in-memory bus).
        $entries = $this->redis->xRange(self::ISOLATED_STREAM, '-', '+');
        self::assertIsArray($entries);
        self::assertCount(1, $entries, 'Messenger must XADD exactly one job to the isolated stream');

        // Cleanup-треки (Conversion → FileStorage → User).
        $conversion = $em->find(Conversion::class, $conversionId);
        self::assertNotNull($conversion);
        $input = $conversion->getInputFile();
        $this->track($conversion);
        if ($input instanceof FileStorage && $input->getId() !== null) {
            $this->track($input);
        }
        $this->track($guest);
    }

    private function guestCount(EntityManagerInterface $em): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.isGuest = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function track(object $entity): void
    {
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        if (! is_int($id)) {
            return;
        }
        $this->toRemove[] = ['class' => $entity::class, 'id' => $id];
    }

    private function uploadedJpg(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'guest_e2e_');
        self::assertNotFalse($path);
        // Минимальный JFIF — finfo нюхает image/jpeg.
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function guestCookieFromResponse(KernelBrowser $client): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestCookieFactory::NAME && $cookie->getValue() !== null && $cookie->getValue() !== '') {
                return $cookie;
            }
        }

        return null;
    }
}

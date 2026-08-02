<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Response;
use AsyncAws\Core\Test\Http\SimpleMockedResponse;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Функциональные тесты operator-recovery для DLQ (карта conv-dead-no-consumer).
 * Граница — ROLE_ADMIN на JWT-firewall (Option B), как у остальных admin-API.
 *
 * `MessageBusInterface` подменяется мок-шпионом (как в остальных тестах, где
 * side-effecting singletons подменяются через `container->set()`) — реального
 * XADD в KeyDB здесь не проверяем, только факт и содержимое dispatch() (это
 * покрывает уже {@see \App\Tests\Unit\Service\Conversion\ConversionManagerOcrTest}
 * для самого ConversionManager::dispatch()). `S3Storage` — реальный класс поверх
 * мокнутого низкоуровневого `S3Client` (final-класс нельзя мокать напрямую).
 */
final class DlqControllerTest extends WebTestCase
{
    /** @var list<object> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $entity) {
                $managed = $em->contains($entity) ? $entity : $em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
        $this->toRemove = [];
    }

    public function testRequeueForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => 1], JSON_THROW_ON_ERROR),
        );
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRequeueUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => 1], JSON_THROW_ON_ERROR),
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRequeueReturns404ForMissingConversion(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => 999999999], JSON_THROW_ON_ERROR),
        );
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testRequeueReturns409WhenNotFailed(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $conversion = $this->persistConversion($em, ConversionStatus::Completed);
        $token      = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => $conversion->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertSame(409, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not_failed', $body['error']);
    }

    /**
     * CNV-49: S3 objectExists — до FOR UPDATE; отсутствие объекта → 409
     * `input_gone`, статус остаётся Failed (gate до flip / без lock hold).
     */
    public function testRequeueReturns409WhenInputObjectGone(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $conversion = $this->persistConversion($em, ConversionStatus::Failed);

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn($this->notFoundHeadObjectOutput());
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $token = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => $conversion->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertSame(409, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('input_gone', $body['error']);

        // Status must stay Failed — the gate runs BEFORE the status flip.
        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertSame(ConversionStatus::Failed, $reloaded->getStatus());
    }

    /**
     * Happy path: Failed → Pending + errorMessage cleared + dispatch() invoked
     * through the SAME producer path as the initial submit (ConversionManager),
     * targeting the transport for the conversion's category.
     */
    public function testRequeueResetsStatusAndDispatches(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $conversion = $this->persistConversion($em, ConversionStatus::Failed);

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps));
        $container->set(MessageBusInterface::class, $bus);

        $token = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => $conversion->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
        self::assertSame($conversion->getId(), $body['conversionId']);
        self::assertSame('pending', $body['status']);
        self::assertSame(1, $body['attempt']);

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ConversionStatus::Pending, $reloaded->getStatus());
        self::assertNull($reloaded->getErrorMessage());
        self::assertNull($reloaded->getProcessingMs());
        // requeue-attempt-generation-marker: bumped from the default 0.
        self::assertSame(1, $reloaded->getAttempt());
    }

    /**
     * CNV-30: requeue respects quota via check()+charge() — at daily limit the
     * operator gets 429, status stays Failed, counters unchanged.
     */
    public function testRequeueReturns429WhenQuotaExceededAtDailyLimit(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $conversion = $this->persistConversion($em, ConversionStatus::Failed);
        $owner      = $conversion->getUser();
        $owner->setPlan('free');
        $owner->setMediumDailyConversions(2); // free medium daily = 2 — already AT the limit
        $em->flush();

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $container->set(MessageBusInterface::class, $bus);

        $token = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => $conversion->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertSame(429, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $em->clear();
        $reloadedOwner = $em->find(User::class, $owner->getId());
        self::assertNotNull($reloadedOwner);
        self::assertSame(2, $reloadedOwner->getMediumDailyConversions());

        $reloadedConversion = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloadedConversion);
        self::assertSame(ConversionStatus::Failed, $reloadedConversion->getStatus());
    }

    /**
     * CNV-11: повторный requeue уже переведённой в Pending конверсии — 409
     * `not_failed` (already-requeued), квота не списывается повторно, второй
     * dispatch не вызывается. Под FOR UPDATE тот же исход у параллельного
     * вызова: второй ждёт лок, затем видит не-Failed.
     */
    public function testSecondRequeueReturnsConflictWithoutDoubleChargeOrDispatch(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $conversion   = $this->persistConversion($em, ConversionStatus::Failed);
        $owner        = $conversion->getUser();
        $ownerId      = $owner->getId();
        $conversionId = $conversion->getId();
        $dailyBefore  = $owner->getMediumDailyConversions();

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps));
        $container->set(MessageBusInterface::class, $bus);

        $token   = $this->jwtFor($this->persistUser(true));
        $server  = ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'];
        $payload = json_encode(['conversionId' => $conversionId], JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/admin/dead-letter/requeue', server: $server, content: $payload);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $client->request('POST', '/api/v1/admin/dead-letter/requeue', server: $server, content: $payload);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not_failed', $body['error']);

        $em->clear();
        $reloadedOwner = $em->find(User::class, $ownerId);
        self::assertNotNull($reloadedOwner);
        // Ровно одно re-charge (+1), не два.
        self::assertSame($dailyBefore + 1, $reloadedOwner->getMediumDailyConversions());

        $reloadedConversion = $em->find(Conversion::class, $conversionId);
        self::assertNotNull($reloadedConversion);
        self::assertSame(ConversionStatus::Pending, $reloadedConversion->getStatus());
        self::assertSame(1, $reloadedConversion->getAttempt());
    }

    /**
     * MINOR fix: a synchronous dispatch() failure (e.g. KeyDB XADD error) must
     * NOT leave the row zombie-Pending with no job actually enqueued.
     * Compensating rollback: status back to Failed, the +1 re-charge refunded
     * (net quota effect zero), 503 returned — the row is retryable via the same
     * endpoint, not stuck.
     */
    public function testRequeueRollsBackStatusAndQuotaOnDispatchFailure(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $conversion  = $this->persistConversion($em, ConversionStatus::Failed);
        $owner       = $conversion->getUser();
        $ownerId     = $owner->getId();
        $dailyBefore = $owner->getMediumDailyConversions();

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('KeyDB unreachable'));
        $container->set(MessageBusInterface::class, $bus);

        $token = $this->jwtFor($this->persistUser(true));

        $client->request(
            'POST',
            '/api/v1/admin/dead-letter/requeue',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['conversionId' => $conversion->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertSame(503, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('dispatch_failed', $body['error']);

        $em->clear();
        $reloadedConversion = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloadedConversion);
        // Row stays Failed (not zombie-Pending) — the operator can retry requeue.
        self::assertSame(ConversionStatus::Failed, $reloadedConversion->getStatus());

        $reloadedOwner = $em->find(User::class, $ownerId);
        self::assertNotNull($reloadedOwner);
        // Re-charge was refunded: net quota effect is zero.
        self::assertSame($dailyBefore, $reloadedOwner->getMediumDailyConversions());
    }

    private function notFoundHeadObjectOutput(): HeadObjectOutput
    {
        $httpResponse = new SimpleMockedResponse('', [], 404);
        $httpClient   = new MockHttpClient($httpResponse);
        $response     = new Response(
            $httpClient->request('GET', 'http://localhost'),
            $httpClient,
            new NullLogger(),
            null,
            null,
            null,
            false,
            ['http_status_code_404' => NoSuchKeyException::class],
        );

        return new HeadObjectOutput($response);
    }

    private function persistConversion(EntityManagerInterface $em, ConversionStatus $status): Conversion
    {
        $owner = new User();
        $em->persist($owner);
        $em->flush();
        $this->toRemove[] = $owner;

        $inputFile = (new FileStorage())
            ->setOriginalName('audio.mp3')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.mp3')
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setFromFormat('mp3')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Audio)
            ->setStatus($status)
            ->setErrorMessage($status === ConversionStatus::Failed ? 'boom' : null)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($conversion);
        $em->flush();
        $this->toRemove[] = $conversion;

        return $conversion;
    }

    private function persistUser(bool $admin): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setIsAdmin($admin);
        $em->persist($user);
        $em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function jwtFor(User $user): string
    {
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwt->create($user);
    }
}

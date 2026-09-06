<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Security\GuestAuthenticator;
use App\Service\Auth\AnonymousIdentityCleanupService;
use App\Service\Auth\AnonymousIdentityService;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Auth\PersonalApiTokenService;
use App\Tests\Support\CaptureLogger;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Depends;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class AnonymousPrivacyBoundaryTest extends WebTestCase
{
    private const HMAC_SECRET = 'test-only-privacy-hmac-secret';
    private const SOURCE_IP   = '192.0.2.112';

    /** @var list<object> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        try {
            if ($this->toRemove !== [] && static::$kernel !== null) {
                $em = static::getContainer()->get(EntityManagerInterface::class);
                foreach (array_reverse($this->toRemove) as $entity) {
                    if ($em->contains($entity)) {
                        $em->remove($entity);
                    }
                }
                $em->flush();
                $this->toRemove = [];
            }
        } finally {
            try {
                if (static::$kernel !== null) {
                    static::getContainer()->get(CaptureLogger::class)->reset();
                }
            } finally {
                parent::tearDown();
            }
        }
    }

    public function testRealKernelAuthenticatorPrecedenceAndMalformedBearerFailClosed(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $logger    = $container->get(CaptureLogger::class);
        $logger->reset();
        $em         = $container->get(EntityManagerInterface::class);
        $suffix     = bin2hex(random_bytes(8));
        $guest      = (new User())->setIsGuest(true)->setGuestId('cookie-privacy-owner-' . $suffix);
        $jwtOwner   = (new User())->setEmail('cnv112-jwt-' . $suffix . '@example.test')->setPlan('basic');
        $tokenOwner = (new User())->setEmail('cnv112-token-' . $suffix . '@example.test')->setPlan('pro');
        foreach ([$guest, $jwtOwner, $tokenOwner] as $user) {
            $em->persist($user);
            $this->toRemove[] = $user;
        }
        $em->flush();

        $personal         = $container->get(PersonalApiTokenService::class)->issue($tokenOwner, 'privacy-test');
        $this->toRemove[] = $personal['token'];
        $guestCookie      = $container->get(GuestTokenService::class)->sign((string) $guest->getGuestId());
        $jwt              = $container->get(JWTTokenManagerInterface::class)->create($jwtOwner);

        $client->getCookieJar()->set(new Cookie(GuestCookieFactory::NAME, $guestCookie));

        $client->request('GET', '/api/v1/quota', server: [
            'REMOTE_ADDR'        => self::SOURCE_IP,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('basic', $this->quotaPlan($client));
        self::assertSame([], $logger->records());

        $client->request('GET', '/api/v1/quota', server: [
            'REMOTE_ADDR'        => self::SOURCE_IP,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $personal['secret'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('pro', $this->quotaPlan($client));
        self::assertSame([], $logger->records());

        $client->request('GET', '/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('guest', $this->quotaPlan($client));
        self::assertSame([], $logger->records());

        $client->getCookieJar()->clear();
        $before = $em->getRepository(User::class)->count(['anonymousIp' => true]);
        $client->request('GET', '/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('guest', $this->quotaPlan($client));
        self::assertSame($before, $em->getRepository(User::class)->count(['anonymousIp' => true]));
        self::assertNull($this->guestCookie($client));

        $client->request('GET', '/api/v1/quota', server: [
            'REMOTE_ADDR'        => self::SOURCE_IP,
            'HTTP_AUTHORIZATION' => 'Bearer malformed',
        ]);
        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertSame([], $logger->records());
    }

    public function testAnonymousResolutionEmitsOnlySafeDebugAuditContext(): void
    {
        $client = static::createClient();
        $logger = static::getContainer()->get(CaptureLogger::class);
        $logger->reset();

        $client->request('GET', '/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame('guest', $this->quotaPlan($client));
        self::assertSame([
            [
                'level'   => LogLevel::DEBUG,
                'message' => 'Anonymous API identity resolved',
                'context' => [
                    'auth_identity_type' => 'anonymous_ip',
                    'identity_opaque'    => true,
                ],
            ],
        ], $logger->records());
    }

    public function testLoggerFailureDoesNotChangeAnonymousAuthentication(): void
    {
        $client = static::createClient();
        $logger = static::getContainer()->get(CaptureLogger::class);
        $logger->reset();
        $logger->throwOnLog();

        $client->request('GET', '/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame('guest', $this->quotaPlan($client));
    }

    #[Depends('testLoggerFailureDoesNotChangeAnonymousAuthentication')]
    public function testLoggerFailureStateDoesNotBleedIntoFollowingTest(mixed $ignored = null): void
    {
        $logger = static::getContainer()->get(CaptureLogger::class);
        $logger->log(LogLevel::INFO, 'following test');

        self::assertSame([
            [
                'level'   => LogLevel::INFO,
                'message' => 'following test',
                'context' => [],
            ],
        ], $logger->records());
    }

    public function testAnonymousPersistenceApiAndConfiguredLoggerContainNoRawInputs(): void
    {
        $client           = static::createClient();
        $container        = static::getContainer();
        $em               = $container->get(EntityManagerInterface::class);
        $request          = Request::create('/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);
        $expectedIdentity = $container->get(AnonymousIdentityService::class)->fromRequest($request);
        self::assertNotNull($expectedIdentity);
        $client->request('GET', '/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertSame('guest', $this->quotaPlan($client));
        self::assertStringNotContainsString(self::SOURCE_IP, $body);
        self::assertStringNotContainsString(self::HMAC_SECRET, $body);
        self::assertStringNotContainsString($expectedIdentity, $body);
        foreach ($response->headers->all() as $values) {
            foreach ($values as $value) {
                self::assertStringNotContainsString(self::SOURCE_IP, $value);
                self::assertStringNotContainsString(self::HMAC_SECRET, $value);
                self::assertStringNotContainsString($expectedIdentity, $value);
            }
        }

        $request       = Request::create('/api/v1/quota', server: ['REMOTE_ADDR' => self::SOURCE_IP]);
        $passport      = $container->get(GuestAuthenticator::class)->authenticate($request);
        $anonymousUser = $passport->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $anonymousUser);
        self::assertTrue($anonymousUser->isAnonymousIp());
        $identity = $anonymousUser->getGuestId();
        self::assertNotNull($identity);
        self::assertSame($expectedIdentity, $identity);
        self::assertNotSame(self::SOURCE_IP, $identity);
        self::assertNotSame(self::HMAC_SECRET, $identity);

        $em->persist($anonymousUser);
        $em->flush();
        $userId = $anonymousUser->getId();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($identity, $reloaded->getGuestId());
        $storedValues = $em->getConnection()->fetchAssociative(
            'SELECT guest_id, anonymous_ip FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertIsArray($storedValues);
        self::assertSame($identity, $storedValues['guest_id']);
        self::assertNotSame(self::SOURCE_IP, $storedValues['guest_id']);
        self::assertNotSame(self::HMAC_SECRET, $storedValues['guest_id']);

        // Symfony's configured logger is the actual boundary here. This request
        // emits no record, so the test must not fabricate a Monolog handler/context.
        self::assertInstanceOf(\Symfony\Component\HttpKernel\Log\Logger::class, $container->get('logger'));
        $this->toRemove[] = $reloaded;
    }

    public function testThirtyDayCleanupUsesCreatedAtOnlyAndLeavesCookieAndConversionData(): void
    {
        $em           = static::getContainer()->get(EntityManagerInterface::class);
        $suffix       = bin2hex(random_bytes(8));
        $oldAnonymous = $this->user(true, 'cnv112-old-anonymous-' . $suffix);
        $atCutoff     = $this->user(true, 'cnv112-at-cutoff-' . $suffix);
        $cookieGuest  = $this->user(false, 'cnv112-old-cookie-' . $suffix);
        $this->setCreatedAt($oldAnonymous, new \DateTimeImmutable('-31 days'));
        $this->setCreatedAt($atCutoff, new \DateTimeImmutable('-29 days'));
        $this->setCreatedAt($cookieGuest, new \DateTimeImmutable('-31 days'));

        $file       = (new FileStorage())->setOriginalName('privacy.txt')->setStoragePath('privacy/input.txt')->setMimeType('text/plain')->setSizeBytes(1);
        $conversion = (new Conversion())
            ->setUser($oldAnonymous)
            ->setInputFile($file)
            ->setFromFormat('txt')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Document);
        $em->persist($file);
        $em->persist($conversion);
        $this->toRemove[] = $conversion;
        $this->toRemove[] = $file;
        $em->flush();
        $oldId        = $oldAnonymous->getId();
        $cutoffId     = $atCutoff->getId();
        $cookieId     = $cookieGuest->getId();
        $conversionId = $conversion->getId();

        $changed = static::getContainer()->get(AnonymousIdentityCleanupService::class)->run();
        self::assertGreaterThanOrEqual(1, $changed);
        $em->clear();

        $old           = $em->find(User::class, $oldId);
        $atCutoffFresh = $em->find(User::class, $cutoffId);
        $cookie        = $em->find(User::class, $cookieId);
        self::assertNotNull($old);
        self::assertFalse($old->isActive());
        self::assertNull($old->getGuestId());
        self::assertNotNull($atCutoffFresh);
        self::assertTrue($atCutoffFresh->isActive());
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isActive());
        self::assertStringStartsWith('cnv112-old-cookie-', (string) $cookie->getGuestId());
        self::assertNotNull($em->find(Conversion::class, $conversionId));
        self::assertNotNull($em->find(FileStorage::class, $file->getId()));
    }

    private function user(bool $anonymousIp, string $guestId): User
    {
        $user = (new User())->setIsGuest(true)->setAnonymousIp($anonymousIp)->setGuestId($guestId);
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $this->toRemove[] = $user;
        $em->flush();

        return $user;
    }

    private function setCreatedAt(User $user, \DateTimeImmutable $createdAt): void
    {
        (new \ReflectionProperty(User::class, 'createdAt'))->setValue($user, $createdAt);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function quotaPlan(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['plan'] ?? null);

        return $data['plan'];
    }

    private function guestCookie(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestCookieFactory::NAME) {
                return $cookie;
            }
        }

        return null;
    }
}

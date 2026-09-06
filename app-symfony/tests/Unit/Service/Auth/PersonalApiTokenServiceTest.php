<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\PersonalApiToken;
use App\Entity\User;
use App\Repository\PersonalApiTokenRepository;
use App\Service\Auth\PersonalApiTokenService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PersonalApiTokenServiceTest extends TestCase
{
    public function testIssueReturnsSecretOnceAndStoresOnlyVerifier(): void
    {
        $repo = $this->createMock(PersonalApiTokenRepository::class);
        $repo->expects(self::once())->method('countActiveForUser')->willReturn(0);
        $persisted = null;
        $repo->expects(self::once())->method('save')->willReturnCallback(static function (PersonalApiToken $token, bool $flush) use (&$persisted): void {
            $persisted = $token;
        });
        $user = (new User())->setIsGuest(false);
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): array => $callback());
        $em->method('find')->willReturn($user);

        $result = (new PersonalApiTokenService($repo, $em))->issue($user, 'build');

        self::assertMatchesRegularExpression('/^cnv_[A-Za-z0-9_-]{43}$/', $result['secret']);
        $reflection = new \ReflectionProperty(PersonalApiToken::class, 'verifier');
        self::assertNotNull($persisted);
        self::assertNotSame($result['secret'], $reflection->getValue($persisted));
        self::assertTrue($persisted->matches($result['secret']));
    }

    public function testRevokedAndMalformedSecretsCannotMatch(): void
    {
        $user  = (new User())->setIsGuest(false);
        $token = new PersonalApiToken($user, 'ci', 'cnv_12345678', hash('sha256', 'cnv_secret'));
        self::assertTrue($token->matches('cnv_secret'));
        self::assertFalse($token->matches('cnv_other'));
        self::assertTrue($token->isActive());
        $token->revoke();
        self::assertFalse($token->isActive());
    }

    public function testAuthenticationSucceedsWhenLastUsedWriteFails(): void
    {
        $user   = (new User())->setIsGuest(false);
        $secret = 'cnv_' . str_repeat('A', 43);
        $token  = new PersonalApiToken($user, 'ci', substr($secret, 0, 12), hash('sha256', $secret));
        $repo   = $this->createMock(PersonalApiTokenRepository::class);
        $repo->expects(self::once())->method('findByVerifier')->willReturn($token);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('transient metadata failure'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        self::assertSame($token, (new PersonalApiTokenService($repo, $em))->authenticate($secret));
        self::assertNull($token->getLastUsedAt());
    }
}

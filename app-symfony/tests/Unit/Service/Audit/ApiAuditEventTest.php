<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Audit;

use App\DTO\ApiAuditEvent;
use App\DTO\ApiAuditIdentityType;
use App\DTO\ApiAuditTokenMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiAuditEventTest extends TestCase
{
    public function testContractSerializesOnlyAllowlistedFields(): void
    {
        $event = new ApiAuditEvent(
            'GET',
            '/api/v1/convert/{id}/status',
            404,
            12,
            $this->personalToken(),
            42,
            new \DateTimeImmutable('2026-09-05T12:00:00+00:00'),
        );

        self::assertSame(['method', 'route', 'status', 'duration_ms', 'token_label', 'token_mask', 'conversion_id', 'created_at'], array_keys($event->toArray()));
        self::assertArrayNotHasKey('authorization', $event->toArray());
        self::assertArrayNotHasKey('body', $event->toArray());
        self::assertArrayNotHasKey('ip', $event->toArray());
        self::assertArrayNotHasKey('user_agent', $event->toArray());
    }

    public function testContractAcceptsSuccessfulAndErrorResponses(): void
    {
        foreach ([200, 404, 500] as $status) {
            $event = new ApiAuditEvent('GET', '/api/v1/quota', $status, 1, $this->personalToken(), null, new \DateTimeImmutable());
            self::assertSame($status, $event->status);
        }
    }

    public function testContractRejectsInformationalStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiAuditEvent('GET', '/api/v1/quota', 100, 1, $this->personalToken(), null, new \DateTimeImmutable());
    }

    public function testContractRejectsQueryBearingRoute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiAuditEvent('GET', '/api/v1/convert?token=secret', 200, 1, $this->personalToken(), null, new \DateTimeImmutable());
    }

    #[DataProvider('invalidTokenMetadataProvider')]
    public function testContractRejectsNonPersonalOrUnsafeTokenMetadata(ApiAuditIdentityType $identityType, string $label, string $mask): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiAuditTokenMetadata($label, $mask, $identityType);
    }

    /** @return iterable<string, array{ApiAuditIdentityType, string, string}> */
    public static function invalidTokenMetadataProvider(): iterable
    {
        yield 'jwt identity' => [ApiAuditIdentityType::JWT, 'build', 'cnv_********'];
        yield 'guest identity' => [ApiAuditIdentityType::GUEST, 'build', 'cnv_********'];
        yield 'worker identity' => [ApiAuditIdentityType::WORKER, 'build', 'cnv_********'];
        yield 'internal identity' => [ApiAuditIdentityType::INTERNAL, 'build', 'cnv_********'];
        yield 'missing token label' => [ApiAuditIdentityType::PERSONAL_TOKEN, '', 'cnv_********'];
        yield 'plaintext token label' => [ApiAuditIdentityType::PERSONAL_TOKEN, 'cnv_plaintext', 'cnv_********'];
        yield 'raw token mask' => [ApiAuditIdentityType::PERSONAL_TOKEN, 'build', 'cnv_secret'];
        yield 'authorization header' => [ApiAuditIdentityType::PERSONAL_TOKEN, 'Bearer cnv_secret', 'cnv_********'];
    }

    private function personalToken(): ApiAuditTokenMetadata
    {
        return new ApiAuditTokenMetadata('build', 'cnv_********', ApiAuditIdentityType::PERSONAL_TOKEN);
    }
}

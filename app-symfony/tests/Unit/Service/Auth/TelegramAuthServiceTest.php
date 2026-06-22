<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\DTO\TelegramAuthDTO;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class TelegramAuthServiceTest extends TestCase
{
    private const BOT_TOKEN = '123456:TEST-bot-token';

    private function makeService(int $maxAuthAge = 86400): TelegramAuthService
    {
        return new TelegramAuthService(
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            self::BOT_TOKEN,
            $maxAuthAge,
        );
    }

    /**
     * Build a DTO with a valid Telegram hash for the given auth_date, mirroring
     * TelegramAuthService::buildCheckString (filter nulls, ksort, "k=v" join).
     */
    private function makeSignedDto(int $authDate): TelegramAuthDTO
    {
        $fields = [
            'auth_date'  => (string) $authDate,
            'first_name' => 'Ada',
            'id'         => '42',
            'username'   => 'ada',
        ];
        ksort($fields);

        $checkString = implode("\n", array_map(
            static fn (string $k, string $v) => "{$k}={$v}",
            array_keys($fields),
            array_values($fields),
        ));

        $secretKey = hash('sha256', self::BOT_TOKEN, true);
        $hash      = hash_hmac('sha256', $checkString, $secretKey);

        return new TelegramAuthDTO(
            id: '42',
            firstName: 'Ada',
            lastName: null,
            username: 'ada',
            photoUrl: null,
            authDate: $authDate,
            hash: $hash,
        );
    }

    public function testFreshAuthDateIsAccepted(): void
    {
        $dto = $this->makeSignedDto(time() - 60);

        self::assertTrue($this->makeService()->verify($dto));
    }

    public function testStaleAuthDateIsRejected(): void
    {
        // Older than the 24h default window → replay guard rejects it.
        $dto = $this->makeSignedDto(time() - 86400 - 60);

        self::assertFalse($this->makeService()->verify($dto));
    }

    public function testFarFutureAuthDateIsRejected(): void
    {
        $dto = $this->makeSignedDto(time() + 3600);

        self::assertFalse($this->makeService()->verify($dto));
    }

    public function testMissingAuthDateIsRejected(): void
    {
        $dto = new TelegramAuthDTO(
            id: '42',
            firstName: 'Ada',
            authDate: null,
            hash: 'deadbeef',
        );

        self::assertFalse($this->makeService()->verify($dto));
    }

    public function testTamperedHashIsRejected(): void
    {
        $authDate = time() - 60;
        $signed   = $this->makeSignedDto($authDate);

        $tampered = new TelegramAuthDTO(
            id: $signed->id,
            firstName: $signed->firstName,
            lastName: $signed->lastName,
            username: $signed->username,
            photoUrl: $signed->photoUrl,
            authDate: $signed->authDate,
            hash: ($signed->hash ?? '') . '00',
        );

        self::assertFalse($this->makeService()->verify($tampered));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Service\Auth\TelegramAvatarService;
use App\Service\Auth\TelegramBotClient;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Input\PutObjectRequest;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Юнит-тесты TelegramAvatarService: фетч фото → getFile → download → put в S3
 * (results-бакет, ключ avatars/{id}.ext) → photoUrl = S3-ключ. Нет фото / ошибка
 * — не фатально, photoUrl не трогается. S3Storage — final, строим поверх мока
 * S3Client (паттерн репо).
 */
final class TelegramAvatarServiceTest extends TestCase
{
    private function user(int $id, string $tgId): User
    {
        $user = (new User())->setTelegramId($tgId);
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    public function testFetchesAndCachesAvatarInS3(): void
    {
        $user = $this->user(42, '12345');

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())->method('getUserProfilePhotos')->with('12345')->willReturn([
            'ok'     => true,
            'result' => ['photos' => [[
                ['file_id' => 'small', 'width' => 160, 'height' => 160],
                ['file_id' => 'big', 'width' => 640, 'height' => 640],
            ]]],
        ]);
        // Ожидаем выбор ≤320 → 'small' (проверяем аргументом with).
        $bot->expects(self::once())->method('getFile')->with('small')->willReturn(['result' => ['file_path' => 'photos/f.jpg']]);
        $bot->expects(self::once())->method('downloadFile')->with('photos/f.jpg')->willReturn('IMG');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::once())
            ->method('putObject')
            ->with(self::callback(static function (PutObjectRequest $req): bool {
                return $req->getBucket()      === 'test_-results'
                    && $req->getKey()         === 'avatars/42.jpg'
                    && $req->getContentType() === 'image/jpeg';
            }))
            ->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new TelegramAvatarService($bot, new S3Storage($s3Client, 'test_'), $em, new NullLogger()))->refreshAvatar($user);

        self::assertSame('avatars/42.jpg', $user->getPhotoUrl());
    }

    public function testNoPhotoLeavesAvatarNull(): void
    {
        $user = $this->user(7, '999');

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->method('getUserProfilePhotos')->willReturn(['ok' => true, 'result' => ['photos' => []]]);
        $bot->expects(self::never())->method('getFile');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::never())->method('putObject');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        (new TelegramAvatarService($bot, new S3Storage($s3Client, 'test_'), $em, new NullLogger()))->refreshAvatar($user);

        self::assertNull($user->getPhotoUrl());
    }

    public function testDownloadFailureIsNonFatal(): void
    {
        $user = $this->user(8, '111');

        $bot = $this->createStub(TelegramBotClient::class);
        $bot->method('getUserProfilePhotos')->willReturn([
            'result' => ['photos' => [[['file_id' => 'x', 'width' => 160]]]],
        ]);
        $bot->method('getFile')->willReturn(['result' => ['file_path' => 'photos/f.png']]);
        $bot->method('downloadFile')->willReturn(null);

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::never())->method('putObject');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        (new TelegramAvatarService($bot, new S3Storage($s3Client, 'test_'), $em, new NullLogger()))->refreshAvatar($user);

        self::assertNull($user->getPhotoUrl());
    }

    public function testTransientUserWithoutIdIsSkipped(): void
    {
        $user = (new User())->setTelegramId('123'); // id === null

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::never())->method('getUserProfilePhotos');

        $s3Client = $this->createStub(S3Client::class);
        $em       = $this->createStub(EntityManagerInterface::class);

        (new TelegramAvatarService($bot, new S3Storage($s3Client, 'test_'), $em, new NullLogger()))->refreshAvatar($user);

        self::assertNull($user->getPhotoUrl());
    }
}

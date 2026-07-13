<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\MeController;
use App\Entity\User;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\Core\Test\SimpleResultStream;
use AsyncAws\S3\Result\GetObjectOutput;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Юнит-тесты GET /api/v1/me. Контроллер снабжается пустым DI-контейнером (json()
 * из AbstractController уходит на json_encode-путь, has('serializer') === false).
 * S3Storage — final, поэтому строим настоящий поверх мока S3Client (паттерн репо).
 */
final class MeControllerTest extends TestCase
{
    private function controller(S3Client $s3Client): MeController
    {
        $controller = new MeController(new S3Storage($s3Client, 'test_'));
        $controller->setContainer(new Container());

        return $controller;
    }

    private function user(int $id): User
    {
        $user = new User();
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(JsonResponse $res): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testAnonymousGets401(): void
    {
        $res = $this->controller($this->createStub(S3Client::class))->me(null);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testGuestGets401(): void
    {
        $guest = (new User())->setIsGuest(true)->setGuestId('g-1');
        $res   = $this->controller($this->createStub(S3Client::class))->me($guest);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testReturnsFullProfileWithAvatarDataUri(): void
    {
        $user = $this->user(123);
        $user->setFirstName('Иван')->setUsername('ivan')->setPhotoUrl('avatars/123.jpg')->setIsAdmin(true);

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('getObject')->willReturn(
            ResultMockFactory::create(GetObjectOutput::class, ['Body' => new SimpleResultStream('IMG')]),
        );

        $res  = $this->controller($s3Client)->me($user);
        $data = $this->payload($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(123, $data['id']);
        self::assertSame('Иван', $data['name']);
        self::assertSame('ivan', $data['username']);
        self::assertSame('https://t.me/ivan', $data['profile_url']);
        self::assertSame('data:image/jpeg;base64,' . base64_encode('IMG'), $data['avatar_url']);
        self::assertSame('free', $data['plan']);
        self::assertTrue($data['is_admin']);
    }

    public function testProfileUrlNullWhenNoUsername(): void
    {
        $user = $this->user(5);
        $user->setFirstName('Пётр'); // username === null

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::never())->method('getObject'); // нет photoUrl → S3 не дёргается

        $res  = $this->controller($s3Client)->me($user);
        $data = $this->payload($res);

        self::assertNull($data['username']);
        self::assertNull($data['profile_url']);
        self::assertSame('Пётр', $data['name']);
    }

    public function testAvatarNullWhenNoPhoto(): void
    {
        $user = $this->user(9);
        $user->setUsername('u9'); // photoUrl === null

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::never())->method('getObject');

        $res  = $this->controller($s3Client)->me($user);
        $data = $this->payload($res);

        self::assertNull($data['avatar_url']);
        // name падает на username, раз firstName пуст.
        self::assertSame('u9', $data['name']);
    }

    public function testAvatarNullWhenS3ObjectMissing(): void
    {
        $user = $this->user(11);
        $user->setPhotoUrl('avatars/11.jpg');

        $s3Client = $this->createStub(S3Client::class);
        // Объекта нет / ошибка чтения — getObjectContents глотает и отдаёт null.
        $s3Client->method('getObject')->willThrowException(new \RuntimeException('NoSuchKey'));

        $res  = $this->controller($s3Client)->me($user);
        $data = $this->payload($res);

        self::assertNull($data['avatar_url']);
    }
}

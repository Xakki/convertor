<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * CNV-8: POST /convert/{id}/retry и DELETE /convert/{id}.
 * Owner-scope (404 чужая), ROLE_USER (403 гость по cookie), 410 если исходник gone.
 * S3 — MockHttpClient поверх реального S3Storage (класс final).
 */
final class ConversionRetryDeleteControllerTest extends WebTestCase
{
    /** @var list<object> */
    private array $toRemove = [];

    /** @var list<array{class: class-string, id: int}> */
    private array $toRemoveById = [];

    protected function tearDown(): void
    {
        $em = null;
        if ($this->toRemove !== [] || $this->toRemoveById !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
        }

        if ($this->toRemoveById !== []) {
            foreach (array_reverse($this->toRemoveById) as $ref) {
                $fresh = $em->find($ref['class'], $ref['id']);
                if ($fresh !== null) {
                    $em->remove($fresh);
                }
            }
            $em->flush();
        }

        if ($this->toRemove !== []) {
            foreach (array_reverse($this->toRemove) as $entity) {
                $managed = $em->contains($entity) ? $entity : $em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
        $this->toRemove     = [];
        $this->toRemoveById = [];
    }

    public function testRetryCreatesNewConversion(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);
        $conv   = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // HEAD (exists) + COPY — оба 200.
        $this->overrideS3Sequence([
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 200]),
        ]);

        $client->request(
            'POST',
            '/api/v1/convert/' . $conv->getId() . '/retry',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );

        self::assertResponseStatusCodeSame(202);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('conversion_id', $data);
        self::assertNotSame($conv->getId(), $data['conversion_id']);
        self::assertSame('pending', $data['status']);

        $newId = (int) $data['conversion_id'];
        $fresh = static::getContainer()->get(ConversionRepository::class)->find($newId);
        self::assertNotNull($fresh);
        self::assertSame($owner->getId(), $fresh->getUser()->getId());
        self::assertSame('jpg', $fresh->getFromFormat());
        self::assertSame('png', $fresh->getToFormat());
        self::assertNotSame(
            $conv->getInputFile()->getStoragePath(),
            $fresh->getInputFile()->getStoragePath(),
        );
        $this->toRemoveById[] = ['class' => FileStorage::class, 'id' => $fresh->getInputFile()->getId()];
        $this->toRemoveById[] = ['class' => Conversion::class, 'id' => $fresh->getId()];
    }

    public function testRetryReturns410WhenInputGone(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);
        $conv   = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // HEAD → 404 NoSuchKey
        $this->overrideS3(404, '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>no</Message></Error>');

        $client->request(
            'POST',
            '/api/v1/convert/' . $conv->getId() . '/retry',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );

        self::assertResponseStatusCodeSame(410);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('gone', $data['error'] ?? null);
    }

    public function testRetryReturns404ForNonOwner(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $other  = $this->persistUser();
        $token  = $this->jwtFor($other);
        $conv   = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request(
            'POST',
            '/api/v1/convert/' . $conv->getId() . '/retry',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testRetryForbiddenForGuestCookie(): void
    {
        $client = static::createClient();
        $guest  = $this->persistGuest();
        $conv   = $this->seedConversion($guest, 'jpg', 'png', ConversionStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $tokens = static::getContainer()->get(GuestTokenService::class);
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                GuestCookieFactory::NAME,
                $tokens->sign((string) $guest->getGuestId()),
            ),
        );

        $client->request('POST', '/api/v1/convert/' . $conv->getId() . '/retry');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteHardRemovesRow(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);
        $conv   = $this->seedConversion(
            $owner,
            'jpg',
            'png',
            ConversionStatus::Completed,
            withOutput: true,
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $convId   = $conv->getId();
        $inputId  = $conv->getInputFile()->getId();
        $outputId = $conv->getOutputFile()?->getId();

        // DELETE input + DELETE output
        $this->overrideS3Sequence([
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $client->request(
            'DELETE',
            '/api/v1/convert/' . $convId,
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );

        self::assertResponseStatusCodeSame(204);
        self::assertNull(static::getContainer()->get(ConversionRepository::class)->find($convId));
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->find(FileStorage::class, $inputId));
        if ($outputId !== null) {
            self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->find(FileStorage::class, $outputId));
        }

        // Строки уже удалены менеджером — не чистим их в tearDown.
        $this->toRemove     = [];
        $this->toRemoveById = [];
        $this->toRemove[]   = $owner;
    }

    public function testDeleteReturns404ForNonOwner(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $other  = $this->persistUser();
        $token  = $this->jwtFor($other);
        $conv   = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request(
            'DELETE',
            '/api/v1/convert/' . $conv->getId(),
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteForbiddenForGuestCookie(): void
    {
        $client = static::createClient();
        $guest  = $this->persistGuest();
        $conv   = $this->seedConversion($guest, 'jpg', 'png', ConversionStatus::Completed);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $tokens = static::getContainer()->get(GuestTokenService::class);
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                GuestCookieFactory::NAME,
                $tokens->sign((string) $guest->getGuestId()),
            ),
        );

        $client->request('DELETE', '/api/v1/convert/' . $conv->getId());

        self::assertResponseStatusCodeSame(403);
    }

    private function overrideS3(int $httpCode, string $body): void
    {
        $this->overrideS3Sequence([new MockResponse($body, ['http_code' => $httpCode])]);
    }

    /** @param list<MockResponse> $responses */
    private function overrideS3Sequence(array $responses): void
    {
        $http = new MockHttpClient($responses);
        $s3   = new S3Client([
            'endpoint'          => 'http://localhost',
            'accessKeyId'       => 'k',
            'accessKeySecret'   => 's',
            'region'            => 'us-east-1',
            'pathStyleEndpoint' => true,
        ], null, $http);

        static::getContainer()->set(S3Storage::class, new S3Storage($s3, 'test'));
    }

    private function persistUser(): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $em->persist($user);
        $em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function persistGuest(): User
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = static::getContainer()->get(GuestTokenService::class);
        $guest  = (new User())
            ->setIsGuest(true)
            ->setGuestId($tokens->generateGuestId());
        $em->persist($guest);
        $em->flush();
        $this->toRemove[] = $guest;

        return $guest;
    }

    private function seedConversion(
        User $owner,
        string $from,
        string $to,
        ConversionStatus $status,
        bool $withOutput = false,
    ): Conversion {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $inputFile = (new FileStorage())
            ->setOriginalName("photo.{$from}")
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.' . $from)
            ->setMimeType('image/jpeg')
            ->setSizeBytes(4096);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $outputFile = null;
        if ($withOutput) {
            $outputFile = (new FileStorage())
                ->setOriginalName("photo.{$to}")
                ->setStoragePath('results/test/' . bin2hex(random_bytes(8)) . '.' . $to)
                ->setMimeType('image/png')
                ->setSizeBytes(2048);
            $em->persist($outputFile);
            $this->toRemove[] = $outputFile;
        }

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setOutputFile($outputFile)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory(FileCategory::Image)
            ->setStatus($status)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($conv);
        $this->toRemove[] = $conv;

        return $conv;
    }

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}

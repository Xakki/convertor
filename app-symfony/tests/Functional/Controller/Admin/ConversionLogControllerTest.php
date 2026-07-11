<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты admin-эндпоинта лога конвертаций (эпик admin-panel,
 * подзадача logs). Граница — ROLE_ADMIN на JWT-firewall (Option B): не-админ
 * 403, unauth 401, админ 200 с пагинацией + graylogUrl. Фильтр «только ошибки»
 * скоупится посеянным владельцем. Требуют тест-БД convertor-test.
 */
final class ConversionLogControllerTest extends WebTestCase
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

    public function testForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/conversions', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/conversions');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testReturnsPaginationMetadataAndGraylogUrlForAdmin(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('GET', '/api/v1/admin/conversions', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (['items', 'page', 'pageSize', 'total', 'pages', 'graylogUrl'] as $key) {
            self::assertArrayHasKey($key, $data, $key);
        }
        self::assertIsArray($data['items']);
        self::assertSame(1, $data['page']);
    }

    public function testErrorsOnlyFilterReturnsFailedRowWithMessage(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null);
        $failed = $this->seedConversion($owner, 'mp3', 'txt', ConversionStatus::Failed, 'worker crashed');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request(
            'GET',
            '/api/v1/admin/conversions?status=failed&user=' . $owner->getId(),
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $data['total'], 'только failed-строка владельца');
        $row = $data['items'][0];
        self::assertSame($failed->getId(), $row['id']);
        self::assertSame('failed', $row['status']);
        self::assertSame('worker crashed', $row['errorMessage']);
        self::assertSame('mp3', $row['fromFormat']);
        self::assertSame('txt', $row['toFormat']);
        self::assertArrayHasKey('user', $row);
        self::assertSame($owner->getId(), $row['user']['id']);
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

    private function seedConversion(User $owner, string $from, string $to, ConversionStatus $status, ?string $err): Conversion
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $input = (new FileStorage())
            ->setOriginalName('in.' . $from)
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.' . $from)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(123);
        $em->persist($input);
        $this->toRemove[] = $input;

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory(FileCategory::Image)
            ->setStatus($status)
            ->setErrorMessage($err)
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

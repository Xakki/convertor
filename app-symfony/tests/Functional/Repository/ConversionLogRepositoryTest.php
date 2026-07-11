<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Поиск/фильтр/пагинация лога конвертаций (эпик admin-panel, подзадача logs)
 * против РЕАЛЬНОЙ тест-БД (convertor-test). Тест-БД общая → все строки сеются
 * под ВЫДЕЛЕННОГО свежего пользователя и фильтруются по нему, поэтому
 * абсолютный `total` глобальной таблицы не ассертится (устойчиво к чужим
 * строкам). Все посеянные сущности удаляются в конце.
 */
final class ConversionLogRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConversionRepository $conversions;

    /** @var list<object> */
    private array $toRemove = [];

    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container         = static::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->conversions = $container->get(ConversionRepository::class);

        // Выделенный владелец с уникальным email — вся выборка скоупится им.
        $this->owner = (new User())->setEmail('logs-' . bin2hex(random_bytes(6)) . '@example.test');
        $this->em->persist($this->owner);
        $this->toRemove[] = $this->owner;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->toRemove) as $entity) {
            if ($this->em->contains($entity)) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        $this->toRemove = [];

        parent::tearDown();
    }

    public function testFilterByUserScopesAndPaginates(): void
    {
        $this->seed('jpg', 'png', false, false, ConversionStatus::Completed, 100, null, new \DateTimeImmutable('today 10:00'));
        $this->seed('jpg', 'png', false, false, ConversionStatus::Completed, 200, null, new \DateTimeImmutable('today 11:00'));
        $this->seed('pdf', 'txt', false, true, ConversionStatus::Completed, 300, null, new \DateTimeImmutable('today 12:00'));
        $this->em->flush();

        $uid = (string) $this->owner->getId();

        // Весь набор владельца.
        $all = $this->conversions->searchPaginated(['user' => $uid], 25, 0);
        self::assertSame(3, $all['total']);
        self::assertCount(3, $all['items']);
        // Свежие сверху (createdAt DESC).
        self::assertSame('pdf', $all['items'][0]->getFromFormat());

        // Пагинация: страница 2 по 2 на страницу → 1 остаток.
        $page2 = $this->conversions->searchPaginated(['user' => $uid], 2, 2);
        self::assertSame(3, $page2['total']);
        self::assertCount(1, $page2['items']);

        // По email (LIKE) — тот же набор.
        $byEmail = $this->conversions->searchPaginated(['user' => (string) $this->owner->getEmail()], 25, 0);
        self::assertSame(3, $byEmail['total']);
    }

    public function testErrorsOnlyReturnsFailedWithMessage(): void
    {
        $this->seed('jpg', 'png', false, false, ConversionStatus::Completed, 100, null, new \DateTimeImmutable('today 10:00'));
        $this->seed('mp3', 'txt', true, false, ConversionStatus::Failed, null, 'worker OOM', new \DateTimeImmutable('today 11:00'));
        $this->em->flush();

        $res = $this->conversions->searchPaginated([
            'user'   => (string) $this->owner->getId(),
            'status' => ConversionStatus::Failed,
        ], 25, 0);

        self::assertSame(1, $res['total']);
        self::assertSame(ConversionStatus::Failed, $res['items'][0]->getStatus());
        self::assertSame('worker OOM', $res['items'][0]->getErrorMessage());
    }

    public function testFormatAndCategoryFilters(): void
    {
        $this->seed('jpg', 'png', false, false, ConversionStatus::Completed, 100, null, new \DateTimeImmutable('today 10:00'));
        $this->seed('pdf', 'txt', false, true, ConversionStatus::Completed, 200, null, new \DateTimeImmutable('today 11:00'));
        $this->em->flush();

        $uid = (string) $this->owner->getId();

        $byFrom = $this->conversions->searchPaginated(['user' => $uid, 'fromFormat' => 'pdf'], 25, 0);
        self::assertSame(1, $byFrom['total']);
        self::assertSame('pdf', $byFrom['items'][0]->getFromFormat());

        $byOcr = $this->conversions->searchPaginated(['user' => $uid, 'isOcr' => true], 25, 0);
        self::assertSame(1, $byOcr['total']);
        self::assertTrue($byOcr['items'][0]->isOcr());
    }

    public function testDateRangeIsInclusiveOfBothEnds(): void
    {
        $threeDaysAgo = (new \DateTimeImmutable('today'))->modify('-3 days');
        $this->seed('jpg', 'png', false, false, ConversionStatus::Completed, 100, null, $threeDaysAgo->setTime(9, 0));
        $this->seed('jpg', 'png', false, false, ConversionStatus::Completed, 200, null, new \DateTimeImmutable('today 12:00'));
        $this->em->flush();

        $uid = (string) $this->owner->getId();

        // from=сегодня → только сегодняшняя.
        $fromToday = $this->conversions->searchPaginated([
            'user' => $uid,
            'from' => new \DateTimeImmutable('today 00:00:00'),
        ], 25, 0);
        self::assertSame(1, $fromToday['total']);

        // to=3 дня назад (инклюзивный день) → включает строку 09:00 того дня.
        $toThatDay = $this->conversions->searchPaginated([
            'user' => $uid,
            'to'   => $threeDaysAgo->setTime(0, 0),
        ], 25, 0);
        self::assertSame(1, $toThatDay['total']);
        self::assertSame(
            $threeDaysAgo->format('Y-m-d'),
            $toThatDay['items'][0]->getCreatedAt()->format('Y-m-d'),
        );
    }

    private function seed(
        string $from,
        string $to,
        bool $isAi,
        bool $isOcr,
        ConversionStatus $status,
        ?int $processingMs,
        ?string $errorMessage,
        \DateTimeImmutable $createdAt,
    ): Conversion {
        $input = (new FileStorage())
            ->setOriginalName('in.' . $from)
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.' . $from)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(123);
        $this->em->persist($input);
        $this->toRemove[] = $input;

        $conv = (new Conversion())
            ->setUser($this->owner)
            ->setInputFile($input)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory(FileCategory::Image)
            ->setStatus($status)
            ->setProcessingMs($processingMs)
            ->setErrorMessage($errorMessage)
            ->setIsAi($isAi)
            ->setIsOcr($isOcr);
        // createdAt задаётся в конструкторе (сеттера нет) — правим reflection'ом,
        // чтобы разложить строки по конкретным дням для date-range теста.
        $ref = new \ReflectionProperty(Conversion::class, 'createdAt');
        $ref->setValue($conv, $createdAt);

        $this->em->persist($conv);
        $this->toRemove[] = $conv;

        return $conv;
    }
}

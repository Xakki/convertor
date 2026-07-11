<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Агрегаты admin-панели (эпик admin-panel, подзадача stats) против РЕАЛЬНОЙ
 * тест-БД (convertor-test). Счётчики глобальны по таблице, поэтому тест
 * ассертит ДЕЛЬТЫ относительно снапшота до сева — устойчиво к строкам от
 * других тестов в общей тест-БД. Все посеянные строки удаляются в конце.
 */
final class AdminStatsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConversionRepository $conversions;
    private UserRepository $users;

    /** @var list<object> */
    private array $toRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container         = static::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->conversions = $container->get(ConversionRepository::class);
        $this->users       = $container->get(UserRepository::class);
    }

    protected function tearDown(): void
    {
        // EM не очищается в тестах, поэтому посеянные сущности остаются managed.
        foreach (array_reverse($this->toRemove) as $entity) {
            if ($this->em->contains($entity)) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        $this->toRemove = [];

        parent::tearDown();
    }

    public function testConversionAggregatesReflectSeededDeltas(): void
    {
        // Снапшот до сева.
        $baseTotal  = $this->conversions->countTotal();
        $baseAi     = $this->conversions->countByAi();
        $baseStatus = $this->conversions->countByStatus();
        $baseSeries = $this->indexSeries($this->conversions->seriesByDay(7));

        $today     = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $threeDays = (new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d');

        $owner = $this->persistUser(false, false);

        // Сегодня: 2 completed regular + 1 failed regular + 1 completed AI.
        $this->seedConversion($owner, 'jpg', 'png', false, ConversionStatus::Completed, 100, new \DateTimeImmutable('today 10:00'));
        $this->seedConversion($owner, 'jpg', 'png', false, ConversionStatus::Completed, 200, new \DateTimeImmutable('today 11:00'));
        $this->seedConversion($owner, 'jpg', 'png', false, ConversionStatus::Failed, null, new \DateTimeImmutable('today 12:00'));
        $this->seedConversion($owner, 'mp3', 'txt', true, ConversionStatus::Completed, 300, new \DateTimeImmutable('today 13:00'));
        // 3 дня назад: 1 completed regular jpg→png.
        $this->seedConversion($owner, 'jpg', 'png', false, ConversionStatus::Completed, 150, new \DateTimeImmutable($threeDays . ' 09:00'));
        $this->em->flush();

        // countTotal: +5.
        self::assertSame($baseTotal + 5, $this->conversions->countTotal());

        // AI vs обычные: +1 AI, +4 обычных.
        $ai = $this->conversions->countByAi();
        self::assertSame(($baseAi['ai'] ?? 0) + 1, $ai['ai']);
        self::assertSame(($baseAi['regular'] ?? 0) + 4, $ai['regular']);

        // По статусам: +4 completed, +1 failed.
        $status = $this->conversions->countByStatus();
        self::assertSame(($baseStatus['completed'] ?? 0) + 4, $status['completed'] ?? 0);
        self::assertSame(($baseStatus['failed'] ?? 0) + 1, $status['failed'] ?? 0);

        // Ряд по дням: 7 меток, последняя — сегодня; дельты по bucket'ам.
        $series = $this->conversions->seriesByDay(7);
        self::assertCount(7, $series['labels']);
        self::assertSame($today, $series['labels'][6]);
        $idx = $this->indexSeries($series);
        self::assertSame(($baseSeries[$today]['regular'] ?? 0) + 3, $idx[$today]['regular']);
        self::assertSame(($baseSeries[$today]['ai'] ?? 0) + 1, $idx[$today]['ai']);
        self::assertSame(($baseSeries[$threeDays]['regular'] ?? 0) + 1, $idx[$threeDays]['regular']);

        // Топ форматов: jpg→png присутствует с count >= 3 (посеяли 3).
        $pair = null;
        foreach ($this->conversions->topFormatPairs(50) as $row) {
            if ($row['from'] === 'jpg' && $row['to'] === 'png') {
                $pair = $row;
                break;
            }
        }
        self::assertNotNull($pair, 'пара jpg→png есть в топе');
        self::assertGreaterThanOrEqual(3, $pair['count']);

        // avg и error-rate: заполнены и в допустимых границах.
        self::assertNotNull($this->conversions->avgProcessingMs());
        $rate = $this->conversions->errorRate();
        self::assertGreaterThan(0.0, $rate);
        self::assertLessThanOrEqual(1.0, $rate);
    }

    public function testUserAggregatesReflectSeededDeltas(): void
    {
        $baseAll     = $this->users->countAll();
        $baseActive  = $this->users->countActive();
        $baseGuests  = $this->users->countGuests();
        $baseSignups = $this->indexCounts($this->users->signupsByDay(7));
        $today       = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->persistUser(false, false); // активный реальный
        $this->persistUser(true, false);  // гость
        $this->em->flush();

        self::assertSame($baseAll + 2, $this->users->countAll());
        self::assertSame($baseActive + 1, $this->users->countActive());
        self::assertSame($baseGuests + 1, $this->users->countGuests());

        // signupsByDay считает только не-гостей → +1 сегодня.
        $signups = $this->users->signupsByDay(7);
        self::assertCount(7, $signups['labels']);
        $idx = $this->indexCounts($signups);
        self::assertSame(($baseSignups[$today] ?? 0) + 1, $idx[$today]);
    }

    /** avg/error-rate на пустом окне не должны падать (guard-и в репозитории). */
    public function testEmptyAggregatesDoNotCrash(): void
    {
        // Не сеем ничего — вызовы просто отрабатывают на текущем (возможно пустом)
        // наборе. avg может быть null, error-rate — валидная доля.
        $rate = $this->conversions->errorRate();
        self::assertGreaterThanOrEqual(0.0, $rate);
        self::assertLessThanOrEqual(1.0, $rate);
        $avg = $this->conversions->avgProcessingMs();
        self::assertTrue($avg === null || $avg >= 0);
    }

    private function persistUser(bool $guest, bool $inactive): User
    {
        $user = new User();
        if ($guest) {
            $user->setIsGuest(true)->setGuestId('stats-' . bin2hex(random_bytes(8)));
        }
        if ($inactive) {
            $user->setIsActive(false);
        }
        $this->em->persist($user);
        $this->toRemove[] = $user;

        return $user;
    }

    private function seedConversion(
        User $owner,
        string $from,
        string $to,
        bool $isAi,
        ConversionStatus $status,
        ?int $processingMs,
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
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory(FileCategory::Image)
            ->setStatus($status)
            ->setProcessingMs($processingMs)
            ->setIsAi($isAi)
            ->setIsOcr(false);
        // createdAt проставляется в конструкторе (сеттера нет) — задаём через
        // reflection, чтобы разложить конвертации по конкретным дням.
        $ref = new \ReflectionProperty(Conversion::class, 'createdAt');
        $ref->setValue($conv, $createdAt);

        $this->em->persist($conv);
        $this->toRemove[] = $conv;

        return $conv;
    }

    /**
     * @param array{labels: list<string>, regular: list<int>, ai: list<int>} $series
     *
     * @return array<string, array{regular: int, ai: int}>
     */
    private function indexSeries(array $series): array
    {
        $out = [];
        foreach ($series['labels'] as $i => $label) {
            $out[$label] = ['regular' => $series['regular'][$i], 'ai' => $series['ai'][$i]];
        }

        return $out;
    }

    /**
     * @param array{labels: list<string>, counts: list<int>} $series
     *
     * @return array<string, int>
     */
    private function indexCounts(array $series): array
    {
        $out = [];
        foreach ($series['labels'] as $i => $label) {
            $out[$label] = $series['counts'][$i];
        }

        return $out;
    }
}

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
 * DB-сигнал зависших задач (эпик admin-panel, подзадача queues) против реальной
 * тест-БД (convertor-test). findStuck/countStuck отбирают Pending/Processing с
 * updatedAt старше порога; терминальные и свежие строки исключены. Счётчики
 * глобальны → ассертим ДЕЛЬТЫ к снапшоту, все посеянные строки удаляются.
 */
final class StuckConversionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConversionRepository $conversions;

    /** @var list<object> */
    private array $toRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container         = static::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->conversions = $container->get(ConversionRepository::class);
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

    public function testFindStuckSelectsOnlyOldPendingOrProcessing(): void
    {
        $threshold = (new \DateTimeImmutable())->modify('-15 minutes');
        $baseCount = $this->conversions->countStuck($threshold);

        $owner = $this->persistUser();

        // Застрявшие (updatedAt старше порога, статус не терминальный).
        $stuckPending    = $this->seed($owner, ConversionStatus::Pending, '-30 minutes');
        $stuckProcessing = $this->seed($owner, ConversionStatus::Processing, '-20 minutes');
        // НЕ застрявшие: свежий Pending + старый, но завершённый (терминальный).
        $freshPending = $this->seed($owner, ConversionStatus::Pending, '-1 minutes');
        $oldCompleted = $this->seed($owner, ConversionStatus::Completed, '-60 minutes');
        $this->em->flush();

        // Дельта счётчика: ровно +2.
        self::assertSame($baseCount + 2, $this->conversions->countStuck($threshold));

        $ids = array_map(static fn (Conversion $c): int => $c->getId(), $this->conversions->findStuck($threshold, 100));
        self::assertContains($stuckPending->getId(), $ids);
        self::assertContains($stuckProcessing->getId(), $ids);
        self::assertNotContains($freshPending->getId(), $ids);
        self::assertNotContains($oldCompleted->getId(), $ids);
    }

    private function persistUser(): User
    {
        $user = new User();
        $this->em->persist($user);
        $this->toRemove[] = $user;

        return $user;
    }

    private function seed(User $owner, ConversionStatus $status, string $updatedAgo): Conversion
    {
        $input = (new FileStorage())
            ->setOriginalName('in.pdf')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.pdf')
            ->setMimeType('application/pdf')
            ->setSizeBytes(123);
        $this->em->persist($input);
        $this->toRemove[] = $input;

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat('pdf')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Document)
            ->setStatus($status)
            ->setIsAi(false)
            ->setIsOcr(false);

        // updatedAt проставляется в конструкторе; PreUpdate не срабатывает на
        // INSERT → бэкдейтим через reflection, чтобы получить «зависшую» строку.
        $when = new \DateTimeImmutable($updatedAgo);
        (new \ReflectionProperty(Conversion::class, 'updatedAt'))->setValue($conv, $when);
        (new \ReflectionProperty(Conversion::class, 'createdAt'))->setValue($conv, $when);

        $this->em->persist($conv);
        $this->toRemove[] = $conv;

        return $conv;
    }
}

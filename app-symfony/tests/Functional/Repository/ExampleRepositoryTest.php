<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Example;
use App\Repository\ExampleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Функциональные тесты репозитория примеров (карточка admin-managed-examples):
 * порядок витрины ({@see ExampleRepository::findAllOrdered()}) и whitelist-поиск
 * по (category, filename)/(category, sourceFilename). Требует тест-БД
 * convertor-test.
 */
final class ExampleRepositoryTest extends KernelTestCase
{
    /** @var list<Example> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $example) {
                $managed = $em->contains($example) ? $example : $em->find(Example::class, $example->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
        $this->toRemove = [];
    }

    public function testFindAllOrderedSortsBySortOrderThenId(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ExampleRepository::class);

        $c = $this->persist('video', 'mp4', 'webm', 2);
        $a = $this->persist('document', 'txt', 'pdf', 0);
        $b = $this->persist('image', 'png', 'jpg', 0); // тот же sortOrder, что и $a → tie-break по id (создан позже)

        $ordered = $repo->findAllOrdered();
        $ids     = array_map(static fn (Example $e): int => $e->getId(), $ordered);

        self::assertSame([$a->getId(), $b->getId(), $c->getId()], $ids);
    }

    public function testFindOneByCategoryAndFilenameIsWhitelistScoped(): void
    {
        self::bootKernel();
        $repo    = static::getContainer()->get(ExampleRepository::class);
        $example = $this->persist('document', 'txt', 'pdf', 0);

        $found = $repo->findOneByCategoryAndFilename('document', $example->getFilename());
        self::assertNotNull($found);
        self::assertSame($example->getId(), $found->getId());

        self::assertNull($repo->findOneByCategoryAndFilename('image', $example->getFilename()), 'категория должна совпадать');
        self::assertNull($repo->findOneByCategoryAndFilename('document', 'bogus.pdf'));
    }

    public function testFindOneByCategoryAndSourceFilenameIsWhitelistScoped(): void
    {
        self::bootKernel();
        $repo    = static::getContainer()->get(ExampleRepository::class);
        $example = $this->persist('document', 'txt', 'pdf', 0);

        $found = $repo->findOneByCategoryAndSourceFilename('document', $example->getSourceFilename());
        self::assertNotNull($found);
        self::assertSame($example->getId(), $found->getId());

        self::assertNull($repo->findOneByCategoryAndSourceFilename('image', $example->getSourceFilename()));
    }

    public function testFindOneByResultKeyAndNextSortOrder(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ExampleRepository::class);

        self::assertSame(0, $repo->nextSortOrder(), 'на пустой таблице MAX(sortOrder) = null → старт с 0');

        $example = $this->persist('document', 'txt', 'pdf', 7);

        self::assertSame(8, $repo->nextSortOrder());
        self::assertNotNull($repo->findOneByResultKey($example->getResultKey()));
        self::assertNull($repo->findOneByResultKey('examples/document/bogus.pdf'));
    }

    private function persist(string $category, string $from, string $to, int $sortOrder): Example
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $example = (new Example())
            ->setCategory($category)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setFilename($from . '-to-' . $to . '.' . $to)
            ->setMime('application/octet-stream')
            ->setSize(1)
            ->setPreviewable(false)
            ->setSourceFormat($from)
            ->setSourceMime('application/octet-stream')
            ->setSourceFilename($category . '.' . $from)
            ->setResultKey('examples/' . $category . '/' . $from . '-to-' . $to . '.' . $to)
            ->setSourceKey('examples/' . $category . '/' . $from . '-to-' . $to . '-source.' . $from)
            ->setSortOrder($sortOrder);

        $em->persist($example);
        $em->flush();
        $this->toRemove[] = $example;

        return $example;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Пессимистичная блокировка Conversion для admin DLQ-requeue (CNV-11).
 * Проверяем, что findOneByIdForUpdate реально держит InnoDB-лок: вторая
 * сессия с FOR UPDATE упирается в lock wait timeout, пока первая не
 * закоммитит.
 */
final class ConversionForUpdateRepositoryTest extends KernelTestCase
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
            $managed = $this->em->contains($entity)
                ? $entity
                : $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        if ($this->toRemove !== []) {
            $this->em->flush();
        }

        parent::tearDown();
        $this->toRemove = [];
    }

    public function testFindOneByIdForUpdateHoldsRowLockAgainstSecondSession(): void
    {
        $conversion = $this->persistFailedConversion();
        $id         = (int) $conversion->getId();

        $this->em->wrapInTransaction(function () use ($id): void {
            $locked = $this->conversions->findOneByIdForUpdate($id);
            self::assertNotNull($locked);
            self::assertSame(ConversionStatus::Failed, $locked->getStatus());

            $params = $this->em->getConnection()->getParams();
            $conn2  = DriverManager::getConnection($params);
            $conn2->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            $conn2->beginTransaction();

            try {
                $conn2->executeQuery('SELECT id FROM conversions WHERE id = ? FOR UPDATE', [$id]);
                self::fail('Вторая сессия должна ждать FOR UPDATE-лок первой транзакции');
            } catch (DriverException $e) {
                self::assertTrue(
                    str_contains(strtolower($e->getMessage()), 'lock wait timeout')
                    || $e->getCode() === 1205
                    || ($e->getPrevious() !== null && str_contains(strtolower($e->getPrevious()->getMessage()), 'lock wait timeout')),
                    'Ожидали InnoDB lock wait timeout, получили: ' . $e->getMessage(),
                );
            } finally {
                if ($conn2->isTransactionActive()) {
                    $conn2->rollBack();
                }
                $conn2->close();
            }
        });

        // После коммита первой транзакции лок снят — повторный FOR UPDATE проходит.
        $this->em->wrapInTransaction(function () use ($id): void {
            $again = $this->conversions->findOneByIdForUpdate($id);
            self::assertNotNull($again);
            self::assertSame($id, $again->getId());
        });
    }

    private function persistFailedConversion(): Conversion
    {
        $owner = new User();
        $this->em->persist($owner);
        $this->em->flush();
        $this->toRemove[] = $owner;

        $inputFile = (new FileStorage())
            ->setOriginalName('audio.mp3')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.mp3')
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $this->em->persist($inputFile);
        $this->em->flush();
        $this->toRemove[] = $inputFile;

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setFromFormat('mp3')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Audio)
            ->setStatus(ConversionStatus::Failed)
            ->setErrorMessage('boom')
            ->setIsAi(false)
            ->setIsOcr(false);
        $this->em->persist($conversion);
        $this->em->flush();
        $this->toRemove[] = $conversion;

        return $conversion;
    }
}

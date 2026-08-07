<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Queue;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Service\Queue\ConversionResultPersister;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CNV-71-03 review fix — concurrency of the terminal-status guard in
 * {@see ConversionResultPersister} (see the class docblock "CONCURRENCY").
 * `/result`, `/fail`/`dlq-fail` and `/expire` are three independent
 * gateway-driven callers that can race for the same conversionId; the guard
 * is only race-safe because persist() now evaluates it AFTER taking a
 * `SELECT … FOR UPDATE` lock on the row, inside the same transaction as the
 * write.
 *
 * Two tests, each proving a DIFFERENT half of the claim:
 *
 * 1. {@see testLockedFindHoldsRowLockAgainstSecondSession()} proves the lock
 *    ITSELF is genuinely held at the DB level — same technique as
 *    {@see \App\Tests\Functional\Repository\ConversionForUpdateRepositoryTest},
 *    but against the exact primitive persist() now uses
 *    (`$em->find(Conversion::class, $id, LockMode::PESSIMISTIC_WRITE)`, not
 *    the repository helper).
 * 2. {@see testSecondSequentialPersistCallDoesNotDoubleRefundPrepaidBalance()}
 *    proves the GUARD holds: two persist() calls for the same conversionId
 *    (as if /expire and a redelivered /fail both arrived for the same job)
 *    refund the prepaid balance exactly once.
 *
 * HONEST LIMIT: PHPUnit is single-threaded. Test #2 calls persist() twice
 * SEQUENTIALLY, not from two real concurrent processes — it proves the guard
 * is correct once the second caller reaches the lock, not that two processes
 * can genuinely interleave inside this test. Test #1 is what proves the lock
 * itself would force that real interleaving to serialize in production
 * (PHP-FPM/gateway are multi-process): a second real caller blocks on the
 * `FOR UPDATE` until the first commits, then observes the same already-
 * terminal row that test #2 exercises sequentially here. Together the two
 * tests cover what this test seam can prove; true parallel interleaving is
 * not (and cannot be) exercised in-process.
 */
final class ConversionResultPersisterConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<object> */
    private array $toRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        // balance_transactions has a plain (non-cascading) FK to users — clean
        // any ledger rows the refund in test #2 wrote BEFORE removing the user,
        // or the user removal below would fail on the FK constraint.
        foreach ($this->toRemove as $entity) {
            if ($entity instanceof User && $entity->getId() !== null) {
                $this->em->getConnection()->executeStatement(
                    'DELETE FROM balance_transactions WHERE user_id = :id',
                    ['id' => $entity->getId()],
                );
            }
        }

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

    public function testLockedFindHoldsRowLockAgainstSecondSession(): void
    {
        $conversion = $this->persistProcessingConversion();
        $id         = (int) $conversion->getId();

        $this->em->wrapInTransaction(function () use ($id): void {
            $locked = $this->em->find(Conversion::class, $id, LockMode::PESSIMISTIC_WRITE);
            self::assertNotNull($locked);
            self::assertSame(ConversionStatus::Processing, $locked->getStatus());

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

        // После коммита лок снят — повторный локированный find проходит.
        $this->em->wrapInTransaction(function () use ($id): void {
            $again = $this->em->find(Conversion::class, $id, LockMode::PESSIMISTIC_WRITE);
            self::assertNotNull($again);
            self::assertSame($id, $again->getId());
        });
    }

    public function testSecondSequentialPersistCallDoesNotDoubleRefundPrepaidBalance(): void
    {
        $persister = static::getContainer()->get(ConversionResultPersister::class);

        $conversion = $this->persistProcessingConversion(BillingMode::PrepaidBalance);
        $userId     = (int) $conversion->getUser()->getId();

        $before = $this->readBalanceCents($userId);

        // Первый вызов — как если бы дошёл /expire (gateway expiry-sweep,
        // WORKER_CLAIM_TIMEOUT_MINUTES). Терминализует и возвращает баланс.
        $persister->persist([
            'conversionId' => $conversion->getId(),
            'state'        => 'expired',
        ]);

        $afterFirst = $this->readBalanceCents($userId);
        self::assertGreaterThan($before, $afterFirst, 'первый вызов обязан вернуть prepaid-баланс');

        // Второй вызов для ТОЙ ЖЕ conversionId — как если бы следом (или
        // передоставленной копией) доехал /fail для уже обработанной задачи.
        // Guard видит терминальный статус под локом и не делает второй refund.
        $persister->persist([
            'conversionId' => $conversion->getId(),
            'state'        => 'failed',
            'error'        => 'duplicate delivery',
        ]);

        $afterSecond = $this->readBalanceCents($userId);
        self::assertSame(
            $afterFirst,
            $afterSecond,
            'второй вызов НЕ должен повторно вернуть баланс — guard обязан no-op-нуть',
        );

        $this->em->clear();
        $reloaded = $this->em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            ConversionStatus::Expired,
            $reloaded->getStatus(),
            'первый (expired) переход должен остаться, второй (failed) — no-op',
        );
    }

    private function readBalanceCents(int $userId): int
    {
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $userId);
        self::assertNotNull($reloaded);

        return $reloaded->getBalanceCents();
    }

    private function persistProcessingConversion(BillingMode $billingMode = BillingMode::PlanQuota): Conversion
    {
        $owner = new User();
        $owner->setBalanceCents(1000);
        $this->em->persist($owner);
        $this->em->flush();
        $this->toRemove[] = $owner;

        $inputFile = (new FileStorage())
            ->setOriginalName('audio.mp3')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.mp3')
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $this->em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setFromFormat('mp3')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Audio)
            ->setStatus(ConversionStatus::Processing)
            ->setBillingMode($billingMode)
            ->setIsAi(false)
            ->setIsOcr(false);
        $this->em->persist($conversion);
        $this->em->flush();
        $this->toRemove[] = $conversion;

        return $conversion;
    }
}

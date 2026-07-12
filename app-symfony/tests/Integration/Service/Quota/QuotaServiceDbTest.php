<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Quota;

use App\Entity\User;
use App\Repository\PlanRepository;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * charge()/refund() против РЕАЛЬНОЙ БД (convertor-test): raw-UPDATE
 * (`= col+1` / `= GREATEST(0, col-1)`) + `em->refresh()`. Юнит-тест мокает
 * Connection и проверяет лишь выбор SQL — здесь проверяем фактический эффект:
 *  - счётчик в строке БД реально меняется на нужной колонке;
 *  - клемп на 0 (`GREATEST`) отрабатывает на уровне БД;
 *  - refresh() синхронизирует in-memory User со строкой БД (главное в фиче B).
 *
 * charge/refund не зовут planRepo/logger/appEnv, поэтому собираем сервис из
 * реального EM + stub-репозитория + NullLogger + appEnv='prod'.
 */
#[Group('integration')]
final class QuotaServiceDbTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private QuotaService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em       = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $this->service = new QuotaService(
            $em,
            $this->createStub(PlanRepository::class),
            new NullLogger(),
            'prod',
        );
    }

    public function testChargeIncrementsRegularCounterAndSyncsInMemory(): void
    {
        $user = $this->persistUser(dailyConversions: 0, dailyAi: 0);

        $this->service->charge($user, false);

        // refresh() синхронизировал in-memory снимок.
        self::assertSame(1, $user->getDailyConversions(), 'in-memory синхронизирован');
        self::assertSame(0, $user->getDailyAiConversions());
        // И строка в БД реально инкрементирована.
        self::assertSame([1, 0], $this->dbCounters($user->getId()));

        $this->removeUser($user);
    }

    public function testChargeIncrementsAiCounterOnly(): void
    {
        $user = $this->persistUser(dailyConversions: 0, dailyAi: 0);

        $this->service->charge($user, true);

        self::assertSame(0, $user->getDailyConversions());
        self::assertSame(1, $user->getDailyAiConversions());
        self::assertSame([0, 1], $this->dbCounters($user->getId()));

        $this->removeUser($user);
    }

    public function testRefundDecrementsRegularCounter(): void
    {
        $user = $this->persistUser(dailyConversions: 3, dailyAi: 2);

        $this->service->refund($user, false);

        self::assertSame(2, $user->getDailyConversions());
        self::assertSame(2, $user->getDailyAiConversions());
        self::assertSame([2, 2], $this->dbCounters($user->getId()));

        $this->removeUser($user);
    }

    public function testRefundClampsAtZeroInDb(): void
    {
        // Счётчики уже на 0 → GREATEST(0, 0-1)=0, refund — no-op на обеих колонках.
        $user = $this->persistUser(dailyConversions: 0, dailyAi: 0);

        $this->service->refund($user, false);
        $this->service->refund($user, true);

        self::assertSame(0, $user->getDailyConversions());
        self::assertSame(0, $user->getDailyAiConversions());
        self::assertSame([0, 0], $this->dbCounters($user->getId()));

        $this->removeUser($user);
    }

    private function persistUser(int $dailyConversions, int $dailyAi): User
    {
        $user = (new User())
            ->setGuestId('itest-quota-' . bin2hex(random_bytes(8)))
            ->setIsGuest(true)
            ->setDailyConversions($dailyConversions)
            ->setDailyAiConversions($dailyAi);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Читает счётчики строки прямо из БД (в обход identity-map), чтобы отделить
     * реальный эффект UPDATE от in-memory состояния.
     *
     * @return array{0: int, 1: int} [daily_conversions, daily_ai_conversions]
     */
    private function dbCounters(?int $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT daily_conversions, daily_ai_conversions FROM users WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($row);

        return [(int) $row['daily_conversions'], (int) $row['daily_ai_conversions']];
    }

    private function removeUser(User $user): void
    {
        $managed = $this->em->getRepository(User::class)->find($user->getId());
        if ($managed !== null) {
            $this->em->remove($managed);
            $this->em->flush();
        }
    }
}

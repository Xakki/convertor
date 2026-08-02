<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Quota;

use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\FileCategory;
use App\Exception\InsufficientBalanceException;
use App\Repository\PlanRepository;
use App\Service\Billing\BalanceService;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * charge()/refund() против реальной БД: raw-UPDATE + refresh() и prepaid debit/refund.
 */
#[Group('integration')]
final class QuotaServiceDbTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private QuotaService $service;
    private BalanceService $balanceService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em       = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var BalanceService $balanceService */
        $balanceService       = $container->get(BalanceService::class);
        $this->balanceService = $balanceService;

        $this->service = new QuotaService(
            $em,
            $this->createStub(PlanRepository::class),
            $balanceService,
            new NullLogger(),
            'prod',
        );
    }

    public function testChargeIncrementsLightTierAndSyncsInMemory(): void
    {
        $user = $this->persistUser();

        $this->service->charge($user, FileCategory::Document, false, BillingMode::PlanQuota);

        self::assertSame(1, $user->getLightDailyConversions());
        self::assertSame(1, $user->getLightMonthlyConversions());
        self::assertSame([1, 1], $this->dbLightCounters($user->getId()));

        $this->removeUser($user);
    }

    public function testChargeIncrementsAiTierOnly(): void
    {
        $user = $this->persistUser();

        $this->service->charge($user, FileCategory::Audio, true, BillingMode::PlanQuota);

        self::assertSame(1, $user->getAiDailyConversions());
        self::assertSame(1, $user->getAiMonthlyConversions());
        self::assertSame(0, $user->getMediumDailyConversions());

        $this->removeUser($user);
    }

    public function testRefundDecrementsMediumTier(): void
    {
        $user = $this->persistUser();
        $user->setMediumDailyConversions(3)->setMediumMonthlyConversions(2);
        $this->em->flush();

        $this->service->refund($user, FileCategory::Audio, false, BillingMode::PlanQuota);

        self::assertSame(2, $user->getMediumDailyConversions());
        self::assertSame(1, $user->getMediumMonthlyConversions());

        $this->removeUser($user);
    }

    public function testRefundClampsAtZeroInDb(): void
    {
        $user = $this->persistUser();

        $this->service->refund($user, FileCategory::Document, false, BillingMode::PlanQuota);

        self::assertSame(0, $user->getLightDailyConversions());
        self::assertSame(0, $user->getLightMonthlyConversions());

        $this->removeUser($user);
    }

    public function testCheckThrowsInsufficientBalanceAtDailyLimitForRegisteredUser(): void
    {
        $user = $this->persistUser();
        $user->setLightDailyConversions(3);
        $this->em->flush();

        try {
            $this->service->check($user, FileCategory::Document, false);
            self::fail('expected insufficient balance');
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->removeUser($user);
    }

    public function testCheckThrows429AtDailyLimitForGuest(): void
    {
        $user = $this->persistGuest();
        $user->setLightDailyConversions(3);
        $this->em->flush();

        try {
            $this->service->check($user, FileCategory::Document, false);
            self::fail('expected daily quota 429');
        } catch (TooManyRequestsHttpException $e) {
            self::assertStringContainsString('Daily light', $e->getMessage());
        }

        $this->removeUser($user);
    }

    public function testCheckReturnsPrepaidWhenOverQuotaWithBalance(): void
    {
        $user = $this->persistUser();
        $user->setLightDailyConversions(3)->setBalanceCents(100);
        $this->em->flush();

        $mode = $this->service->check($user, FileCategory::Document, false);

        self::assertSame(BillingMode::PrepaidBalance, $mode);

        $this->removeUser($user);
    }

    public function testPrepaidChargeDebitsBalanceWithoutTierCounters(): void
    {
        $user = $this->persistUser();
        $user->setLightDailyConversions(3)->setBalanceCents(100);
        $this->em->flush();

        $this->service->charge($user, FileCategory::Document, false, BillingMode::PrepaidBalance, 42);

        self::assertSame(95, $user->getBalanceCents());
        self::assertSame([3, 0], $this->dbLightCounters($user->getId()));

        $this->removeUser($user);
    }

    public function testPrepaidRefundCreditsBalance(): void
    {
        $user = $this->persistUser();
        $user->setBalanceCents(90);
        $this->em->flush();

        $this->service->refund($user, FileCategory::Document, false, BillingMode::PrepaidBalance, 42);

        self::assertSame(95, $user->getBalanceCents());

        $this->removeUser($user);
    }

    public function testChargeIncrementsBothWindowsAtomicallyInDb(): void
    {
        $user = $this->persistUser();

        $this->service->charge($user, FileCategory::Image, false, BillingMode::PlanQuota);

        self::assertSame([1, 1], $this->dbMediumCounters($user->getId()));

        $this->removeUser($user);
    }

    /**
     * @return array{0: int, 1: int} [medium_daily, medium_monthly]
     */
    private function dbMediumCounters(?int $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT medium_daily_conversions, medium_monthly_conversions FROM users WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($row);

        return [(int) $row['medium_daily_conversions'], (int) $row['medium_monthly_conversions']];
    }

    private function persistUser(): User
    {
        $user = (new User())
            ->setGuestId('itest-quota-' . bin2hex(random_bytes(8)))
            ->setIsGuest(false);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistGuest(): User
    {
        $user = (new User())
            ->setGuestId('itest-guest-quota-' . bin2hex(random_bytes(8)))
            ->setIsGuest(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: int, 1: int} [light_daily, light_monthly]
     */
    private function dbLightCounters(?int $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT light_daily_conversions, light_monthly_conversions FROM users WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($row);

        return [(int) $row['light_daily_conversions'], (int) $row['light_monthly_conversions']];
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

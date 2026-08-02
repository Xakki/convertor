<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Quota;

use App\Entity\Plan;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Repository\PlanRepository;
use App\Service\Quota\QuotaService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Юнит-покрытие QuotaService: даунгрейд плана + выбор SQL для charge/refund.
 */
final class QuotaServiceTest extends TestCase
{
    private function makeService(
        PlanRepository $planRepo,
        string $appEnv = 'test',
        ?LoggerInterface $logger = null,
    ): QuotaService {
        return new QuotaService(
            $this->createStub(EntityManagerInterface::class),
            $planRepo,
            $logger ?? new NullLogger(),
            $appEnv,
        );
    }

    private function stubPlanRepo(?Plan $forName, ?Plan $forFree = null): PlanRepository
    {
        $repo = $this->createStub(PlanRepository::class);
        $repo->method('findByName')->willReturnCallback(
            static fn (string $name): ?Plan => $name === 'free' ? $forFree : $forName,
        );

        return $repo;
    }

    private function freePlanStub(): Plan
    {
        return (new Plan())
            ->setName('free')
            ->setLightDailyLimit(3)->setLightMonthlyLimit(30)
            ->setMediumDailyLimit(2)->setMediumMonthlyLimit(15)
            ->setHeavyDailyLimit(0)->setHeavyMonthlyLimit(0)
            ->setAiDailyLimit(0)->setAiMonthlyLimit(0)
            ->setMaxFileSizeMb(50);
    }

    private function proPlanStub(): Plan
    {
        return (new Plan())
            ->setName('pro')
            ->setLightDailyLimit(-1)->setLightMonthlyLimit(-1)
            ->setMediumDailyLimit(300)->setMediumMonthlyLimit(6000)
            ->setHeavyDailyLimit(60)->setHeavyMonthlyLimit(800)
            ->setAiDailyLimit(80)->setAiMonthlyLimit(1200)
            ->setMaxFileSizeMb(500);
    }

    private function prodService(Plan $plan, EntityManagerInterface $em): QuotaService
    {
        return new QuotaService($em, $this->stubPlanRepo($plan, $plan), new NullLogger(), 'prod');
    }

    private function freeUser(): User
    {
        return (new User())->setPlan('free');
    }

    public function testMaxUploadBytesUsesPlanFileSize(): void
    {
        $plan = (new Plan())->setName('pro')->setMaxFileSizeMb(500);
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $service = $this->makeService($this->stubPlanRepo($plan), 'test', $logger);

        self::assertSame(500 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    public function testMaxUploadBytesFallsBackWhenPlanSizeNonPositive(): void
    {
        $plan = (new Plan())->setName('free')->setMaxFileSizeMb(0);
        $user = (new User())->setPlan('free');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $service = $this->makeService($this->stubPlanRepo($plan, $plan), 'test', $logger);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    public function testFreeUserOnSeededTableNeitherWarnsNorThrows(): void
    {
        $free = $this->freePlanStub();
        $user = (new User())->setPlan('free');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $service = $this->makeService($this->stubPlanRepo($free, $free), 'test', $logger);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));

        $remaining = $service->getRemainingQuota($user);
        self::assertSame(3, $remaining['tiers']['light']['daily']['remaining']);
        self::assertSame(2, $remaining['tiers']['medium']['daily']['remaining']);
        self::assertSame(3, $remaining['tiers']['light']['daily']['limit']);
        self::assertSame(0, $remaining['tiers']['ai']['daily']['limit']);
    }

    public function testProdDowngradeLogsWarningWithoutThrowViaMaxUpload(): void
    {
        $free = (new Plan())->setName('free')->setMaxFileSizeMb(50);
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(static fn (array $ctx): bool => ($ctx['requestedPlan'] ?? null) === 'pro'
                    && ($ctx['fallbackTo'] ?? null)                                            === 'free'),
            );

        $service = $this->makeService($this->stubPlanRepo(null, $free), 'prod', $logger);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    public function testProdEmptyTableDowngradeReportsFreeFallbackWithoutThrow(): void
    {
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(static fn (array $ctx): bool => ($ctx['fallbackTo'] ?? null) === 'FREE_FALLBACK'),
            );

        $service = $this->makeService($this->stubPlanRepo(null, null), 'prod', $logger);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    public function testProdDowngradeReturnsFallbackLimitsViaGetRemainingQuota(): void
    {
        $free = $this->freePlanStub();
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = $this->makeService($this->stubPlanRepo(null, $free), 'prod', $logger);

        $remaining = $service->getRemainingQuota($user);
        self::assertSame(3, $remaining['tiers']['light']['daily']['remaining']);
        self::assertSame(0, $remaining['tiers']['ai']['daily']['remaining']);
    }

    public function testNonProdDowngradeThrowsViaMaxUpload(): void
    {
        $free = (new Plan())->setName('free')->setMaxFileSizeMb(50);
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = $this->makeService($this->stubPlanRepo(null, $free), 'test', $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pro/');
        $service->maxUploadBytes($user);
    }

    public function testNonProdDowngradeThrowsViaLimitsForPath(): void
    {
        $free    = $this->freePlanStub();
        $user    = (new User())->setPlan('pro');
        $service = $this->makeService($this->stubPlanRepo(null, $free), 'dev');

        $this->expectException(\RuntimeException::class);
        $service->getRemainingQuota($user);
    }

    public function testNonProdEmptyTableThrowsViaLimitsForPath(): void
    {
        $user    = (new User())->setPlan('free');
        $service = $this->makeService($this->stubPlanRepo(null, null), 'test');

        $this->expectException(\RuntimeException::class);
        $service->getRemainingQuota($user);
    }

    public function testChargeIncrementsLightTierColumns(): void
    {
        $this->assertAppliesSql(
            category: FileCategory::Document,
            isAi: false,
            charge: true,
            expect: static fn (string $sql): bool => str_contains($sql, 'light_daily_conversions = light_daily_conversions + 1')
                && str_contains($sql, 'light_monthly_conversions = light_monthly_conversions + 1'),
        );
    }

    public function testChargeIncrementsAiTierColumns(): void
    {
        $this->assertAppliesSql(
            category: FileCategory::Audio,
            isAi: true,
            charge: true,
            expect: static fn (string $sql): bool => str_contains($sql, 'ai_daily_conversions = ai_daily_conversions + 1')
                && str_contains($sql, 'ai_monthly_conversions = ai_monthly_conversions + 1'),
        );
    }

    public function testRefundClampedDecrementMediumTierColumns(): void
    {
        $this->assertAppliesSql(
            category: FileCategory::Audio,
            isAi: false,
            charge: false,
            expect: static fn (string $sql): bool => str_contains($sql, 'GREATEST(0, medium_daily_conversions - 1)')
                && str_contains($sql, 'GREATEST(0, medium_monthly_conversions - 1)'),
        );
    }

    public function testChargeIncrementsMediumTierForImageOcr(): void
    {
        $this->assertAppliesSql(
            category: FileCategory::Image,
            isAi: false,
            charge: true,
            expect: static fn (string $sql): bool => str_contains($sql, 'medium_daily_conversions = medium_daily_conversions + 1')
                && str_contains($sql, 'medium_monthly_conversions = medium_monthly_conversions + 1'),
        );
    }

    public function testChargeIncrementsHeavyTierColumns(): void
    {
        $this->assertAppliesSql(
            category: FileCategory::Video,
            isAi: false,
            charge: true,
            expect: static fn (string $sql): bool => str_contains($sql, 'heavy_daily_conversions = heavy_daily_conversions + 1')
                && str_contains($sql, 'heavy_monthly_conversions = heavy_monthly_conversions + 1'),
        );
    }

    public function testCheckThrows429WhenDailyCeilingReached(): void
    {
        $user = $this->freeUser()->setLightDailyConversions(3);
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessageMatches('/Daily light conversion limit of 3 reached/');

        $this->prodService($this->freePlanStub(), $em)->check($user, FileCategory::Document, false);
    }

    public function testCheckThrows429WhenMonthlyCeilingReachedIndependentOfDaily(): void
    {
        $user = $this->freeUser()
            ->setLightDailyConversions(0)
            ->setLightMonthlyConversions(30);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessageMatches('/Monthly light conversion limit of 30 reached/');

        $this->prodService($this->freePlanStub(), $em)->check($user, FileCategory::Document, false);
    }

    public function testFreeTierHeavyAndAiZeroFailImmediately(): void
    {
        $em      = $this->createMock(EntityManagerInterface::class);
        $service = $this->prodService($this->freePlanStub(), $em);

        try {
            $service->check($this->freeUser(), FileCategory::Video, false);
            self::fail('expected 429 for free heavy tier');
        } catch (TooManyRequestsHttpException $e) {
            self::assertStringContainsString('Daily heavy conversion limit of 0 reached', $e->getMessage());
        }

        try {
            $service->check($this->freeUser(), FileCategory::Audio, true);
            self::fail('expected 429 for free ai tier');
        } catch (TooManyRequestsHttpException $e) {
            self::assertStringContainsString('Daily ai conversion limit of 0 reached', $e->getMessage());
        }
    }

    public function testProUnlimitedLightTierNever429(): void
    {
        $user = (new User())->setPlan('pro')
            ->setLightDailyConversions(10_000)
            ->setLightMonthlyConversions(10_000);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->prodService($this->proPlanStub(), $em)->check($user, FileCategory::Document, false);

        $remaining = $this->prodService($this->proPlanStub(), $em)->getRemainingQuota($user);
        self::assertSame(-1, $remaining['tiers']['light']['daily']['remaining']);
        self::assertSame(-1, $remaining['tiers']['light']['monthly']['remaining']);
    }

    public function testDailyResetClearsOnlyDailyCounters(): void
    {
        $user = $this->freeUser()
            ->setLightDailyConversions(3)
            ->setMediumDailyConversions(2)
            ->setLightMonthlyConversions(10)
            ->setMediumMonthlyConversions(5)
            ->setQuotaResetAt(new \DateTimeImmutable('-1 day'))
            ->setMonthlyResetAt(new \DateTimeImmutable());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->prodService($this->freePlanStub(), $em)->getRemainingQuota($user);

        self::assertSame(0, $user->getLightDailyConversions());
        self::assertSame(0, $user->getMediumDailyConversions());
        self::assertSame(10, $user->getLightMonthlyConversions());
        self::assertSame(5, $user->getMediumMonthlyConversions());
    }

    public function testRollingMonthlyResetClearsOnlyMonthlyCounters(): void
    {
        $user = $this->freeUser()
            ->setLightDailyConversions(2)
            ->setLightMonthlyConversions(30)
            ->setMediumMonthlyConversions(15)
            ->setQuotaResetAt(new \DateTimeImmutable())
            ->setMonthlyResetAt(new \DateTimeImmutable('-31 days'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->prodService($this->freePlanStub(), $em)->getRemainingQuota($user);

        self::assertSame(2, $user->getLightDailyConversions());
        self::assertSame(0, $user->getLightMonthlyConversions());
        self::assertSame(0, $user->getMediumMonthlyConversions());
    }

    /**
     * @param callable(string):bool $expect
     */
    private function assertAppliesSql(FileCategory $category, bool $isAi, bool $charge, callable $expect): void
    {
        $user = (new User())->setPlan('free');

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => $expect($sql)),
                self::identicalTo(['id' => $user->getId()]),
            )
            ->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->expects(self::once())->method('refresh')->with(self::identicalTo($user));

        $service = new QuotaService($em, $this->createStub(PlanRepository::class), new NullLogger(), 'prod');

        $charge
            ? $service->charge($user, $category, $isAi)
            : $service->refund($user, $category, $isAi);
    }
}

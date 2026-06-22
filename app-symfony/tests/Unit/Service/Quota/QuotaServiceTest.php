<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Quota;

use App\Entity\Plan;
use App\Entity\User;
use App\Repository\PlanRepository;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class QuotaServiceTest extends TestCase
{
    private function makeService(): QuotaService
    {
        return new QuotaService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PlanRepository::class),
        );
    }

    public function testRefundDecrementsRegularCounter(): void
    {
        $user = new User();
        $user->setDailyConversions(3);
        $user->setDailyAiConversions(2);

        $this->makeService()->refund($user, false);

        self::assertSame(2, $user->getDailyConversions());
        self::assertSame(2, $user->getDailyAiConversions());
    }

    public function testRefundDecrementsAiCounter(): void
    {
        $user = new User();
        $user->setDailyConversions(3);
        $user->setDailyAiConversions(2);

        $this->makeService()->refund($user, true);

        self::assertSame(3, $user->getDailyConversions());
        self::assertSame(1, $user->getDailyAiConversions());
    }

    public function testRefundClampsAtZero(): void
    {
        $user = new User();
        $user->setDailyConversions(0);
        $user->setDailyAiConversions(0);

        $this->makeService()->refund($user, false);
        $this->makeService()->refund($user, true);

        self::assertSame(0, $user->getDailyConversions());
        self::assertSame(0, $user->getDailyAiConversions());
    }

    public function testMaxUploadBytesUsesPlanFileSize(): void
    {
        $plan = (new Plan())->setName('pro')->setMaxFileSizeMb(500);

        $planRepo = $this->createStub(PlanRepository::class);
        $planRepo->method('findByName')->willReturn($plan);

        $service = new QuotaService($this->createStub(EntityManagerInterface::class), $planRepo);

        self::assertSame(500 * 1024 * 1024, $service->maxUploadBytes(new User()));
    }

    public function testMaxUploadBytesFallsBackToFreeWhenPlanMissing(): void
    {
        $planRepo = $this->createStub(PlanRepository::class);
        $planRepo->method('findByName')->willReturn(null);

        $service = new QuotaService($this->createStub(EntityManagerInterface::class), $planRepo);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes(new User()));
    }

    public function testMaxUploadBytesFallsBackWhenPlanSizeNonPositive(): void
    {
        $plan = (new Plan())->setName('free')->setMaxFileSizeMb(0);

        $planRepo = $this->createStub(PlanRepository::class);
        $planRepo->method('findByName')->willReturn($plan);

        $service = new QuotaService($this->createStub(EntityManagerInterface::class), $planRepo);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes(new User()));
    }
}

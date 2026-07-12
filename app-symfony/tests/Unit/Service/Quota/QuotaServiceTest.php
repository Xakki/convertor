<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Quota;

use App\Entity\Plan;
use App\Entity\User;
use App\Repository\PlanRepository;
use App\Service\Quota\QuotaService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Юнит-покрытие двух фич hardening'а QuotaService:
 *
 * (A) Сигнал «тихого даунгрейда плана» в resolvePlan(): строки запрошенного
 *     плана нет → ВСЕГДА warning; вне prod ещё и throw (misconfig обязан падать
 *     в CI/dev, но не ронять платящих в prod). Проверяем оба публичных входа,
 *     зовущих resolvePlan: maxUploadBytes() напрямую и getRemainingQuota()
 *     через приватный limitsFor().
 *
 * (B, только выбор SQL) charge()/refund() ходят raw-UPDATE'ом; здесь мокаем
 *     Connection и проверяем ТОЛЬКО несущее: выбор колонки по $isAi, направление
 *     (+1 vs GREATEST(…-1)) и вызов refresh(). Реальный эффект в БД (инкремент,
 *     клемп на 0 через GREATEST, синхронизацию in-memory) проверяет
 *     QuotaServiceDbTest на интеграции.
 */
final class QuotaServiceTest extends TestCase
{
    /**
     * Собирает сервис со stub-EM (даунгрейд-ветки БД не касаются) и заданными
     * planRepo / appEnv / logger.
     */
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

    // ----- (A1) happy-path: план на месте, даунгрейда нет ---------------------

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
        // План есть (даунгрейда нет), но размер невалидный → FREE_MAX_UPLOAD_MB.
        $plan = (new Plan())->setName('free')->setMaxFileSizeMb(0);
        $user = (new User())->setPlan('free');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $service = $this->makeService($this->stubPlanRepo($plan, $plan), 'test', $logger);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    /**
     * Негативный кейс (нет false-positive): `free`-юзер на засеянной таблице —
     * НИ warning, НИ throw. Дискриминатор фичи-A: отличается от кейсов даунгрейда
     * ниже ТОЛЬКО состоянием репозитория (строка `free` присутствует).
     */
    public function testFreeUserOnSeededTableNeitherWarnsNorThrows(): void
    {
        $free = (new Plan())->setName('free')->setDailyLimit(2)->setDailyAiLimit(1)->setMaxFileSizeMb(50);
        $user = (new User())->setPlan('free');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        // appEnv='test' → если бы это трактовалось как даунгрейд, был бы throw.
        $service = $this->makeService($this->stubPlanRepo($free, $free), 'test', $logger);

        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));

        $remaining = $service->getRemainingQuota($user);
        self::assertSame(2, $remaining['conversions']);
        self::assertSame(1, $remaining['ai_conversions']);
    }

    // ----- (A2) prod-даунгрейд: warning, но НЕ throw --------------------------

    public function testProdDowngradeLogsWarningWithoutThrowViaMaxUpload(): void
    {
        // Юзер 'pro', строки 'pro' нет, есть 'free' → тихий даунгрейд к free.
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

        // Не бросает и отдаёт размер free-плана (fail-open в prod).
        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    public function testProdEmptyTableDowngradeReportsFreeFallbackWithoutThrow(): void
    {
        // Пустая таблица: нет ни 'pro', ни 'free' → fallbackTo='FREE_FALLBACK'.
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(static fn (array $ctx): bool => ($ctx['fallbackTo'] ?? null) === 'FREE_FALLBACK'),
            );

        $service = $this->makeService($this->stubPlanRepo(null, null), 'prod', $logger);

        // plan===null → FREE_MAX_UPLOAD_MB.
        self::assertSame(50 * 1024 * 1024, $service->maxUploadBytes($user));
    }

    public function testProdDowngradeReturnsFallbackLimitsViaGetRemainingQuota(): void
    {
        // Путь через приватный limitsFor(): getRemainingQuota() зовёт resolvePlan.
        $free = (new Plan())->setName('free')->setDailyLimit(2)->setDailyAiLimit(1);
        $user = (new User())->setPlan('pro');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = $this->makeService($this->stubPlanRepo(null, $free), 'prod', $logger);

        $remaining = $service->getRemainingQuota($user);
        self::assertSame(2, $remaining['conversions']);
        self::assertSame(1, $remaining['ai_conversions']);
    }

    // ----- (A3) non-prod даунгрейд: throw на обоих путях ----------------------

    public function testNonProdDowngradeThrowsViaMaxUpload(): void
    {
        $free = (new Plan())->setName('free')->setMaxFileSizeMb(50);
        $user = (new User())->setPlan('pro');

        // Всё равно логируется warning перед throw.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(static fn (array $ctx): bool => ($ctx['fallbackTo'] ?? null) === 'free'),
            );

        $service = $this->makeService($this->stubPlanRepo(null, $free), 'test', $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pro/');
        $service->maxUploadBytes($user);
    }

    public function testNonProdDowngradeThrowsViaLimitsForPath(): void
    {
        // Тот же даунгрейд, но входная точка — getRemainingQuota()→limitsFor().
        $free    = (new Plan())->setName('free')->setDailyLimit(2)->setDailyAiLimit(1);
        $user    = (new User())->setPlan('pro');
        $service = $this->makeService($this->stubPlanRepo(null, $free), 'dev');

        $this->expectException(\RuntimeException::class);
        $service->getRemainingQuota($user);
    }

    public function testNonProdEmptyTableThrowsViaLimitsForPath(): void
    {
        // Пустая таблица через limitsFor(): даунгрейд даже без строки 'free'.
        $user    = (new User())->setPlan('free');
        $service = $this->makeService($this->stubPlanRepo(null, null), 'test');

        $this->expectException(\RuntimeException::class);
        $service->getRemainingQuota($user);
    }

    // ----- (B, выбор SQL) charge/refund: колонка по $isAi + refresh -----------

    public function testChargeIncrementsRegularColumn(): void
    {
        $this->assertAppliesSql(
            isAi: false,
            charge: true,
            expect: static fn (string $sql): bool => str_contains($sql, 'daily_conversions = daily_conversions + 1')
                && ! str_contains($sql, 'daily_ai_conversions'),
        );
    }

    public function testChargeIncrementsAiColumn(): void
    {
        $this->assertAppliesSql(
            isAi: true,
            charge: true,
            expect: static fn (string $sql): bool => str_contains($sql, 'daily_ai_conversions = daily_ai_conversions + 1'),
        );
    }

    public function testRefundClampedDecrementRegularColumn(): void
    {
        $this->assertAppliesSql(
            isAi: false,
            charge: false,
            expect: static fn (string $sql): bool => str_contains($sql, 'GREATEST(0, daily_conversions - 1)')
                && ! str_contains($sql, 'daily_ai_conversions'),
        );
    }

    public function testRefundClampedDecrementAiColumn(): void
    {
        $this->assertAppliesSql(
            isAi: true,
            charge: false,
            expect: static fn (string $sql): bool => str_contains($sql, 'GREATEST(0, daily_ai_conversions - 1)'),
        );
    }

    /**
     * Мокает EM→Connection: проверяет, что charge/refund выдаёт один
     * executeStatement с ожидаемым SQL (несущие фрагменты) и id юзера, а затем
     * зовёт refresh($user).
     *
     * @param callable(string):bool $expect
     */
    private function assertAppliesSql(bool $isAi, bool $charge, callable $expect): void
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

        $charge ? $service->charge($user, $isAi) : $service->refund($user, $isAi);
    }
}

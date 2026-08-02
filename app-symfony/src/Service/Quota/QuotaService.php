<?php

declare(strict_types=1);

namespace App\Service\Quota;

use App\Entity\Plan;
use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Enum\BillingMode;
use App\Enum\FileCategory;
use App\Enum\QuotaTier;
use App\Exception\InsufficientBalanceException;
use App\Repository\PlanRepository;
use App\Service\Billing\BalanceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class QuotaService
{
    /**
     * Last-resort tier limits (daily/monthly) when the `plans` table is unseeded.
     * Mirrors the free tier from CNV-30. -1 = unlimited.
     *
     * @var array<string, array{daily: int, monthly: int}>
     */
    private const FREE_FALLBACK = [
        'light'  => ['daily' => 3, 'monthly' => 30],
        'medium' => ['daily' => 2, 'monthly' => 15],
        'heavy'  => ['daily' => 0, 'monthly' => 0],
        'ai'     => ['daily' => 0, 'monthly' => 0],
    ];

    /**
     * Last-resort max upload size (MB) used only when the resolved plan row is
     * missing or carries a non-positive `maxFileSizeMb`.
     */
    private const FREE_MAX_UPLOAD_MB = 50;

    /** @var array<string, array{daily: string, monthly: string}> */
    private const TIER_COUNTER_COLUMNS = [
        'light'  => ['daily' => 'light_daily_conversions', 'monthly' => 'light_monthly_conversions'],
        'medium' => ['daily' => 'medium_daily_conversions', 'monthly' => 'medium_monthly_conversions'],
        'heavy'  => ['daily' => 'heavy_daily_conversions', 'monthly' => 'heavy_monthly_conversions'],
        'ai'     => ['daily' => 'ai_daily_conversions', 'monthly' => 'ai_monthly_conversions'],
    ];

    /** @var list<QuotaTier> */
    private const ALL_TIERS = [
        QuotaTier::Light,
        QuotaTier::Medium,
        QuotaTier::Heavy,
        QuotaTier::Ai,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanRepository $planRepository,
        private readonly BalanceService $balanceService,
        private readonly LoggerInterface $logger,
        private readonly string $appEnv,
    ) {
    }

    /**
     * Up-front limit check: reject an over-limit request BEFORE any work is done.
     * В квоте → plan_quota; сверх квоты у ROLE_USER с балансом → prepaid_balance;
     * гость сверх квоты → 429; залогиненный без баланса → InsufficientBalanceException.
     */
    public function check(User $user, FileCategory $category, bool $isAi): BillingMode
    {
        $this->resetIfNeeded($user);

        $tier   = QuotaTier::resolve($category, $isAi);
        $limits = $this->limitsForTier($this->resolvePlan($user), $tier);
        $used   = $this->usedForTier($user, $tier);

        if (! $this->isOverQuota($limits, $used)) {
            return BillingMode::PlanQuota;
        }

        if ($user->isGuest()) {
            $this->throwQuotaExceeded($tier, $limits, $used);
        }

        $cost = $this->balanceService->getPayPerUseCostCents($isAi);
        if ($this->balanceService->getBalanceCents($user) >= $cost) {
            return BillingMode::PrepaidBalance;
        }

        throw new InsufficientBalanceException('insufficient_balance');
    }

    /**
     * Commit one conversion charge. plan_quota → tier counters; prepaid_balance →
     * atomic debit (без инкремента счётчиков). Call only AFTER submit succeeded
     * (plan_quota) or BEFORE dispatch (prepaid — debit до постановки в очередь).
     */
    public function charge(
        User $user,
        FileCategory $category,
        bool $isAi,
        BillingMode $billingMode,
        ?int $conversionId = null,
    ): void {
        if ($billingMode === BillingMode::PrepaidBalance) {
            $cost  = $this->balanceService->getPayPerUseCostCents($isAi);
            $refId = $conversionId !== null ? (string) $conversionId : null;
            $this->balanceService->debit(
                $user,
                $cost,
                BalanceTransactionSource::Conversion,
                $refId,
            );

            return;
        }

        $this->resetIfNeeded($user);
        $this->applyDelta($user, QuotaTier::resolve($category, $isAi), +1);
    }

    /**
     * Refund one conversion: prepaid → credit баланса; plan_quota → decrement counters.
     */
    public function refund(
        User $user,
        FileCategory $category,
        bool $isAi,
        BillingMode $billingMode,
        ?int $conversionId = null,
    ): void {
        if ($billingMode === BillingMode::PrepaidBalance) {
            $cost  = $this->balanceService->getPayPerUseCostCents($isAi);
            $refId = $conversionId !== null ? (string) $conversionId : null;
            $this->balanceService->refund(
                $user,
                $cost,
                BalanceTransactionSource::Conversion,
                $refId,
            );

            return;
        }

        $this->resetIfNeeded($user);
        $this->applyDelta($user, QuotaTier::resolve($category, $isAi), -1);
    }

    /**
     * Остаток + лимиты плана по всем 4 тирам × 2 окна (CNV-30) + prepaid-баланс.
     *
     * @return array<string, mixed>
     */
    public function getRemainingQuota(User $user): array
    {
        $this->resetIfNeeded($user);

        $plan  = $this->resolvePlan($user);
        $tiers = [];
        foreach (self::ALL_TIERS as $tier) {
            $limits = $this->limitsForTier($plan, $tier);
            $used   = $this->usedForTier($user, $tier);
            $key    = $tier->value;

            $tiers[$key] = [
                'daily' => [
                    'used'      => $used['daily'],
                    'limit'     => $limits['daily'],
                    'remaining' => $this->remaining($limits['daily'], $used['daily']),
                ],
                'monthly' => [
                    'used'      => $used['monthly'],
                    'limit'     => $limits['monthly'],
                    'remaining' => $this->remaining($limits['monthly'], $used['monthly']),
                ],
            ];
        }

        return [
            'plan'                 => $user->getPlan(),
            'tiers'                => $tiers,
            'balance_cents'        => $this->balanceService->getBalanceCents($user),
            'pay_per_use_cents'    => $this->balanceService->getPayPerUseCostCents(false),
            'pay_per_use_ai_cents' => $this->balanceService->getPayPerUseCostCents(true),
        ];
    }

    public function resetIfNeeded(User $user): void
    {
        $now        = new \DateTimeImmutable();
        $needsFlush = false;

        if ($user->getQuotaResetAt()->format('Y-m-d') < $now->format('Y-m-d')) {
            $this->resetDailyCounters($user);
            $user->setQuotaResetAt($now);
            $needsFlush = true;
        }

        if ($now >= $user->getMonthlyResetAt()->modify('+30 days')) {
            $this->resetMonthlyCounters($user);
            $user->setMonthlyResetAt($now);
            $needsFlush = true;
        }

        if ($needsFlush) {
            $this->em->flush();
        }
    }

    /**
     * Безусловный сброс всех пер-тир счётчиков обоих окон (admin reset-quota).
     */
    public function reset(User $user, bool $flush = true): void
    {
        $now = new \DateTimeImmutable();
        $this->resetDailyCounters($user);
        $this->resetMonthlyCounters($user);
        $user->setQuotaResetAt($now);
        $user->setMonthlyResetAt($now);

        if ($flush) {
            $this->em->flush();
        }
    }

    public function maxUploadBytes(User $user): int
    {
        $plan = $this->resolvePlan($user);

        $mb = $plan?->getMaxFileSizeMb() ?? 0;
        if ($mb <= 0) {
            $mb = self::FREE_MAX_UPLOAD_MB;
        }

        return $mb * 1024 * 1024;
    }

    /**
     * @param array{daily: int, monthly: int} $limits
     * @param array{daily: int, monthly: int} $used
     */
    private function isOverQuota(array $limits, array $used): bool
    {
        if ($limits['daily'] !== -1 && $used['daily'] >= $limits['daily']) {
            return true;
        }

        return $limits['monthly'] !== -1 && $used['monthly'] >= $limits['monthly'];
    }

    /**
     * @param array{daily: int, monthly: int} $limits
     * @param array{daily: int, monthly: int} $used
     */
    private function throwQuotaExceeded(QuotaTier $tier, array $limits, array $used): never
    {
        if ($limits['daily'] !== -1 && $used['daily'] >= $limits['daily']) {
            throw new TooManyRequestsHttpException(
                null,
                sprintf('Daily %s conversion limit of %d reached. Upgrade your plan.', $tier->value, $limits['daily']),
            );
        }

        throw new TooManyRequestsHttpException(
            null,
            sprintf('Monthly %s conversion limit of %d reached. Upgrade your plan.', $tier->value, $limits['monthly']),
        );
    }

    /**
     * @return array{daily: int, monthly: int}
     */
    private function limitsForTier(?Plan $plan, QuotaTier $tier): array
    {
        if ($plan === null) {
            return self::FREE_FALLBACK[$tier->value];
        }

        return match ($tier) {
            QuotaTier::Light => [
                'daily'   => $plan->getLightDailyLimit(),
                'monthly' => $plan->getLightMonthlyLimit(),
            ],
            QuotaTier::Medium => [
                'daily'   => $plan->getMediumDailyLimit(),
                'monthly' => $plan->getMediumMonthlyLimit(),
            ],
            QuotaTier::Heavy => [
                'daily'   => $plan->getHeavyDailyLimit(),
                'monthly' => $plan->getHeavyMonthlyLimit(),
            ],
            QuotaTier::Ai => [
                'daily'   => $plan->getAiDailyLimit(),
                'monthly' => $plan->getAiMonthlyLimit(),
            ],
        };
    }

    /**
     * @return array{daily: int, monthly: int}
     */
    private function usedForTier(User $user, QuotaTier $tier): array
    {
        return match ($tier) {
            QuotaTier::Light => [
                'daily'   => $user->getLightDailyConversions(),
                'monthly' => $user->getLightMonthlyConversions(),
            ],
            QuotaTier::Medium => [
                'daily'   => $user->getMediumDailyConversions(),
                'monthly' => $user->getMediumMonthlyConversions(),
            ],
            QuotaTier::Heavy => [
                'daily'   => $user->getHeavyDailyConversions(),
                'monthly' => $user->getHeavyMonthlyConversions(),
            ],
            QuotaTier::Ai => [
                'daily'   => $user->getAiDailyConversions(),
                'monthly' => $user->getAiMonthlyConversions(),
            ],
        };
    }

    private function remaining(int $limit, int $used): int
    {
        return $limit === -1 ? -1 : max(0, $limit - $used);
    }

    private function resetDailyCounters(User $user): void
    {
        $user->setLightDailyConversions(0);
        $user->setMediumDailyConversions(0);
        $user->setHeavyDailyConversions(0);
        $user->setAiDailyConversions(0);
    }

    private function resetMonthlyCounters(User $user): void
    {
        $user->setLightMonthlyConversions(0);
        $user->setMediumMonthlyConversions(0);
        $user->setHeavyMonthlyConversions(0);
        $user->setAiMonthlyConversions(0);
    }

    /**
     * Атомарно применяет дельту (+1 charge / -1 refund) к daily+monthly колонкам
     * выбранного тира прямым `UPDATE … WHERE id`.
     */
    private function applyDelta(User $user, QuotaTier $tier, int $delta): void
    {
        $cols       = self::TIER_COUNTER_COLUMNS[$tier->value];
        $dailyCol   = $cols['daily'];
        $monthlyCol = $cols['monthly'];

        if ($delta < 0) {
            $sql = "UPDATE users SET {$dailyCol} = GREATEST(0, {$dailyCol} - 1), "
                . "{$monthlyCol} = GREATEST(0, {$monthlyCol} - 1) WHERE id = :id";
        } else {
            $sql = "UPDATE users SET {$dailyCol} = {$dailyCol} + 1, "
                . "{$monthlyCol} = {$monthlyCol} + 1 WHERE id = :id";
        }

        $this->em->getConnection()->executeStatement($sql, ['id' => $user->getId()]);
        $this->em->refresh($user);
    }

    private function resolvePlan(User $user): ?Plan
    {
        $requested = $user->getPlan();

        $plan = $this->planRepository->findByName($requested);
        if ($plan !== null) {
            return $plan;
        }

        $free       = $this->planRepository->findByName('free');
        $fallbackTo = $free !== null ? 'free' : 'FREE_FALLBACK';

        $this->logger->warning('Quota silent plan downgrade: requested plan row missing', [
            'requestedPlan' => $requested,
            'fallbackTo'    => $fallbackTo,
        ]);

        if ($this->appEnv !== 'prod') {
            throw new \RuntimeException(sprintf(
                'Quota silent downgrade: plan "%s" not found, served %s. Seed the `plans` table.',
                $requested,
                $fallbackTo,
            ));
        }

        return $free;
    }
}

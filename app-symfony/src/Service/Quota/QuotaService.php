<?php

declare(strict_types=1);

namespace App\Service\Quota;

use App\Entity\User;
use App\Repository\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class QuotaService
{
    /**
     * Last-resort daily limits used only when the `plans` table is unseeded
     * (the migration seeds free/basic/pro). -1 = unlimited.
     *
     * @var array<string, int>
     */
    private const FREE_FALLBACK = ['conversions' => 2, 'ai_conversions' => 1];

    /**
     * Last-resort max upload size (MB) used only when the resolved plan row is
     * missing or carries a non-positive `maxFileSizeMb` (mirrors the free tier:
     * 50 MB free / 500 MB paid, enforced from the `plans` table).
     */
    private const FREE_MAX_UPLOAD_MB = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanRepository $planRepository,
    ) {
    }

    /**
     * Up-front limit check: reject an over-limit request BEFORE any work is done.
     * Read-only w.r.t. the counters (no increment) — the actual charge happens in
     * {@see charge()} only after a successful submit, so a failure mid-submit can
     * never leak a charge.
     */
    public function check(User $user, bool $isAi): void
    {
        $this->resetIfNeeded($user);

        $limits = $this->limitsFor($user);
        $limit  = $isAi ? $limits['ai_conversions'] : $limits['conversions'];
        $used   = $isAi ? $user->getDailyAiConversions() : $user->getDailyConversions();

        if ($limit !== -1 && $used >= $limit) {
            throw new TooManyRequestsHttpException(
                null,
                $isAi
                    ? "Daily AI conversion limit of {$limit} reached. Upgrade your plan."
                    : "Daily conversion limit of {$limit} reached. Upgrade your plan.",
            );
        }
    }

    /**
     * Commit one daily conversion. Call only AFTER the submit succeeded
     * (S3 upload + persist + enqueue) so the charge is never left dangling.
     */
    public function charge(User $user, bool $isAi): void
    {
        if ($isAi) {
            $user->incrementDailyAiConversions();
        } else {
            $user->incrementDailyConversions();
        }

        $this->em->flush();
    }

    /**
     * Refund one daily conversion (e.g. the worker reported a failure). Pure
     * mutation + clamp-at-0: the caller owns the flush so the User is persisted
     * by the same EM that loaded it (the result consumer re-resolves its EM per
     * message). Clamp-at-0 also makes a refund a no-op once the daily window has
     * rolled over and the counter was already reset.
     */
    public function refund(User $user, bool $isAi): void
    {
        if ($isAi) {
            $user->setDailyAiConversions(max(0, $user->getDailyAiConversions() - 1));
        } else {
            $user->setDailyConversions(max(0, $user->getDailyConversions() - 1));
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function getRemainingQuota(User $user): array
    {
        $this->resetIfNeeded($user);

        $limits = $this->limitsFor($user);

        return [
            'conversions' => $limits['conversions'] === -1
                ? -1
                : max(0, $limits['conversions'] - $user->getDailyConversions()),
            'ai_conversions' => $limits['ai_conversions'] === -1
                ? -1
                : max(0, $limits['ai_conversions'] - $user->getDailyAiConversions()),
            'plan' => $user->getPlan(),
        ];
    }

    public function resetIfNeeded(User $user): void
    {
        $now = new \DateTimeImmutable();

        if ($user->getQuotaResetAt()->format('Y-m-d') < $now->format('Y-m-d')) {
            $this->reset($user);
        }
    }

    /**
     * Безусловный сброс дневной квоты: счётчики → 0, окно (`quotaResetAt`) → now.
     * Единая точка обнуления счётчиков (её же зовёт resetIfNeeded при смене
     * суток) — ручной admin-сброс не дублирует логику в контроллере, а бьёт
     * ровно те же поля. $flush=false — если вызывающий владеет своим flush.
     */
    public function reset(User $user, bool $flush = true): void
    {
        $user->setDailyConversions(0);
        $user->setDailyAiConversions(0);
        $user->setQuotaResetAt(new \DateTimeImmutable());

        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * Per-plan max upload size in bytes, read from the `plans` table
     * (`maxFileSizeMb`). Falls back to the `free` plan, then to
     * {@see FREE_MAX_UPLOAD_MB} when the row is missing or non-positive.
     */
    public function maxUploadBytes(User $user): int
    {
        $plan = $this->planRepository->findByName($user->getPlan())
            ?? $this->planRepository->findByName('free');

        $mb = $plan?->getMaxFileSizeMb() ?? 0;
        if ($mb <= 0) {
            $mb = self::FREE_MAX_UPLOAD_MB;
        }

        return $mb * 1024 * 1024;
    }

    /**
     * Single source of truth for daily limits: the `plans` table. Falls back to
     * the `free` plan, then to a hardcoded free baseline if the table is unseeded.
     *
     * @return array<string, int>
     */
    private function limitsFor(User $user): array
    {
        $plan = $this->planRepository->findByName($user->getPlan())
            ?? $this->planRepository->findByName('free');

        if ($plan === null) {
            return self::FREE_FALLBACK;
        }

        return [
            'conversions'    => $plan->getDailyLimit(),
            'ai_conversions' => $plan->getDailyAiLimit(),
        ];
    }
}

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
        $now     = new \DateTimeImmutable();
        $resetAt = $user->getQuotaResetAt();

        if ($resetAt->format('Y-m-d') < $now->format('Y-m-d')) {
            $user->setDailyConversions(0);
            $user->setDailyAiConversions(0);
            $user->setQuotaResetAt($now);
            $this->em->flush();
        }
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

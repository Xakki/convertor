<?php

declare(strict_types=1);

namespace App\Service\Quota;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class QuotaService
{
    /** Daily limits per plan [regular, ai] */
    private array $planLimits = [
        'free'  => ['conversions' => 2,   'ai_conversions' => 1],
        'basic' => ['conversions' => 100, 'ai_conversions' => 30],
        'pro'   => ['conversions' => -1,  'ai_conversions' => 100],  // -1 = unlimited
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function checkAndDecrement(User $user, bool $isAi): void
    {
        $this->resetIfNeeded($user);

        $limits = $this->planLimits[$user->getPlan()] ?? $this->planLimits['free'];

        if ($isAi) {
            $limit = $limits['ai_conversions'];
            if ($limit !== -1 && $user->getDailyAiConversions() >= $limit) {
                throw new TooManyRequestsHttpException(
                    null,
                    "Daily AI conversion limit of {$limit} reached. Upgrade your plan."
                );
            }
            $user->incrementDailyAiConversions();
        } else {
            $limit = $limits['conversions'];
            if ($limit !== -1 && $user->getDailyConversions() >= $limit) {
                throw new TooManyRequestsHttpException(
                    null,
                    "Daily conversion limit of {$limit} reached. Upgrade your plan."
                );
            }
            $user->incrementDailyConversions();
        }

        $this->em->flush();
    }

    public function getRemainingQuota(User $user): array
    {
        $this->resetIfNeeded($user);

        $limits = $this->planLimits[$user->getPlan()] ?? $this->planLimits['free'];

        return [
            'conversions'    => $limits['conversions'] === -1
                ? -1
                : max(0, $limits['conversions'] - $user->getDailyConversions()),
            'ai_conversions' => $limits['ai_conversions'] === -1
                ? -1
                : max(0, $limits['ai_conversions'] - $user->getDailyAiConversions()),
            'plan'           => $user->getPlan(),
        ];
    }

    public function resetIfNeeded(User $user): void
    {
        $now = new \DateTimeImmutable();
        $resetAt = $user->getQuotaResetAt();

        if ($resetAt->format('Y-m-d') < $now->format('Y-m-d')) {
            $user->setDailyConversions(0);
            $user->setDailyAiConversions(0);
            $user->setQuotaResetAt($now);
            $this->em->flush();
        }
    }
}

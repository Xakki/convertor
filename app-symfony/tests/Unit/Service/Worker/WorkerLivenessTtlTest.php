<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Worker;

use App\Service\Worker\WorkerLivenessTtl;
use PHPUnit\Framework\TestCase;

/**
 * Pins the staleness-threshold FORMULA itself (registry-07 review: extracted
 * so {@see \App\Service\Worker\WorkerCapabilityGcService} and
 * {@see \App\Service\Admin\WorkerStatsProvider} can never silently diverge).
 * These are the only tests that exercise the formula directly — both callers
 * keep asserting their own behavior against it, not re-deriving it.
 */
final class WorkerLivenessTtlTest extends TestCase
{
    public function testThresholdIsApproximatelyNowMinusTtlHours(): void
    {
        $before    = new \DateTimeImmutable();
        $threshold = WorkerLivenessTtl::staleThreshold(24);
        $after     = new \DateTimeImmutable();

        // staleThreshold()'s own "now" was captured somewhere between $before
        // and $after, so "now - 24h" must land in the same bracket, shifted.
        self::assertGreaterThanOrEqual($before->modify('-24 hours')->getTimestamp(), $threshold->getTimestamp());
        self::assertLessThanOrEqual($after->modify('-24 hours')->getTimestamp(), $threshold->getTimestamp());
    }

    /**
     * Floor guard: a misconfigured `ttlHours <= 0` must not mean "everything
     * is stale/deletable right now" — same defensive floor as
     * FileCleanupService's `max(1, …)` pattern.
     */
    public function testNonPositiveTtlHoursIsFlooredToOneHour(): void
    {
        $zero     = WorkerLivenessTtl::staleThreshold(0);
        $negative = WorkerLivenessTtl::staleThreshold(-10);
        $one      = WorkerLivenessTtl::staleThreshold(1);

        // All three floor to "-1 hour" — assert they land within the same
        // few-second window rather than comparing exact timestamps (avoids
        // test flakiness from the tiny gap between the three calls).
        self::assertEqualsWithDelta($one->getTimestamp(), $zero->getTimestamp(), 2);
        self::assertEqualsWithDelta($one->getTimestamp(), $negative->getTimestamp(), 2);
    }

    public function testLargerTtlProducesAnOlderThreshold(): void
    {
        $short = WorkerLivenessTtl::staleThreshold(24);
        $long  = WorkerLivenessTtl::staleThreshold(168);

        self::assertLessThan($short->getTimestamp(), $long->getTimestamp());
    }
}

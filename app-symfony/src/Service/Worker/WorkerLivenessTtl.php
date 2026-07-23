<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * Single source of truth for the worker-capability staleness-threshold
 * FORMULA (registry-07 review finding). The TTL *value* was already
 * single-sourced via one env var (`WORKER_CAPABILITY_GC_TTL_HOURS`,
 * `services.yaml`), but the formula that turns it into a cutoff
 * `DateTimeImmutable` was duplicated verbatim in
 * {@see WorkerCapabilityGcService::run()} and
 * {@see \App\Service\Admin\WorkerStatsProvider::collect()} — a future edit to
 * the floor, the unit, or added jitter in one place would silently diverge
 * the admin page's "stale" prediction from when GC actually deletes the row.
 * Both callers MUST go through this one callable — never re-derive the
 * threshold inline again.
 */
final class WorkerLivenessTtl
{
    /**
     * Rows with `lastSeen` strictly older than the returned threshold are
     * eligible for GC (and shown as `stale` on the admin page). `max(1, …)`
     * — same defensive floor as `FileCleanupService`: a misconfigured
     * `ttlHours <= 0` must not mean "everything is stale/deletable right now".
     */
    public static function staleThreshold(int $ttlHours): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify('-' . max(1, $ttlHours) . ' hours');
    }
}

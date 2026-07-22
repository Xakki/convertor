<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\WorkerLivenessStatus;
use App\Repository\ConversionRepository;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Queue\ConversionResultPersister;
use App\Service\Storage\S3Storage;
use App\Service\Worker\ResultKeyBuilder;
use App\Service\Worker\WorkerStreamGateway;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Внутренний relay для WS-Gateway (§5/§7 spec). Только gateway ходит сюда —
 * firewall `internal_api` (токен GATEWAY_INTERNAL_TOKEN), НЕ публичный
 * worker_api. Эндпоинты НЕ трогают KeyDB Stream и НЕ делают XACK — владение
 * XACK теперь целиком у gateway (он ретранслирует result/fail и сам ацкает).
 *
 * - result: малый результат (≤256 KB) приходит inline (base64) по WS →
 *   gateway → сюда → Symfony пишет S3 + БД. Успешный 200 = сигнал gateway ацкать.
 * - fail: воркер сдался → gateway ретранслирует сюда → пометить failed + refund.
 * - dlq-fail: job исчерпал ретраи и осел в DLQ-стриме `conv.dead` → отдельный
 *   consumer gateway ретранслирует сюда → пометить failed + refund. В отличие от
 *   fail() ключуется НАПРЯМУЮ по conversionId (не jobId→getJobMeta): запись
 *   `worker:job:{jobId}` к моменту DLQ-записи уже удалена, поэтому getJobMeta()
 *   дал бы гарантированный 404.
 */
#[Route('/api/v1/internal/worker')]
final class InternalWorkerController extends AbstractController
{
    public function __construct(
        private readonly WorkerStreamGateway $gateway,
        private readonly ConversionResultPersister $persister,
        private readonly S3Storage $s3,
        private readonly ResultKeyBuilder $keyBuilder,
        private readonly ConversionRepository $conversions,
        private readonly WorkerCapabilityRepository $workerCapabilities,
    ) {
    }

    /**
     * POST /api/v1/internal/worker/result
     * Body: {"jobId":"<streamId>","data":"<base64>","mime":"<opt>","processingMs":<opt int|null>}
     *
     * При ошибке S3/persist исключение всплывает как 5xx — gateway НЕ ацкает.
     */
    #[Route('/result', methods: ['POST'])]
    public function result(Request $request): JsonResponse
    {
        $body  = json_decode((string) $request->getContent(), true, 512, 0);
        $jobId = is_array($body) && isset($body['jobId']) ? (string) $body['jobId'] : '';

        $meta = $jobId !== '' ? $this->gateway->getJobMeta($jobId) : null;
        if ($meta === null) {
            return $this->json(['error' => 'Job not found or already completed'], Response::HTTP_NOT_FOUND);
        }

        $rawData = is_array($body) && isset($body['data']) ? (string) $body['data'] : '';
        if ($rawData === '') {
            return $this->json(['error' => '"data" field is required'], Response::HTTP_BAD_REQUEST);
        }

        $bytes = base64_decode($rawData, true);
        if ($bytes === false) {
            return $this->json(['error' => '"data" is not valid base64'], Response::HTTP_BAD_REQUEST);
        }

        $mime = is_array($body) && isset($body['mime']) && (string) $body['mime'] !== ''
            ? (string) $body['mime']
            : 'application/octet-stream';
        $processingMs = is_array($body) && isset($body['processingMs']) && $body['processingMs'] !== null
            ? (int) $body['processingMs']
            : null;

        $conversionId = $meta['conversionId'];
        $resultKey    = $this->keyBuilder->build($conversionId, $meta['targetFormat']);
        $bucket       = $this->s3->resultsBucket();

        $this->s3->putObject($bucket, $resultKey, $bytes, $mime);

        $this->persister->persist([
            'conversionId' => $conversionId,
            'state'        => 'completed',
            'outputBucket' => $bucket,
            'outputKey'    => $resultKey,
            'outputMime'   => $mime,
            'outputSize'   => strlen($bytes),
            'processingMs' => $processingMs,
        ]);

        return $this->json(['ok' => true]);
    }

    /**
     * POST /api/v1/internal/worker/fail
     * Body: {"jobId":"<streamId>","error":"<msg>","processingMs":<opt int|null>}
     */
    #[Route('/fail', methods: ['POST'])]
    public function fail(Request $request): JsonResponse
    {
        $body  = json_decode((string) $request->getContent(), true, 512, 0);
        $jobId = is_array($body) && isset($body['jobId']) ? (string) $body['jobId'] : '';

        $meta = $jobId !== '' ? $this->gateway->getJobMeta($jobId) : null;
        if ($meta === null) {
            return $this->json(['error' => 'Job not found or already completed'], Response::HTTP_NOT_FOUND);
        }

        $error        = is_array($body) && isset($body['error']) ? (string) $body['error'] : 'Worker reported failure';
        $processingMs = is_array($body) && isset($body['processingMs']) && $body['processingMs'] !== null
            ? (int) $body['processingMs']
            : null;

        $this->persister->persist([
            'conversionId' => $meta['conversionId'],
            'state'        => 'failed',
            'error'        => mb_substr($error, 0, 500),
            'processingMs' => $processingMs,
        ]);

        return $this->json(['ok' => true]);
    }

    /**
     * POST /api/v1/internal/worker/dlq-fail
     * Body: {"conversionId":<int>,"reason":"<msg>","processingMs":<opt int|null>,"attempt":<opt int|null>}
     *
     * Keyed directly on conversionId (DLQ entry carries it), NOT jobId: the
     * `worker:job:{jobId}` meta is already gone by the time the DLQ-consumer
     * relays here, so getJobMeta() is not usable for this path. persist() is
     * idempotent (status-guard skips Completed/Failed) and does the quota
     * refund — the only thing this action adds is the 404 for an unknown
     * conversionId (persist() itself only logs+no-ops on a miss).
     *
     * `attempt` (requeue-attempt-generation-marker, cross-zone contract with the
     * gateway DLQ-consumer) — int|null, echoed from the job's `attempt` field.
     * `null`/absent for DLQ entries written before this field existed (legacy,
     * drained on first deploy) — persist() treats that as "no stale-guard",
     * identical to today. Passed through untouched; the actual stale-vs-current
     * comparison lives in {@see ConversionResultPersister::persist()}.
     */
    #[Route('/dlq-fail', methods: ['POST'])]
    public function dlqFail(Request $request): JsonResponse
    {
        $body         = json_decode((string) $request->getContent(), true, 512, 0);
        $conversionId = is_array($body) && isset($body['conversionId']) ? (int) $body['conversionId'] : 0;

        if ($conversionId <= 0) {
            return $this->json(['error' => '"conversionId" field is required'], Response::HTTP_BAD_REQUEST);
        }

        if ($this->conversions->find($conversionId) === null) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }

        $reason       = is_array($body) && isset($body['reason']) ? (string) $body['reason'] : 'Job exhausted retries (DLQ)';
        $processingMs = is_array($body) && isset($body['processingMs']) && $body['processingMs'] !== null
            ? (int) $body['processingMs']
            : null;
        $attempt = is_array($body) && isset($body['attempt']) && $body['attempt'] !== null
            ? (int) $body['attempt']
            : null;

        $this->persister->persist([
            'conversionId' => $conversionId,
            'state'        => 'failed',
            'error'        => mb_substr($reason, 0, 500),
            'processingMs' => $processingMs,
            'attempt'      => $attempt,
        ]);

        return $this->json(['ok' => true]);
    }

    /**
     * POST /api/v1/internal/worker/liveness
     * Body: {"instances":[{"workerType":"...","instanceId":"...",
     *   "status":"alive"|"disconnected","lastSeenAt":"<ISO-8601 UTC>",
     *   "metrics":{"cpu":<float|null>,"mem":<float|null>,"load":<float|null>}|absent}]}
     * Response: {"updated":<int>,"unknown":[{"workerType":"...","instanceId":"..."}]}
     *
     * registry-06 (gateway batch push, one tick per ping-aggregation window).
     * UPDATE ONLY — {@see WorkerCapabilityRepository::updateLiveness()} never
     * inserts. An unknown `(workerType, instanceId)` (no matching capability
     * row — never registered, or already GC'd) is reported back in `unknown`
     * instead of being silently created — a liveness ping fabricating a
     * worker with no declared matrix is exactly what registry-05's "no
     * silent non-empty fallback" contract forbids.
     *
     * KNOWN GAP (review finding, tracked as a separate grooming card — do
     * NOT treat this as self-healing): reporting `unknown` does not, by
     * itself, cause anything to re-create the row. Nothing here forces the
     * gateway to re-register that worker, and `register()` only fires on the
     * worker's OWN (re)connect. If a row is GC'd while its worker keeps its
     * WS connection open (never drops it), the gap persists indefinitely —
     * not just "until the next push cycle" — until an unrelated reconnect
     * happens to trigger a fresh `register()`.
     *
     * `status` IS persisted ({@see WorkerCapability::$status},
     * {@see \App\Enum\WorkerLivenessStatus}) — it does NOT gate routing
     * (`ConversionRegistry` never reads it), it is a pure monitoring signal
     * for the future admin page. `metrics` is validated (shape-checked,
     * malformed rejects the batch same as any other field) but deliberately
     * NOT persisted — accept-and-ignore, grooming decision: no current
     * consumer reads worker metrics anywhere (registry-07's admin page lists
     * workerType/instanceId/image/version/lastSeen/alive-stale/pair count,
     * no metrics), so shipping a column nobody reads would be dead schema.
     * Add it (one migration) if/when something actually consumes it — this
     * is NOT an oversight, do not "fix" it by adding persistence without a
     * consumer driving the requirement.
     *
     * Malformed-batch policy: ANY invalid entry rejects the WHOLE batch with
     * 400 (not a partial-apply-and-report-the-rest split). This is an
     * internal trusted gateway↔PHP contract, not a public API expected to
     * tolerate garbage — a malformed entry almost always signals a version
     * skew in the gateway's serialization, and silently applying the valid
     * remainder would mask that bug instead of surfacing it. The GC TTL is
     * days, not minutes (registry-06 "long-TTL GC"), so one rejected batch
     * delaying a heartbeat is not operationally significant.
     */
    #[Route('/liveness', methods: ['POST'])]
    public function liveness(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true, 512, 0);
        if (! is_array($body) || ! isset($body['instances']) || ! is_array($body['instances'])) {
            return $this->json(['error' => '"instances" must be an array'], Response::HTTP_BAD_REQUEST);
        }

        $parsed = [];
        foreach ($body['instances'] as $i => $entry) {
            $validated = $this->validateLivenessEntry($entry);
            if (is_string($validated)) {
                return $this->json(['error' => "instances[{$i}]: {$validated}"], Response::HTTP_BAD_REQUEST);
            }
            $parsed[] = $validated;
        }

        return $this->json($this->workerCapabilities->updateLiveness($parsed));
    }

    /**
     * @return array{workerType: string, instanceId: string, status: WorkerLivenessStatus, lastSeenAt: \DateTimeImmutable}|string
     *         Normalized entry, or an error-message string on validation failure.
     */
    private function validateLivenessEntry(mixed $entry): array|string
    {
        if (! is_array($entry)) {
            return 'must be an object';
        }
        if (! isset($entry['workerType']) || ! is_string($entry['workerType']) || $entry['workerType'] === '') {
            return 'workerType must be a non-empty string';
        }
        if (! isset($entry['instanceId']) || ! is_string($entry['instanceId']) || $entry['instanceId'] === '') {
            return 'instanceId must be a non-empty string';
        }
        // Wire contract allows ONLY alive/disconnected (never "unknown" —
        // that value is DB-only, reserved for seed rows, see the migration).
        $status = is_string($entry['status'] ?? null) ? WorkerLivenessStatus::tryFrom($entry['status']) : null;
        if ($status === null || $status === WorkerLivenessStatus::Unknown) {
            return 'status must be "alive" or "disconnected"';
        }
        if (! isset($entry['lastSeenAt']) || ! is_string($entry['lastSeenAt']) || $entry['lastSeenAt'] === '') {
            return 'lastSeenAt must be a non-empty ISO-8601 string';
        }

        try {
            $lastSeenAt = new \DateTimeImmutable($entry['lastSeenAt']);
        } catch (\Exception) {
            return 'lastSeenAt must be a valid ISO-8601 timestamp';
        }
        // Shape-validated, then dropped — see the accept-and-ignore note above.
        if (array_key_exists('metrics', $entry) && $entry['metrics'] !== null) {
            if (! is_array($entry['metrics'])) {
                return 'metrics must be an object or null';
            }
            foreach (['cpu', 'mem', 'load'] as $field) {
                $value = $entry['metrics'][$field] ?? null;
                if ($value !== null && ! is_int($value) && ! is_float($value)) {
                    return "metrics.{$field} must be numeric or null";
                }
            }
        }

        return [
            'workerType' => $entry['workerType'],
            'instanceId' => $entry['instanceId'],
            'status'     => $status,
            'lastSeenAt' => $lastSeenAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\ConversionRepository;
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
}

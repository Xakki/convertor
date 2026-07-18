<?php

declare(strict_types=1);

namespace App\Controller\Api;

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
 */
#[Route('/api/v1/internal/worker')]
final class InternalWorkerController extends AbstractController
{
    public function __construct(
        private readonly WorkerStreamGateway $gateway,
        private readonly ConversionResultPersister $persister,
        private readonly S3Storage $s3,
        private readonly ResultKeyBuilder $keyBuilder,
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

        $error        = is_array($body)        && isset($body['error']) ? (string) $body['error'] : 'Worker reported failure';
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
}

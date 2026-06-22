<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Queue\ConversionResultPersister;
use App\Service\Storage\S3Storage;
use App\Service\Worker\WorkerStreamGateway;
use AsyncAws\S3\Exception\NoSuchKeyException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Universal worker pull-API — HTTP gateway over KeyDB Streams.
 *
 * Off-server workers (e.g. AI worker on home WSL+GPU) poll this API every
 * ~10 s to claim jobs, download input files, and post results/failures back.
 * Auth: static bearer token (WORKER_API_TOKEN) via WorkerAuthenticator on the
 * `worker_api` firewall — no per-action checks needed here.
 *
 * Contract and rationale: .claude/kanban/progress/validate-ai-worker.md
 */
#[Route('/api/v1/worker')]
final class WorkerController extends AbstractController
{
    /** Allowed stream types — must match conv.<type> keys in messenger.yaml. */
    private const ALLOWED_TYPES = ['ai', 'document', 'image', 'audio', 'video', 'data'];

    public function __construct(
        private readonly WorkerStreamGateway $gateway,
        private readonly ConversionResultPersister $persister,
        private readonly S3Storage $s3,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(S3_PREFIX)%')]
        private readonly string $s3Prefix,
    ) {
    }

    /**
     * POST /api/v1/worker/claim
     * Body: {"type":"ai", "consumer":"<id>"}
     * 204 — no job available; 200 — job claimed.
     */
    #[Route('/claim', methods: ['POST'])]
    public function claim(Request $request): JsonResponse|Response
    {
        $body     = json_decode((string) $request->getContent(), true, 512, 0);
        $type     = is_array($body) && isset($body['type']) ? (string) $body['type'] : '';
        $consumer = is_array($body) && isset($body['consumer']) ? (string) $body['consumer'] : '';

        if ($type === '' || $consumer === '') {
            return $this->json(['error' => '"type" and "consumer" are required'], Response::HTTP_BAD_REQUEST);
        }

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->json(
                ['error' => sprintf('Unknown type "%s". Allowed: %s', $type, implode(', ', self::ALLOWED_TYPES))],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $job = $this->gateway->claim($type, $consumer);

        if ($job === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->json($job);
    }

    /**
     * GET /api/v1/worker/jobs/{jobId}/input
     * Streams the raw input file from S3 to the worker.
     */
    #[Route('/jobs/{jobId}/input', methods: ['GET'], requirements: ['jobId' => '[0-9]+-[0-9]+'])]
    public function input(string $jobId): JsonResponse|StreamedResponse
    {
        $meta = $this->gateway->getJobMeta($jobId);
        if ($meta === null) {
            return $this->json(['error' => 'Job not found or already completed'], Response::HTTP_NOT_FOUND);
        }

        try {
            return $this->s3->streamFromBucket($meta['inputBucket'], $meta['inputKey']);
        } catch (NoSuchKeyException) {
            return $this->json(['error' => 'Input file not found in storage'], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('S3 error fetching input file', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to fetch input file'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/worker/jobs/{jobId}/result
     * Multipart: field `file` = result file.
     * Uploads to S3 results, marks Conversion completed, XACKs the stream entry.
     */
    #[Route('/jobs/{jobId}/result', methods: ['POST'], requirements: ['jobId' => '[0-9]+-[0-9]+'])]
    public function result(string $jobId, Request $request): JsonResponse
    {
        $meta = $this->gateway->getJobMeta($jobId);
        if ($meta === null) {
            return $this->json(['error' => 'Job not found or already completed'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['error' => '"file" field is required'], Response::HTTP_BAD_REQUEST);
        }

        $conversionId = $meta['conversionId'];
        $resultKey    = $this->buildResultKey($conversionId, $meta['targetFormat']);
        $mimeType     = $file->getMimeType() ?? 'application/octet-stream';
        $rawSize      = $file->getSize();
        $size         = is_int($rawSize) ? $rawSize : 0;

        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            $this->logger->error('Cannot open result file for reading', ['jobId' => $jobId]);

            return $this->json(['error' => 'Cannot read uploaded file'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $this->s3->putObject($this->s3->resultsBucket(), $resultKey, $stream, $mimeType);
        } finally {
            fclose($stream);
        }

        $this->persister->persist([
            'conversionId' => $conversionId,
            'state'        => 'completed',
            'outputBucket' => $this->s3->resultsBucket(),
            'outputKey'    => $resultKey,
            'outputMime'   => $mimeType,
            'outputSize'   => $size,
            'processingMs' => null,
        ]);

        $this->gateway->ack($meta['stream'], $jobId);

        return $this->json(['ok' => true]);
    }

    /**
     * POST /api/v1/worker/jobs/{jobId}/fail
     * Body: {"error":"<reason>"}
     * Marks Conversion failed, refunds quota (via ConversionResultPersister), XACKs.
     *
     * XACKing on fail is intentional and consistent with the Python worker DLQ path:
     * the worker has given up, so the entry must leave the PEL. Transient crashes
     * are handled by XAUTOCLAIM reclaim in WorkerStreamGateway::reclaimStale().
     */
    #[Route('/jobs/{jobId}/fail', methods: ['POST'], requirements: ['jobId' => '[0-9]+-[0-9]+'])]
    public function fail(string $jobId, Request $request): JsonResponse
    {
        $meta = $this->gateway->getJobMeta($jobId);
        if ($meta === null) {
            return $this->json(['error' => 'Job not found or already completed'], Response::HTTP_NOT_FOUND);
        }

        $body  = json_decode((string) $request->getContent(), true, 512, 0);
        $error = is_array($body) && isset($body['error']) ? (string) $body['error'] : 'Worker reported failure';
        $error = mb_substr($error, 0, 500);

        $this->persister->persist([
            'conversionId' => $meta['conversionId'],
            'state'        => 'failed',
            'error'        => $error,
            'processingMs' => null,
        ]);

        $this->gateway->ack($meta['stream'], $jobId);

        return $this->json(['ok' => true]);
    }

    /**
     * S3 object key for a conversion result — must match the Python worker format:
     * {S3_PREFIX}results/{Y}/{m-d}/{id}.{ext}
     *
     * $targetFormat is sanitized to [a-z0-9]+ before use in the key (defense
     * against path-injection or unexpected characters from the stream payload).
     */
    private function buildResultKey(int $conversionId, string $targetFormat): string
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($targetFormat)) ?: 'bin';

        return $this->s3Prefix
            . 'results/'
            . (new \DateTimeImmutable())->format('Y/m-d')
            . '/' . $conversionId . '.' . $ext;
    }
}

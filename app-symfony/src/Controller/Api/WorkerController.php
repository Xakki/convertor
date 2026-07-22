<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use App\Service\Queue\ConversionResultPersister;
use App\Service\Storage\S3Storage;
use App\Service\Worker\ResultKeyBuilder;
use App\Service\Worker\WorkerStreamGateway;
use AsyncAws\S3\Exception\NoSuchKeyException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Universal worker pull-API — HTTP gateway over KeyDB Streams.
 *
 * Off-server workers download input files and post large results back here.
 * Auth: static bearer token (WORKER_API_TOKEN) via WorkerAuthenticator on the
 * `worker_api` firewall — no per-action checks needed here.
 *
 * XACK-владение перенесено в WS-Gateway (§5 spec): эндпоинты ЗДЕСЬ больше НЕ
 * ацкают. Чтение Stream (claim) и inline-result/fail ушли в gateway +
 * InternalWorkerController. Остаётся только large-multipart result и стриминг
 * входного файла.
 *
 * Contract and rationale: .claude/kanban/progress/validate-ai-worker.md
 */
#[Route('/api/v1/worker')]
final class WorkerController extends AbstractController
{
    /**
     * Зарезервированный instanceId seed-строк (registry-03,
     * migrations/Version20260722150301.php). Реальный воркер никогда не
     * должен на него попасть — иначе его регистрация будет неотличима от
     * seed-строки и потенциально снесена down()-миграцией seed'а (та удаляет
     * ряды строго по этому литералу). Литерал ЗАДУБЛИРОВАН в миграции
     * намеренно — миграция обязана оставаться самодостаточной и не зависеть
     * от кода приложения; при переименовании синхронизировать оба места
     * вручную.
     */
    private const RESERVED_SEED_INSTANCE_ID = '__seed__';

    public function __construct(
        private readonly WorkerStreamGateway $gateway,
        private readonly ConversionResultPersister $persister,
        private readonly S3Storage $s3,
        private readonly ResultKeyBuilder $keyBuilder,
        private readonly LoggerInterface $logger,
        private readonly WorkerCapabilityRepository $workerCapabilityRepository,
        private readonly ConversionRegistry $registry,
    ) {
    }

    /**
     * POST /api/v1/worker/register
     * Регистрирует возможности воркера; upsert по составному ключу (workerType,
     * instanceId), инвалидирует кеш матрицы.
     */
    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (! is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $err = $this->validateRegisterPayload($data);
        if ($err !== null) {
            return $this->json(['error' => $err], Response::HTTP_BAD_REQUEST);
        }

        $this->workerCapabilityRepository->upsert((string) $data['workerType'], (string) $data['instanceId'], $data);
        $this->registry->invalidateMatrix();

        return $this->json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateRegisterPayload(array $data): ?string
    {
        if (! isset($data['workerType']) || ! is_string($data['workerType']) || $data['workerType'] === '') {
            return 'workerType must be a non-empty string';
        }
        if (
            ! isset($data['instanceId'])
            || ! is_string($data['instanceId'])
            || $data['instanceId'] === ''
            || strlen($data['instanceId']) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $data['instanceId']) !== 1
        ) {
            return 'instanceId must be a non-empty string (max 128 chars, [A-Za-z0-9._:-]+)';
        }
        if ($data['instanceId'] === self::RESERVED_SEED_INSTANCE_ID) {
            return 'instanceId "' . self::RESERVED_SEED_INSTANCE_ID . '" is reserved for the seed migration (registry-03)';
        }
        if (! array_key_exists('isAi', $data) || ! is_bool($data['isAi'])) {
            return 'isAi must be a boolean';
        }
        if (! isset($data['streams']) || ! is_array($data['streams'])) {
            return 'streams must be an array';
        }
        if (! isset($data['routingKeys']) || ! is_array($data['routingKeys'])) {
            return 'routingKeys must be an array';
        }
        if (! isset($data['matrix']) || ! is_array($data['matrix'])) {
            return 'matrix must be an object';
        }

        return null;
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
     * Multipart: field `file` = result file (large-result path, payload минует gateway);
     * опциональное form-поле `processingMs` (int) — тот же ключ, что и на inline-пути.
     * Загружает в S3 results и помечает Conversion completed. НЕ ацкает — XACK
     * делает gateway на доверии к WS-сообщению {type:"result", jobId, resultKey}.
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
        $resultKey    = $this->keyBuilder->build($conversionId, $meta['targetFormat']);
        $mimeType     = $file->getMimeType() ?? 'application/octet-stream';
        $rawSize      = $file->getSize();
        $size         = is_int($rawSize) ? $rawSize : 0;
        // Доп. form-поле (не файл) рядом с `file` — тот же ключ processingMs, что и
        // на inline-пути (InternalWorkerController::result), контракт единый.
        $rawProcessingMs = $request->request->get('processingMs');
        $processingMs    = $rawProcessingMs !== null ? (int) $rawProcessingMs : null;

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
            'processingMs' => $processingMs,
        ]);

        return $this->json(['ok' => true]);
    }
}

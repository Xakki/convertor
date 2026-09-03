<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\HostTelemetrySnapshot;
use App\Repository\HostTelemetrySnapshotRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/internal/host-telemetry')]
final class HostTelemetryController extends AbstractController
{
    private const MAX_BODY_BYTES = 65536;
    private const MAX_WORKERS    = 32;
    private const MAX_INTEGER    = 9223372036854775807;

    public function __construct(private readonly HostTelemetrySnapshotRepository $snapshots)
    {
    }

    #[Route('', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return $this->json(['error' => 'telemetry payload is too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $body = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        if (! is_array($body)) {
            return $this->json(['error' => 'invalid telemetry contract'], Response::HTTP_BAD_REQUEST);
        }
        $host = $body['host'] ?? null;
        if (! is_string($host) || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) !== 1) {
            return $this->json(['error' => 'host must be a lowercase DNS name'], Response::HTTP_BAD_REQUEST);
        }
        if ($this->integer($body['contractVersion'] ?? null, 1, 1) === null) {
            return $this->json(['error' => 'invalid telemetry contract'], Response::HTTP_BAD_REQUEST);
        }
        $observed = $this->number($body['observedAt'] ?? null);
        $now      = microtime(true);
        if ($observed === null || $observed < 0 || $observed > $now || $observed < $now - 1200) {
            return $this->json(['error' => 'snapshot is outside freshness window'], Response::HTTP_BAD_REQUEST);
        }
        foreach (['cpuCount', 'memTotalBytes', 'memAvailableBytes', 'diskTotalBytes', 'diskUsedBytes'] as $field) {
            if (($body[$field] ?? null) !== null && $this->integer($body[$field], 0, self::MAX_INTEGER) === null) {
                return $this->json(['error' => 'invalid telemetry value'], Response::HTTP_BAD_REQUEST);
            }
        }
        if (($body['load1'] ?? null) !== null && ($this->number($body['load1']) === null || $this->number($body['load1']) < 0)) {
            return $this->json(['error' => 'invalid telemetry value'], Response::HTTP_BAD_REQUEST);
        }
        $workers = $body['workers'] ?? [];
        if (! is_array($workers) || count($workers) > self::MAX_WORKERS) {
            return $this->json(['error' => 'invalid worker telemetry'], Response::HTTP_BAD_REQUEST);
        }
        $sanitizedWorkers = [];
        foreach ($workers as $worker => $metrics) {
            if (! is_string($worker) || strlen($worker) > 128 || $worker === '' || ! is_array($metrics)) {
                return $this->json(['error' => 'invalid worker telemetry'], Response::HTTP_BAD_REQUEST);
            }
            $cpu    = $this->integer($metrics['cpuUsageUsec'] ?? null, 0, self::MAX_INTEGER);
            $memory = $this->integer($metrics['memoryBytes'] ?? null, 0, self::MAX_INTEGER);
            if (($metrics['cpuUsageUsec'] ?? null) !== null && $cpu === null || ($metrics['memoryBytes'] ?? null) !== null && $memory === null) {
                return $this->json(['error' => 'invalid worker telemetry'], Response::HTTP_BAD_REQUEST);
            }
            $sanitizedWorkers[$worker] = ['cpuUsageUsec' => $cpu, 'memoryBytes' => $memory];
        }
        $body['workers'] = $sanitizedWorkers;
        $this->snapshots->save(new HostTelemetrySnapshot($host, $body, (new \DateTimeImmutable())->setTimestamp((int) $observed), new \DateTimeImmutable()));

        return $this->json(['ok' => true]);
    }

    private function number(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && preg_match('/^\d+(?:\.\d+)?$/', $value) === 1)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function integer(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            return $value >= $min && $value <= $max ? $value : null;
        }
        if (! is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/', $value) !== 1) {
            return null;
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $normalized === false ? null : $normalized;
    }
}

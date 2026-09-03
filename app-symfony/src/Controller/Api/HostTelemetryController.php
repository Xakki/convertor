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
    public function __construct(private readonly HostTelemetrySnapshotRepository $snapshots)
    {
    }

    #[Route('', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $contentLength = $request->headers->get('Content-Length');
        if (is_numeric($contentLength) && (int) $contentLength > 65536) {
            return $this->json(['error' => 'telemetry payload is too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $body = json_decode($request->getContent(), true);
        $host = is_array($body) ? ($body['host'] ?? null) : null;
        if (! is_string($host) || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) !== 1) {
            return $this->json(['error' => 'host must be a lowercase DNS name'], Response::HTTP_BAD_REQUEST);
        }
        if (! is_array($body) || (int) ($body['contractVersion'] ?? 0) !== 1 || ! is_numeric($body['observedAt'] ?? null)) {
            return $this->json(['error' => 'invalid telemetry contract'], Response::HTTP_BAD_REQUEST);
        }
        $workers = $body['workers'] ?? [];
        if (! is_array($workers) || count($workers) > 32) {
            return $this->json(['error' => 'invalid worker telemetry'], Response::HTTP_BAD_REQUEST);
        }
        $sanitizedWorkers = [];
        foreach ($workers as $worker => $metrics) {
            if (! is_string($worker) || strlen($worker) > 128 || ! is_array($metrics)) {
                return $this->json(['error' => 'invalid worker telemetry'], Response::HTTP_BAD_REQUEST);
            }
            $cpu    = $metrics['cpuUsageUsec'] ?? null;
            $memory = $metrics['memoryBytes']  ?? null;
            if (($cpu !== null && ! is_numeric($cpu)) || ($memory !== null && ! is_numeric($memory))) {
                return $this->json(['error' => 'invalid worker telemetry'], Response::HTTP_BAD_REQUEST);
            }
            $sanitizedWorkers[$worker] = [
                'cpuUsageUsec' => $cpu    === null ? null : (int) $cpu,
                'memoryBytes'  => $memory === null ? null : (int) $memory,
            ];
        }
        $body['workers'] = $sanitizedWorkers;
        $observed        = (float) $body['observedAt'];
        $now             = microtime(true);
        if ($observed > $now + 60 || $observed < $now - 1200) {
            return $this->json(['error' => 'snapshot is outside freshness window'], Response::HTTP_BAD_REQUEST);
        }
        $this->snapshots->save(new HostTelemetrySnapshot($host, $body, (new \DateTimeImmutable())->setTimestamp((int) $observed), new \DateTimeImmutable()));

        return $this->json(['ok' => true]);
    }
}

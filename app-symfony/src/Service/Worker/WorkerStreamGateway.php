<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Queue\RedisConnectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Gateway over KeyDB Streams for the universal worker pull-API.
 *
 * Stream `conv.<type>` uses the Symfony Messenger double-encoded envelope
 * format (§2 of docs/queue-contract.md): field `message` is a JSON string
 * whose `body` value is itself a JSON string containing the job payload.
 *
 * jobId → conversionId mapping is kept in a Redis key `worker:job:{jobId}`
 * (TTL 24 h) so downstream endpoints can look up job meta without PEL scans.
 */
class WorkerStreamGateway
{
    private const GROUP         = 'convertor';
    private const JOB_META_TTL  = 86400;           // 24 h
    private const STALE_IDLE_MS = 300_000;         // 5 min before XAUTOCLAIM reclaims

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Claim one pending job from the `conv.<type>` stream.
     *
     * XAUTOCLAIM is tried first to re-queue entries idle longer than 5 minutes
     * (handles crashed consumers). Falls through to XREADGROUP for new entries.
     * Stores job meta in Redis for later resolution by the other endpoints.
     *
     * @return array{jobId:string, conversionId:int, sourceFormat:string, targetFormat:string}|null
     */
    public function claim(string $type, string $consumer): ?array
    {
        $redis  = $this->redisFactory->create();
        $stream = 'conv.' . $type;

        $this->ensureGroup($redis, $stream);

        $entry = $this->reclaimStale($redis, $stream, $consumer)
            ?? $this->readNew($redis, $stream, $consumer);

        if ($entry === null) {
            return null;
        }

        [$jobId, $job] = $entry;

        if ((int) ($job['conversionId'] ?? 0) <= 0) {
            $redis->xAck($stream, self::GROUP, [$jobId]);
            $this->logger->error('Stream entry has no positive conversionId — dropped', [
                'stream'       => $stream,
                'jobId'        => $jobId,
                'conversionId' => $job['conversionId'] ?? null,
                'hint'         => 'XRANGE ' . $stream . ' ' . $jobId . ' ' . $jobId . ' recovers raw entry while stream is live',
            ]);

            return null;
        }

        $meta = json_encode([
            'conversionId' => $job['conversionId'] ?? 0,
            'inputBucket'  => $job['inputBucket']  ?? '',
            'inputKey'     => $job['inputKey']     ?? '',
            'stream'       => $stream,
            'targetFormat' => $job['targetFormat'] ?? '',
        ], JSON_THROW_ON_ERROR);

        $redis->setex('worker:job:' . $jobId, self::JOB_META_TTL, $meta);

        return [
            'jobId'        => $jobId,
            'conversionId' => (int) ($job['conversionId'] ?? 0),
            'sourceFormat' => (string) ($job['sourceFormat'] ?? ''),
            'targetFormat' => (string) ($job['targetFormat'] ?? ''),
        ];
    }

    /**
     * Retrieve stored job meta by stream message ID.
     *
     * @return array{conversionId:int, inputBucket:string, inputKey:string, stream:string, targetFormat:string}|null
     */
    public function getJobMeta(string $jobId): ?array
    {
        $redis = $this->redisFactory->create();
        $raw   = $redis->get('worker:job:' . $jobId);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            return null;
        }

        return [
            'conversionId' => (int) ($data['conversionId'] ?? 0),
            'inputBucket'  => (string) ($data['inputBucket'] ?? ''),
            'inputKey'     => (string) ($data['inputKey'] ?? ''),
            'stream'       => (string) ($data['stream'] ?? ''),
            'targetFormat' => (string) ($data['targetFormat'] ?? ''),
        ];
    }

    /**
     * XACK the stream entry and delete the job-meta key.
     * Called after a successful result upload or a final failure report.
     */
    public function ack(string $stream, string $jobId): void
    {
        $redis = $this->redisFactory->create();
        $redis->xAck($stream, self::GROUP, [$jobId]);
        $redis->del('worker:job:' . $jobId);
    }

    // -------------------------------------------------------------------------

    private function ensureGroup(\Redis $redis, string $stream): void
    {
        try {
            $redis->xGroup('CREATE', $stream, self::GROUP, '0', true);
        } catch (\RedisException $e) {
            // BUSYGROUP = group already exists; any other error is fatal.
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * XAUTOCLAIM: try to reclaim one entry idle longer than STALE_IDLE_MS.
     *
     * Returns [jobId, job-body] or null when nothing to reclaim or on error.
     *
     * @return array{string, array<string, mixed>}|null
     */
    private function reclaimStale(\Redis $redis, string $stream, string $consumer): ?array
    {
        try {
            // Returns [nextId, [msgId => [field => value], ...]] or false.
            /** @var mixed $result */
            $result = $redis->xautoclaim($stream, self::GROUP, $consumer, self::STALE_IDLE_MS, '0-0', 1);
        } catch (\Throwable $e) {
            $this->logger->warning('XAUTOCLAIM failed, skipping stale reclaim', [
                'stream' => $stream,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($result) || ! isset($result[1]) || ! is_array($result[1]) || $result[1] === []) {
            return null;
        }

        /** @var array<string, array<string, string>> $entries */
        $entries = $result[1];
        $jobId   = (string) array_key_first($entries);

        /** @var array<string, string> $fields */
        $fields = $entries[$jobId];

        $job = $this->parseOrAck($redis, $stream, $jobId, $fields);

        return $job !== null ? [$jobId, $job] : null;
    }

    /**
     * XREADGROUP COUNT 1 (non-blocking) for a new, undelivered entry.
     *
     * @return array{string, array<string, mixed>}|null
     */
    private function readNew(\Redis $redis, string $stream, string $consumer): ?array
    {
        $messages = $redis->xReadGroup(self::GROUP, $consumer, [$stream => '>'], 1);

        if (! is_array($messages) || ! isset($messages[$stream]) || ! is_array($messages[$stream]) || $messages[$stream] === []) {
            return null;
        }

        $entries = $messages[$stream];
        $jobId   = (string) array_key_first($entries);

        /** @var array<string, string> $fields */
        $fields = $entries[$jobId];

        $job = $this->parseOrAck($redis, $stream, $jobId, $fields);

        return $job !== null ? [$jobId, $job] : null;
    }

    /**
     * Parse stream fields into a job array; on failure XACK the entry (drop it
     * as a poison message) and return null to prevent infinite reclaim loops.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>|null
     */
    private function parseOrAck(\Redis $redis, string $stream, string $jobId, array $fields): ?array
    {
        try {
            return $this->parseMessage($fields);
        } catch (\Throwable $e) {
            $redis->xAck($stream, self::GROUP, [$jobId]);
            $this->logger->error('Poisoned stream entry — dropped', [
                'stream' => $stream,
                'jobId'  => $jobId,
                'error'  => $e->getMessage(),
                'hint'   => 'XRANGE ' . $stream . ' ' . $jobId . ' ' . $jobId . ' recovers raw entry while stream is live',
            ]);

            return null;
        }
    }

    /**
     * Decode a Symfony Messenger stream entry (§2 of docs/queue-contract.md).
     *
     * The `message` field is a JSON envelope string; its `body` value is
     * itself a JSON string containing the job payload — hence "double-decode".
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function parseMessage(array $fields): array
    {
        $raw = $fields['message'] ?? '';
        if ($raw === '') {
            throw new \RuntimeException('Stream entry missing "message" field');
        }

        $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($envelope)) {
            throw new \RuntimeException('Stream entry "message" is not a JSON object');
        }

        $body = $envelope['body'] ?? null;
        if (! is_string($body)) {
            throw new \RuntimeException('Messenger envelope missing string "body"');
        }

        $job = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($job)) {
            throw new \RuntimeException('Messenger envelope body is not a JSON object');
        }

        return $job;
    }
}

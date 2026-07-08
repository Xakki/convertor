<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Queue\RedisConnectionFactory;

/**
 * Read-only доступ к мете задачи для worker pull-API.
 *
 * jobId → conversionId маппинг живёт в Redis-ключе `worker:job:{jobId}` (пишет и
 * удаляет его теперь WS-Gateway: SETEX при dispatch, DEL при XACK). Здесь только
 * чтение — downstream-эндпоинты резолвят мету без сканирования PEL.
 *
 * Чтение Stream (XREADGROUP/XAUTOCLAIM) и XACK перенесены в WS-Gateway (§5 spec)
 * — Symfony больше не трогает Stream.
 */
class WorkerStreamGateway
{
    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
    ) {
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
}

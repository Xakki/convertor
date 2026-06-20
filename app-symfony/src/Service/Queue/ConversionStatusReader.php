<?php

declare(strict_types=1);

namespace App\Service\Queue;

/**
 * Reads the live conversion status hash `conv:status:{id}` written by the
 * Python workers (contract §4). Returns null when the hash is gone (TTL 24h
 * expired) so callers can fall back to MariaDB.
 */
final class ConversionStatusReader
{
    public function __construct(private readonly RedisConnectionFactory $factory)
    {
    }

    /**
     * @return array<string, string>|null
     */
    public function read(int $conversionId): ?array
    {
        $data = $this->factory->create()->hGetAll('conv:status:' . $conversionId);

        if (! is_array($data) || $data === []) {
            return null;
        }

        /** @var array<string, string> $data */
        return $data;
    }
}

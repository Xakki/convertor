<?php

declare(strict_types=1);

namespace App\Service\Queue;

/**
 * Builds a raw phpredis connection from the canonical REDIS_DSN
 * (`redis://[user:pass@]host:port?dbindex=N`). The db is taken from the
 * `dbindex` query param to stay consistent with the Messenger transport — the
 * DSN *path* there means stream/group, not the db. See docs/queue-contract.md §1.
 */
// Non-final: the result-consumer test doubles this to inject a mock \Redis.
class RedisConnectionFactory
{
    private ?\Redis $connection = null;

    public function __construct(private readonly string $dsn) {}

    public function create(): \Redis
    {
        if ($this->connection instanceof \Redis) {
            return $this->connection;
        }

        $parts = parse_url($this->dsn);
        if ($parts === false) {
            throw new \RuntimeException('Invalid REDIS_DSN: ' . $this->dsn);
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? (int) $parts['port'] : 6379;

        $db = 0;
        if (isset($parts['query'])) {
            $query = [];
            parse_str($parts['query'], $query);
            $db = isset($query['dbindex']) ? (int) $query['dbindex'] : 0;
        }

        $redis = new \Redis();
        $redis->connect($host, $port);

        $pass = $parts['pass'] ?? '';
        if ($pass !== '') {
            $user = $parts['user'] ?? '';
            $redis->auth($user !== '' ? [$user, $pass] : $pass);
        }

        $redis->select($db);

        $this->connection = $redis;

        return $redis;
    }
}

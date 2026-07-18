<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Immutable job contract dispatched onto the Redis-Streams transport and
 * consumed by the Python workers. Field names are camelCase end-to-end and
 * pinned in docs/queue-contract.md — do NOT rename without updating that doc
 * and the worker-side decoders.
 */
class ConversionMessage
{
    /**
     * @param array<string, mixed> $options
     * @param string               $attempt requeue-attempt-generation-marker (cross-zone
     *                                       contract) — Conversion.attempt at dispatch time,
     *                                       stringified int (gateway/worker side already
     *                                       treats it as a numeric string, see
     *                                       workers/gateway/keydb.py `write_job_meta`).
     *                                       "0" on the initial submit.
     */
    public function __construct(
        public readonly int $conversionId,
        public readonly string $inputBucket,
        public readonly string $inputKey,
        public readonly string $originalFilename,
        public readonly string $sourceFormat,
        public readonly string $targetFormat,
        public readonly string $category,
        public readonly bool $isAi = false,
        public readonly array $options = [],
        public readonly string $attempt = '0',
    ) {
    }
}

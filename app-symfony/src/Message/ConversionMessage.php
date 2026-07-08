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
    ) {
    }
}

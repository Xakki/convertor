<?php

declare(strict_types=1);

namespace App\Message;

class ConversionMessage
{
    public function __construct(
        public readonly int $conversionId,
        public readonly string $inputPath,
        public readonly string $outputFormat,
        public readonly string $category,
    ) {}
}

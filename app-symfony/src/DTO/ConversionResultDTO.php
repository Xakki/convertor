<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ConversionStatus;

class ConversionResultDTO
{
    public function __construct(
        public readonly int $conversionId,
        public readonly ConversionStatus $status,
        public readonly ?string $outputPath = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $chainId = null,
        public readonly ?int $sequence = null,
        public readonly ?string $finalToFormat = null,
        public readonly ?int $chainLength = null,
        public readonly ?string $fromFormat = null,
        public readonly ?string $toFormat = null,
    ) {
    }
}

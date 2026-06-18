<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class ConversionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $fromFormat,

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $toFormat,

        #[Assert\NotBlank]
        public readonly string $filePath,
    ) {}
}

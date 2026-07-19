<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Входной контракт Controller → ConversionManager: описывает запрос на
 * конвертацию файла ДО постановки в очередь (пользователь, загруженный файл,
 * целевой формат и флаги ocr/privileged).
 */
class ConversionRequestDTO
{
    public function __construct(
        public readonly User $user,
        public readonly UploadedFile $file,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $toFormat,
        public readonly bool $ocr = false,
        public readonly bool $privileged = true,
    ) {
    }
}

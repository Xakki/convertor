<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class TelegramAuthDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $id,

        #[Assert\NotBlank]
        public readonly string $firstName,

        public readonly ?string $lastName = null,
        public readonly ?string $username = null,
        public readonly ?string $photoUrl = null,

        #[Assert\NotBlank]
        public readonly ?int $authDate = null,

        #[Assert\NotBlank]
        public readonly ?string $hash = null,
    ) {}
}

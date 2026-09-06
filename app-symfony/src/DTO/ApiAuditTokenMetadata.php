<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ApiAuditTokenMetadata
{
    public function __construct(
        public string $label,
        public string $mask,
        public ApiAuditIdentityType $identityType,
    ) {
        if ($this->identityType !== ApiAuditIdentityType::PERSONAL_TOKEN
            || $this->label === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $this->label) !== 1
            || str_starts_with($this->label, 'cnv_')
            || preg_match('/^cnv_\*{8}$/D', $this->mask) !== 1) {
            throw new \InvalidArgumentException('Invalid personal token audit metadata.');
        }
    }
}

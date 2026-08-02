<?php

declare(strict_types=1);

namespace App\Exception;

final class UnknownTopUpPackException extends \InvalidArgumentException
{
    public function __construct(string $packId)
    {
        parent::__construct(sprintf('Unknown top-up pack: %s.', $packId));
    }
}

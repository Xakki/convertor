<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidTopUpAmountException extends \InvalidArgumentException
{
    public static function belowMinimum(int $minStars): self
    {
        return new self(sprintf('Minimum top-up is %d Stars.', $minStars));
    }
}

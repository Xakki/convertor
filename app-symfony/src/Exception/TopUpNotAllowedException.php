<?php

declare(strict_types=1);

namespace App\Exception;

final class TopUpNotAllowedException extends \RuntimeException
{
    public static function guestUser(): self
    {
        return new self('Top-up is available for registered users only.');
    }

    public static function noTelegramLink(): self
    {
        return new self('Link your Telegram account before topping up via the bot.');
    }
}

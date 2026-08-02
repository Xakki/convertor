<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Бросается при попытке списать с prepaid-баланса больше, чем доступно.
 * HTTP-маппинг (429 + insufficient_balance) — на уровне контроллера/слушателя.
 */
final class InsufficientBalanceException extends \RuntimeException
{
}

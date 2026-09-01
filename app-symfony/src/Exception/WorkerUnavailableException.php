<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Бросается, когда требуемая возможность воркера для пары (from→to) недоступна.
 * API-задачи требуют свежих активных регистраций: каждая свежая строка должна
 * соответствовать `ApiCapabilityContract`, а общее множество валидированных
 * моделей должно быть непустым. Для остальных типов действует durable-семантика:
 * достаточно сохранённой регистрации возможности без требования liveness.
 *
 * Контроллер отдаёт HTTP 503 с телом
 * `{error:"worker_unavailable", message:"…"}`. В отличие от
 * {@see ConversionDisabledException}, это инфраструктурная недоступность
 * требуемой возможности, а не ручное отключение валидной пары администратором.
 */
final class WorkerUnavailableException extends \RuntimeException
{
}

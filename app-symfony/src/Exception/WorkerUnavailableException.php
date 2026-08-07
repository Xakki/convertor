<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Бросается когда для нужного workerType пары (from→to) в `worker_capabilities`
 * НЕТ НИ ОДНОЙ строки — воркер такого типа никогда не регистрировался
 * ({@see \App\Repository\WorkerCapabilityRepository::existsForWorkerType()}).
 * Контроллер ловит и отдаёт HTTP 503 (временная недоступность сервиса, не
 * ошибка клиента) с телом `{error:"worker_unavailable", message:"…"}`.
 *
 * Отличается от {@see ConversionDisabledException}: там пара валидна и воркер
 * существует, но админ временно выключил её вручную; здесь причина
 * инфраструктурная — воркер этого типа никогда не был развёрнут. Если строка
 * ЕСТЬ (даже offline/disconnected) — это НЕ этот гейт, задача принимается и
 * ставится в очередь; протухание никем не взятой задачи закрывает отдельный
 * эндпоинт `POST /api/v1/internal/worker/expire` (CNV-71-03).
 */
final class WorkerUnavailableException extends \RuntimeException
{
}

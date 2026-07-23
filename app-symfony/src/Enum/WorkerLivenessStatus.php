<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Liveness-статус инстанса воркера (registry-06) — ОТДЕЛЬНЫЙ факт от
 * `WorkerCapability::lastSeen`: последний обозначает "когда видели живым",
 * этот — "по последним данным, соединение живо или отвалилось". НЕ входит в
 * критерии выбора воркера для маршрутизации
 * ({@see \App\Service\Conversion\ConversionRegistry::buildMatrixFromCapabilities()}
 * его не читает) — чистый монитор-сигнал для будущей admin-страницы.
 *
 * `Alive`/`Disconnected` — единственные значения, которые принимает wire-
 * контракт liveness-пуша ({@see \App\Controller\Api\InternalWorkerController::liveness()}).
 * `Unknown` — НЕ приходит по проводу; это DB-only DEFAULT для seed-строк
 * (`instance_id='__seed__'`), которые не являются живым процессом и никогда
 * не получают liveness-пуш — см. `migrations/Version20260722212523.php`.
 */
enum WorkerLivenessStatus: string
{
    case Alive        = 'alive';
    case Disconnected = 'disconnected';
    case Unknown      = 'unknown';
}

<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Liveness-статус инстанса воркера (registry-06) — ОТДЕЛЬНЫЙ факт от
 * `WorkerCapability::lastSeen`: последний обозначает "когда видели живым",
 * этот — "по последним данным, соединение живо или отвалилось". НЕ входит в
 * критерии выбора воркера для маршрутизации — с CNV-71-02
 * {@see \App\Service\Conversion\ConversionRegistry} вообще не читает
 * `worker_capabilities` для построения роутинг-матрицы (та строится из
 * статического каталога) — чистый монитор-сигнал для admin-страницы.
 *
 * `Alive`/`Disconnected` — единственные значения, которые принимает wire-
 * контракт liveness-пуша ({@see \App\Controller\Api\InternalWorkerController::liveness()}).
 * `Unknown` — НЕ приходит по проводу и по факту недостижим через штатный код:
 * колонка `status` имеет DB-DEFAULT `'unknown'` (`migrations/Version20260722212523.php`),
 * но {@see \App\Repository\WorkerCapabilityRepository::upsert()} — ЕДИНСТВЕННЫЙ
 * путь INSERT/UPDATE строки из приложения — на каждом вызове явно пишет
 * `status='alive'`, так что этот DEFAULT никогда не срабатывает в реальном
 * потоке. Исторически он был честным значением для seed-строк
 * (`instance_id='__seed__'`, INSERT которых не указывал `status`), но
 * CNV-71-04 удалил и seed-строки, и все их спец-обработки — case оставлен в
 * enum как безопасный fallback значения БД, не как достижимое runtime-состояние.
 */
enum WorkerLivenessStatus: string
{
    case Alive        = 'alive';
    case Disconnected = 'disconnected';
    case Unknown      = 'unknown';
}

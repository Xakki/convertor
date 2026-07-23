<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * registry-06: `worker_capabilities` получает `status` (alive/disconnected/
 * unknown) — liveness-статус инстанса, ОТДЕЛЬНЫЙ факт от `last_seen`. Причина
 * завести колонку, а не выводить статус из возраста `last_seen`: обрыв WS —
 * мгновенное событие («воркер только что отвалился»), а свежесть `last_seen`
 * — это когда его ПОСЛЕДНИЙ РАЗ видели живым; воркер, пинганувший 30 секунд
 * назад и тут же уронивший сокет, по `last_seen` всё ещё выглядит свежим, но
 * по факту уже не на связи. Один timestamp не может нести оба факта.
 *
 * НЕ добавляет `metrics` — карта registry-06 wire-контракта несёт
 * cpu/mem/load, но ни один текущий потребитель (в т.ч. `[[registry-07-admin-workers-page]]`,
 * который выводит workerType/instanceId/image/version/lastSeen/alive-stale/
 * pair count) их не читает. Груминг-решение: эндпоинт валидирует форму
 * `metrics`, но не персистит — см. `InternalWorkerController::liveness()`.
 * Добавить колонку позже, когда появится реальный потребитель, — одна
 * миграция, не блокирующая эту.
 *
 * Hand-written (не через migrate-diff): тот же повод, что и registry-02
 * (Version20260722142906) — автосгенерированный diff тянет несвязанный дрейф
 * по другим таблицам.
 *
 * Значения:
 *   - `alive` — реальный воркер, только что зарегистрировался или прислал
 *     liveness `status=alive` (см. `WorkerCapabilityRepository::upsert()` —
 *     каждый `register()` безусловно сбрасывает статус в `alive`: реконнект
 *     воркера — это ipso facto живое соединение, даже если до этого он был
 *     помечен `disconnected`).
 *   - `disconnected` — liveness-пуш с `status=disconnected` (gateway увидел
 *     обрыв WS). НЕ убирает пары воркера из активной матрицы маршрутизации —
 *     см. акцептанс-критерий эпика «liveness НЕ гейтит роутинг»; статус —
 *     чистый монитор-сигнал для будущей admin-страницы
 *     (`[[registry-07-admin-workers-page]]`), не входной параметр
 *     {@see \App\Service\Conversion\ConversionRegistry::buildMatrixFromCapabilities()}.
 *   - `unknown` — DEFAULT колонки; честное значение для seed-строк
 *     (`instance_id='__seed__'`, `[[registry-03-seed-migration]]`, INSERT
 *     которых предшествует этой миграции и не знает о колонке `status`) — они
 *     НЕ реальный процесс, никогда не получают liveness-пуш, и `alive` было бы
 *     ложью (подразумевает живое соединение, которого нет), а `disconnected`
 *     — тоже ложью (подразумевает, что соединение БЫЛО и прервалось). Тот же
 *     DEFAULT автоматически даёт `unknown` seed-строкам на КАЖДОМ прогоне
 *     миграций с нуля (registry-03 INSERT выполняется раньше этой ALTER'ы
 *     независимо от окружения — DEFAULT избавляет от необходимости трогать
 *     иммутабельную историческую миграцию).
 *
 * UPDATE ... WHERE instance_id != '__seed__' сразу после ALTER — backfill для
 * УЖЕ существующих НЕ-seed строк (если таковые есть в уже смигрированном
 * окружении): им ALTER дал бы тот же DEFAULT `unknown`, что честно для seed,
 * но нечестно для реальной регистрации — заведомо было хотя бы одно живое
 * register(), так что `alive` точнее отражает факт, чем `unknown`.
 */
final class Version20260722212523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'worker_capabilities: колонка status (liveness alive/disconnected/unknown), registry-06';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE worker_capabilities ADD status VARCHAR(20) NOT NULL DEFAULT 'unknown'");
        $this->addSql("UPDATE worker_capabilities SET status = 'alive' WHERE instance_id != '__seed__'");
    }

    public function down(Schema $schema): void
    {
        // Никакого rollback-guard'а не нужно (в отличие от registry-02's
        // down(), который защищался от коллизии на UNIQUE-индексе): простой
        // VARCHAR без constraint'ов, DROP COLUMN структурно не может упасть.
        // Данные статуса теряются — ожидаемо для отката, как и везде.
        $this->addSql('ALTER TABLE worker_capabilities DROP status');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `worker_capabilities` получает `metrics` (nullable JSON) — cpu/mem/load из
 * gateway'ного liveness-батча ({@see \App\Controller\Api\InternalWorkerController::liveness()},
 * `workers/gateway/liveness.py:_Instance.to_payload()`). Ранее (Version20260722212523)
 * это поле сознательно НЕ персистилось — "accept-and-ignore", т.к. не было
 * потребителя; теперь потребитель есть — admin-страница воркеров
 * ({@see \App\Service\Admin\WorkerStatsProvider}) показывает cpu/mem/load, если
 * воркер их прислал.
 *
 * NULL по умолчанию, а не `{}`: строка могла ни разу не получить liveness-пуш
 * (seed-строка `instance_id='__seed__'`, или живой воркер, только что
 * зарегистрированный, но ещё не пинговавший) — null отличим от "0/0/0", тогда
 * как пустой объект стёр бы это различие.
 *
 * Hand-written (тот же повод, что registry-02/06 — автогенерённый diff тянет
 * несвязанный дрейф по другим таблицам).
 */
final class Version20260723090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'worker_capabilities: nullable колонка metrics (cpu/mem/load из liveness-пуша)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker_capabilities ADD metrics JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker_capabilities DROP metrics');
    }
}

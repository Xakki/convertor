<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `worker_capabilities` получает `host` (nullable VARCHAR) — явный host/node-
 * идентификатор воркера, репортится в register-payload'е (registry-08). До этого
 * не было НИ ОДНОГО явного поля, отличающего физический хост инстанса — только
 * соглашение по именованию WORKER_ID/COMPOSE_PROJECT_NAME
 * (docs/workers-remote-deploy.md), недостаточное для on-server воркеров без
 * пиннинга hostname (см. `workers/common/ws_client.py::_worker_host()`).
 *
 * NULL по умолчанию: существующие строки (seed-строки `instance_id='__seed__'`,
 * и любой воркер, зарегистрировавшийся ДО этой миграции/до апдейта Python-кода)
 * не несут host — не воркер это ломает, просто честный пробел (страница
 * воркеров показывает прочерк, см. templates/admin/workers.html.twig).
 *
 * Hand-written (тот же повод, что registry-02/06/07 — автогенерённый diff тянет
 * несвязанный дрейф по другим таблицам).
 */
final class Version20260723110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'worker_capabilities: nullable колонка host (явный host/node-идентификатор воркера)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker_capabilities ADD host VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker_capabilities DROP host');
    }
}

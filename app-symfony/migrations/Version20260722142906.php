<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * registry-02: ключ worker_capabilities меняется с UNIQUE(worker_type) на
 * составной UNIQUE(worker_type, instance_id) — несколько инстансов одного
 * workerType (напр. два хоста с одним воркером) теперь сосуществуют как
 * отдельные ряды вместо перетирания друг друга.
 *
 * Hand-written (не через migrate-diff): автосгенерированный diff тянул за
 * собой несвязанный дрейф схемы по другим таблицам (переименования индексов,
 * снятие DC2Type-комментариев) — вне зоны этой миграции.
 */
final class Version20260722142906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'worker_capabilities: ключ (worker_type) → составной (worker_type, instance_id)';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT 'legacy' backfill'ит существующие ряды детерминированным placeholder'ом
        // за тот же ALTER, без отдельного UPDATE. Дефолт снимается сразу после —
        // приложение всегда шлёт instanceId, стоячий DEFAULT не нужен.
        $this->addSql("ALTER TABLE worker_capabilities ADD instance_id VARCHAR(128) NOT NULL DEFAULT 'legacy'");
        $this->addSql('ALTER TABLE worker_capabilities ALTER instance_id DROP DEFAULT');
        $this->addSql('DROP INDEX UNIQ_WORKER_CAPABILITIES_TYPE ON worker_capabilities');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_WORKER_CAPABILITIES_TYPE_INSTANCE ON worker_capabilities (worker_type, instance_id)');
    }

    public function down(Schema $schema): void
    {
        // ⚠ Откат безопасен только пока в таблице максимум один ряд на worker_type
        // (т.е. до появления реальных multi-instance регистраций) — иначе
        // CREATE UNIQUE INDEX на голый worker_type упадёт на дубликатах.
        $this->addSql('DROP INDEX UNIQ_WORKER_CAPABILITIES_TYPE_INSTANCE ON worker_capabilities');
        $this->addSql('ALTER TABLE worker_capabilities DROP instance_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_WORKER_CAPABILITIES_TYPE ON worker_capabilities (worker_type)');
    }
}

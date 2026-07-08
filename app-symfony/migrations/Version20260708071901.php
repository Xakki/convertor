<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708071901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблица worker_capabilities: реестр возможностей воркеров (Phase 1 регистрации)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_capabilities (
                id           INT AUTO_INCREMENT NOT NULL,
                worker_type  VARCHAR(64)        NOT NULL,
                capabilities JSON               NOT NULL,
                last_seen    DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_WORKER_CAPABILITIES_TYPE (worker_type),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worker_capabilities');
    }
}

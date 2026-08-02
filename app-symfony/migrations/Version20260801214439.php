<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-25 catch-up: синхронизация типов колонок с актуальным Doctrine mapping.
 *
 * Не трогает индексы — имена зафиксированы через ORM Index/UniqueConstraint
 * на entities (IDX_… / UNIQ_… / FK_… как в БД). Здесь только:
 *  - снятие COMMENT '(DC2Type:datetime_immutable)' (DBAL 4 больше не пишет их);
 *  - выравнивание boolean → TINYINT без display-width / без лишних DEFAULT,
 *    которые живы только в PHP-дефолтах entity.
 *
 * Данные не меняются (нет DROP COLUMN / DROP INDEX).
 */
final class Version20260801214439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CNV-25: catch-up колонок (DC2Type comments, TINYINT/DEFAULT) под ORM mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversion_toggles CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE conversions CHANGE status status VARCHAR(20) NOT NULL, CHANGE is_ai is_ai TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE is_ocr is_ocr TINYINT NOT NULL');
        $this->addSql('ALTER TABLE file_storage CHANGE created_at created_at DATETIME NOT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE payments CHANGE status status VARCHAR(20) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE plan plan VARCHAR(50) NOT NULL, CHANGE daily_conversions daily_conversions INT NOT NULL, CHANGE daily_ai_conversions daily_ai_conversions INT NOT NULL, CHANGE quota_reset_at quota_reset_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE is_guest is_guest TINYINT NOT NULL, CHANGE is_admin is_admin TINYINT NOT NULL');
        $this->addSql('ALTER TABLE worker_capabilities CHANGE last_seen last_seen DATETIME NOT NULL, CHANGE status status VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions CHANGE status status VARCHAR(20) DEFAULT \'pending\' NOT NULL, CHANGE is_ai is_ai TINYINT DEFAULT 0 NOT NULL, CHANGE is_ocr is_ocr TINYINT DEFAULT 0 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE conversion_toggles CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE file_storage CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE expires_at expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE payments CHANGE status status VARCHAR(20) DEFAULT \'pending\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE users CHANGE plan plan VARCHAR(50) DEFAULT \'free\' NOT NULL, CHANGE daily_conversions daily_conversions INT DEFAULT 0 NOT NULL, CHANGE daily_ai_conversions daily_ai_conversions INT DEFAULT 0 NOT NULL, CHANGE quota_reset_at quota_reset_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, CHANGE is_admin is_admin TINYINT DEFAULT 0 NOT NULL, CHANGE is_guest is_guest TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE worker_capabilities CHANGE last_seen last_seen DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE status status VARCHAR(20) DEFAULT \'unknown\' NOT NULL');
    }
}

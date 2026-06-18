<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, plans, file_storage, conversions, payments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE plans (
                id           INT AUTO_INCREMENT NOT NULL,
                name         VARCHAR(50)        NOT NULL,
                daily_limit  INT                NOT NULL,
                daily_ai_limit INT              NOT NULL,
                max_file_size_mb INT            NOT NULL,
                price_usd    DOUBLE PRECISION   NOT NULL,
                price_stars  INT                NOT NULL,
                UNIQUE INDEX UNIQ_PLANS_NAME (name),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id                    INT AUTO_INCREMENT NOT NULL,
                telegram_id           BIGINT             DEFAULT NULL,
                phone                 VARCHAR(20)        DEFAULT NULL,
                email                 VARCHAR(180)       DEFAULT NULL,
                plan                  VARCHAR(50)        NOT NULL DEFAULT 'free',
                daily_conversions     INT                NOT NULL DEFAULT 0,
                daily_ai_conversions  INT                NOT NULL DEFAULT 0,
                quota_reset_at        DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at            DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                is_active             TINYINT(1)         NOT NULL DEFAULT 1,
                UNIQUE INDEX UNIQ_USERS_TELEGRAM_ID (telegram_id),
                UNIQUE INDEX UNIQ_USERS_PHONE (phone),
                UNIQUE INDEX UNIQ_USERS_EMAIL (email),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE file_storage (
                id            INT AUTO_INCREMENT NOT NULL,
                original_name VARCHAR(255)       NOT NULL,
                storage_path  VARCHAR(500)       NOT NULL,
                mime_type     VARCHAR(127)       NOT NULL,
                size_bytes    BIGINT             NOT NULL,
                created_at    DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at    DATETIME           DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE conversions (
                id             INT AUTO_INCREMENT NOT NULL,
                user_id        INT                NOT NULL,
                input_file_id  INT                NOT NULL,
                output_file_id INT                DEFAULT NULL,
                from_format    VARCHAR(20)        NOT NULL,
                to_format      VARCHAR(20)        NOT NULL,
                category       VARCHAR(20)        NOT NULL,
                status         VARCHAR(20)        NOT NULL DEFAULT 'pending',
                error_message  LONGTEXT           DEFAULT NULL,
                processing_ms  INT                DEFAULT NULL,
                is_ai          TINYINT(1)         NOT NULL DEFAULT 0,
                created_at     DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at     DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_CONVERSIONS_USER_ID (user_id),
                INDEX IDX_CONVERSIONS_STATUS (status),
                INDEX IDX_CONVERSIONS_CREATED_AT (created_at),
                CONSTRAINT FK_CONVERSIONS_USER    FOREIGN KEY (user_id)        REFERENCES users (id),
                CONSTRAINT FK_CONVERSIONS_INPUT   FOREIGN KEY (input_file_id)  REFERENCES file_storage (id),
                CONSTRAINT FK_CONVERSIONS_OUTPUT  FOREIGN KEY (output_file_id) REFERENCES file_storage (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id          INT AUTO_INCREMENT NOT NULL,
                user_id     INT                NOT NULL,
                amount      DOUBLE PRECISION   NOT NULL,
                currency    VARCHAR(10)        NOT NULL,
                gateway     VARCHAR(30)        NOT NULL,
                status      VARCHAR(20)        NOT NULL DEFAULT 'pending',
                external_id VARCHAR(255)       DEFAULT NULL,
                metadata    JSON               NOT NULL,
                created_at  DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_PAYMENTS_USER_ID (user_id),
                INDEX IDX_PAYMENTS_STATUS (status),
                CONSTRAINT FK_PAYMENTS_USER FOREIGN KEY (user_id) REFERENCES users (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // Seed default plans
        $this->addSql(<<<'SQL'
            INSERT INTO plans (name, daily_limit, daily_ai_limit, max_file_size_mb, price_usd, price_stars) VALUES
            ('free',  2,   1,   50,  0.00, 0),
            ('basic', 100, 30,  200, 3.00, 150),
            ('pro',   -1,  100, 500, 10.00, 500)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE conversions');
        $this->addSql('DROP TABLE file_storage');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE plans');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}

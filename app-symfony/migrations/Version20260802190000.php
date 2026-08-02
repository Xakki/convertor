<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-28 slice 1: prepaid balance (USD cents) + append-only ledger + billing_mode on conversions.
 */
final class Version20260802190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CNV-28: prepaid balance_cents on users, balance_transactions ledger, conversions.billing_mode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD balance_cents INT NOT NULL DEFAULT 0');

        $this->addSql(<<<'SQL'
            CREATE TABLE balance_transactions (
                id           INT AUTO_INCREMENT NOT NULL,
                user_id      INT                NOT NULL,
                amount_cents INT                NOT NULL,
                type         VARCHAR(20)        NOT NULL,
                source       VARCHAR(20)        NOT NULL,
                ref_id       VARCHAR(64)        DEFAULT NULL,
                metadata     JSON               DEFAULT NULL,
                created_at   DATETIME           NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_BALANCE_TRANSACTIONS_USER_ID (user_id),
                INDEX IDX_BALANCE_TRANSACTIONS_CREATED_AT (created_at),
                INDEX IDX_BALANCE_TRANSACTIONS_USER_CREATED_AT (user_id, created_at),
                CONSTRAINT FK_BALANCE_TRANSACTIONS_USER FOREIGN KEY (user_id) REFERENCES users (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE conversions ADD billing_mode VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions DROP billing_mode');
        $this->addSql('DROP TABLE balance_transactions');
        $this->addSql('ALTER TABLE users DROP balance_cents');
    }
}

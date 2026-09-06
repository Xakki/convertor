<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string { return 'CNV-109 append-only personal API audit records'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_audit_records (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, method VARCHAR(7) NOT NULL, route VARCHAR(80) NOT NULL, status SMALLINT NOT NULL, duration_ms INT NOT NULL, token_label VARCHAR(100) NOT NULL, token_mask VARCHAR(13) NOT NULL, conversion_id INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_API_AUDIT_OWNER_CREATED (owner_id, created_at), INDEX IDX_API_AUDIT_CREATED (created_at), PRIMARY KEY(id), CONSTRAINT FK_API_AUDIT_OWNER FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_audit_records');
    }
}

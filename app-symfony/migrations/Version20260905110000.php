<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905110000 extends AbstractMigration
{
    public function getDescription(): string { return 'add personal API bearer token lifecycle storage'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE personal_api_tokens (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, label VARCHAR(100) NOT NULL, token_prefix VARCHAR(16) NOT NULL, verifier VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_PERSONAL_TOKEN_VERIFIER (verifier), INDEX IDX_PERSONAL_TOKEN_USER_ACTIVE (user_id, revoked_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE personal_api_tokens ADD CONSTRAINT FK_PERSONAL_TOKEN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE personal_api_tokens'); }
}

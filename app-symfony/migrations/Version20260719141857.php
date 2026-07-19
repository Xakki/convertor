<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * oauth-01-foundation: таблица `social_identities` — привязка внешних OAuth-
 * провайдеров (google|github|yandex|vk) к User. UNIQUE(provider, provider_uid),
 * FK user_id → users ON DELETE CASCADE.
 *
 * Автоген `doctrine:migrations:diff` заодно поймал НЕСВЯЗАННЫЙ пред-существующий
 * дрифт схемы (переименования индексов в hash-имена Doctrine, COMMENT-разметку
 * datetime_immutable по всей БД) — сюда намеренно НЕ включён, чтобы миграция
 * делала строго одну вещь (та же политика, что Version20260718115536).
 */
final class Version20260719141857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_identities table (oauth-01-foundation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE social_identities (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(32) NOT NULL, provider_uid VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, username VARCHAR(255) DEFAULT NULL, display_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_346A9E2AA76ED395 (user_id), UNIQUE INDEX uniq_social_provider_uid (provider, provider_uid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE social_identities ADD CONSTRAINT FK_346A9E2AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_identities DROP FOREIGN KEY FK_346A9E2AA76ED395');
        $this->addSql('DROP TABLE social_identities');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Telegram-профиль в users: first_name, username, photo_url (все nullable).
 * photo_url хранит НАШ S3-ключ закешированного аватара, не сырой TG-URL.
 *
 * Диффер захватил ещё пачку не связанной с задачей drift'а (переименования
 * индексов, снятие DC2Type-комментариев) — намеренно убрано, миграция несёт
 * только добавление трёх колонок.
 */
final class Version20260713061310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Telegram profile columns (first_name, username, photo_url) to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD first_name VARCHAR(255) DEFAULT NULL, ADD username VARCHAR(255) DEFAULT NULL, ADD photo_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP first_name, DROP username, DROP photo_url');
    }
}

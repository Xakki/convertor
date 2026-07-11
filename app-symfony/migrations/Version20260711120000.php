<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'conversion_toggles: персистентный вкл/выкл конвертации по паре (from→to)';
    }

    public function up(Schema $schema): void
    {
        // Тумблер конвертаций (admin-panel). Один ряд на пару (from_format,
        // to_format), уникальный ключ. Отсутствие ряда = включено, поэтому
        // пустая таблица ничего не меняет. Хранятся только явно переключённые пары.
        $this->addSql('CREATE TABLE conversion_toggles (
            id INT AUTO_INCREMENT NOT NULL,
            from_format VARCHAR(32) NOT NULL,
            to_format VARCHAR(32) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_conversion_pair (from_format, to_format),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE conversion_toggles');
    }
}

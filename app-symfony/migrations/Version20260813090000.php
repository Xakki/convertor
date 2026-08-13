<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Сохраняет параметры результата конвертации в jobs изображений';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE conversions ADD options JSON NOT NULL DEFAULT ('[]') COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions DROP options');
    }
}
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711091528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: поле is_admin (админ-тир — доступ к ^/admin и ^/api/v1/admin, ROLE_ADMIN)';
    }

    public function up(Schema $schema): void
    {
        // is_admin — флаг админа поверх обычного пользователя. Default 0,
        // существующие юзеры остаются не-админами. Выдаётся app:user:make-admin.
        $this->addSql('ALTER TABLE users ADD is_admin TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP is_admin');
    }
}

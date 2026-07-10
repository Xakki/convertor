<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710113302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: поля is_guest + guest_id (анонимная конвертация, guest-User по cookie)';
    }

    public function up(Schema $schema): void
    {
        // is_guest — признак гостя; guest_id — сырое значение cookie-id (nullable,
        // unique). NULL повторяется много раз (MariaDB это допускает), поэтому
        // существующие пользователи не конфликтуют по уникальному индексу.
        $this->addSql('ALTER TABLE users ADD is_guest TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE users ADD guest_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USERS_GUEST_ID ON users (guest_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USERS_GUEST_ID ON users');
        $this->addSql('ALTER TABLE users DROP is_guest');
        $this->addSql('ALTER TABLE users DROP guest_id');
    }
}

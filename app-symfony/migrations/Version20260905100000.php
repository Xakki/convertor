<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: distinguish IP-derived anonymous identities from cookie guests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD anonymous_ip TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP anonymous_ip');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CNV-137 persist explicit host and worker CPU observation windows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_telemetry_snapshots ADD telemetry JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_telemetry_snapshots DROP telemetry');
    }
}

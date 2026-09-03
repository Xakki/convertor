<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903090000 extends AbstractMigration
{
    public function getDescription(): string { return 'CNV-137 latest-only host telemetry snapshots'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE host_telemetry_snapshots (id INT AUTO_INCREMENT NOT NULL, host_name VARCHAR(253) NOT NULL, contract_version INT NOT NULL, cpu_count INT DEFAULT NULL, mem_total_bytes BIGINT DEFAULT NULL, mem_available_bytes BIGINT DEFAULT NULL, disk_total_bytes BIGINT DEFAULT NULL, disk_used_bytes BIGINT DEFAULT NULL, load1 DOUBLE PRECISION DEFAULT NULL, workers JSON NOT NULL, observed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_HOST_TELEMETRY_HOST (host_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE host_telemetry_snapshots'); }
}

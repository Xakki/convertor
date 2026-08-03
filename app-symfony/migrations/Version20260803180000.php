<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-5 Phase 1 (entity-chain-schema): колонки цепочки на conversions —
 * chain_id / sequence / final_to_format + индекс (chain_id, sequence).
 * Manager/Persister/Quota ещё не подключены — только схема.
 */
final class Version20260803180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CNV-5: conversions chain_id, sequence, final_to_format + IDX (chain_id, sequence)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions ADD chain_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE conversions ADD sequence INT DEFAULT NULL');
        $this->addSql('ALTER TABLE conversions ADD final_to_format VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CONVERSIONS_CHAIN_ID_SEQUENCE ON conversions (chain_id, sequence)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_CONVERSIONS_CHAIN_ID_SEQUENCE ON conversions');
        $this->addSql('ALTER TABLE conversions DROP final_to_format');
        $this->addSql('ALTER TABLE conversions DROP sequence');
        $this->addSql('ALTER TABLE conversions DROP chain_id');
    }
}

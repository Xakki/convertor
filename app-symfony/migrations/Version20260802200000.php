<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-28: идемпотентность top-up по telegram_payment_charge_id (UNIQUE external_id).
 */
final class Version20260802200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CNV-28: UNIQUE index on payments.external_id (NULL-safe in MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PAYMENTS_EXTERNAL_ID ON payments (external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_PAYMENTS_EXTERNAL_ID ON payments');
    }
}

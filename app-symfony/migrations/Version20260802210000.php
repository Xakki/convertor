<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-58/CNV-28: telegram_payment_charge_id длиннее VARCHAR(64) —
 * credit() писал его в balance_transactions.ref_id и падал (SQLSTATE 1406),
 * webhook successful_payment → 500, бот молчал после оплаты.
 * Выравниваем с payments.external_id (255).
 */
final class Version20260802210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen balance_transactions.ref_id to VARCHAR(255) for Telegram charge ids';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE balance_transactions CHANGE ref_id ref_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE balance_transactions CHANGE ref_id ref_id VARCHAR(64) DEFAULT NULL');
    }
}

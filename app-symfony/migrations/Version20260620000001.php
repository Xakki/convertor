<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conversions.is_ocr flag (explicit OCR intent → image worker)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions ADD is_ocr TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions DROP is_ocr');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * requeue-attempt-generation-marker (grooming/requeue-attempt-generation-marker):
 * добавляет `Conversion.attempt` — маркер попытки/поколения, инкрементится
 * оператором на requeue, протягивается в job → gateway → dlq-fail, чтобы
 * ConversionResultPersister::persist() отличал устаревшую (superseded)
 * финализацию от актуальной.
 *
 * Автогенерированный `doctrine:migrations:diff` заодно поймал НЕСВЯЗАННЫЙ
 * пред-существующий дрифт схемы (переименования индексов, COMMENT-разметку
 * на datetime_immutable колонках и т.п. по всей БД) — сюда намеренно НЕ
 * включён, чтобы миграция делала строго одну вещь.
 */
final class Version20260718115536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Conversion.attempt (requeue-attempt-generation-marker)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions ADD attempt INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions DROP attempt');
    }
}

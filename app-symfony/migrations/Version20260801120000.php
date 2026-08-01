<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * registry-09 / CNV-36: одноразовая очистка мусорных строк `worker_capabilities`
 * с `instance_id = 'test:worker'` — артефакт ручного/тестового register, не живой
 * воркер, но попадает в матрицу `/formats` и admin workers page наравне с реальными
 * инстансами. Seed-строки (`instance_id = '__seed__'`, registry-03) НЕ затрагиваются.
 *
 * Hand-written data migration (тот же стиль, что registry-03 seed).
 */
final class Version20260801120000 extends AbstractMigration
{
    private const JUNK_INSTANCE_ID = 'test:worker';

    public function getDescription(): string
    {
        return 'worker_capabilities: удалить junk instance_id test:worker (registry-09 / CNV-36)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM worker_capabilities WHERE instance_id = ?',
            [self::JUNK_INSTANCE_ID],
        );
    }

    public function down(Schema $schema): void
    {
        // Необратимо: удалённые junk-строки не восстанавливаются — осознанный no-op.
    }
}

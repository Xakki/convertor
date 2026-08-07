<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-71-04 (последний шаг эпика CNV-71): удаляет 6 seed-строк
 * `instance_id = '__seed__'`, заведённых `Version20260722150301.php`
 * (registry-03) как статичная страховка «матрица не опустеет». Страховка
 * перестала быть нужна — CNV-71-02 перевёл `/formats`/SEO-страницы на
 * статический каталог `config/catalog/conversion_pairs.json`, а CNV-71-03
 * завёл честный 503 `worker_unavailable` вместо тихого фолбэка на seed при
 * пустой `worker_capabilities`. Вся спец-обработка `__seed__` в
 * `WorkerCapabilityGcService`, `WorkerCapabilityRepository`,
 * `WorkerStatsProvider` и `templates/admin/workers.html.twig` вычищена этим
 * же коммитом.
 *
 * Hand-written (тот же стиль, что registry-03 seed и registry-09 junk-cleanup).
 */
final class Version20260807060000 extends AbstractMigration
{
    private const SEED_INSTANCE_ID = '__seed__';

    public function getDescription(): string
    {
        return 'worker_capabilities: удалить 6 seed-строк __seed__ (CNV-71-04, финал эпика CNV-71)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM worker_capabilities WHERE instance_id = ?',
            [self::SEED_INSTANCE_ID],
        );
    }

    public function down(Schema $schema): void
    {
        // Необратимо, тот же прецедент, что Version20260801120000::down()
        // (junk-очистка той же таблицы): удалённые seed-строки — статичный
        // снимок матрицы на момент registry-03, который CNV-71-02/03 сделали
        // избыточным. Восстанавливать его down()-миграцией значило бы
        // воссоздать ровно ту костыльную страховку, ради удаления которой
        // существует весь эпик CNV-71, — заведомо неверный контракт
        // обратимости. Осознанный no-op.
    }
}

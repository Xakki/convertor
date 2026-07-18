<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Composite indexes на `conversions` под реальные admin-запросы (карточка
 * hardening-05-conversions-admin-indexes). Single-column индексы на
 * status/created_at/user_id уже были (Version20260419000001) — недостающие
 * композиты подобраны по EXPLAIN реальных запросов:
 *
 *  - `(status, updated_at)` — ConversionRepository::findStuck/countStuck
 *    (WHERE status IN(..) AND updated_at < ? ORDER BY updated_at ASC),
 *    вызывается из QueueStatsProvider::dbStuck() (admin-панель очередей).
 *    Убирает построчный lookup в кластерный индекс (EXPLAIN: "Using index
 *    condition" → "Using index", покрывающий скан).
 *  - `(status, created_at)` — ConversionRepository::searchPaginated с
 *    фильтром status (тумблер «только ошибки» в admin-логах, status=failed)
 *    + ORDER BY created_at DESC. EXPLAIN ANALYZE: реально читаемые строки
 *    117 → 25 (ровно LIMIT), тип доступа index-scan → ref (прямой seek по
 *    границе status), "Using index" (без row lookup).
 *
 * Старый одиночный `IDX_CONVERSIONS_STATUS (status)` — строгий left-prefix
 * обоих новых композитов (leftmost-prefix rule), после миграции ничего не
 * обслуживает (в possible_keys ещё виден, optimizer всегда выбирает
 * композит) — чистый write-cost без пользы на чтение, поэтому удалён здесь
 * же (карточка явно просит «не плодить лишние индексы»). Проверено: нет
 * FORCE/USE INDEX на это имя нигде в коде.
 *
 * Дифф диспетчера (`make migrate-diff`) захватывает не относящийся к задаче
 * дрейф (переименования индексов в хеш-имена Doctrine, снятие
 * DC2Type-комментариев — см. doctrine:schema:validate) — миграция написана
 * руками, несёт только целевые изменения индексов (см. прецедент
 * Version20260713061310).
 */
final class Version20260717210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes on conversions for admin queries; drop now-redundant single-column status index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_CONVERSIONS_STATUS_UPDATED_AT ON conversions (status, updated_at)');
        $this->addSql('CREATE INDEX IDX_CONVERSIONS_STATUS_CREATED_AT ON conversions (status, created_at)');
        $this->addSql('DROP INDEX IDX_CONVERSIONS_STATUS ON conversions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_CONVERSIONS_STATUS ON conversions (status)');
        $this->addSql('DROP INDEX IDX_CONVERSIONS_STATUS_UPDATED_AT ON conversions');
        $this->addSql('DROP INDEX IDX_CONVERSIONS_STATUS_CREATED_AT ON conversions');
    }
}

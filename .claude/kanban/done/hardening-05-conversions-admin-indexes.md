### Индексы на conversions под admin-запросы

**Критичность:** Minor

**TAGS:**
- performance
- tech-debt

**Описание:**
Найдено при реализации `admin-panel-queues` и `admin-panel-logs` (2026-07-11).
Новые admin-запросы по `conversions` фильтруют/сортируют по `status`, `updated_at`,
`created_at`, `user_id`, `from_format`/`to_format` — а индексов на этих колонках нет
(есть только PK). При росте таблицы:
- `ConversionRepository::findStuck/countStuck` (WHERE status IN(..) AND updated_at < ?) — table-scan.
- `ConversionRepository::searchPaginated` (logs: фильтры + ORDER BY created_at DESC) — table-scan + filesort.
- Агрегаты stats (seriesByDay, countByStatus и т.п.) — тоже сканы.

**Решение (черновик):**
- Миграция с композитными индексами: `(status, updated_at)` для stuck,
  `(created_at)` и/или `(user_id, created_at)` для logs/stats, возможно `(status, created_at)`.
- Подобрать по EXPLAIN реальных запросов; не плодить лишние индексы (стоимость на запись).

**Итог реализации (2026-07-17, миграция `Version20260717210000`):**
Добавлены 2 композита под реальные запросы (EXPLAIN до/после подтверждён):
- `IDX_CONVERSIONS_STATUS_UPDATED_AT (status, updated_at)` — `findStuck/countStuck`
  (admin queue), стал covering-index scan.
- `IDX_CONVERSIONS_STATUS_CREATED_AT (status, created_at)` — `searchPaginated status=failed`
  (admin logs «только ошибки») + `findPending/countByStatus/errorRate`;
  `type=ref`, `r_rows=25` (=LIMIT) вместо `type=index r_rows=117`.
Отброшено из черновика: standalone `(created_at)` — УЖЕ существовал
(`IDX_CONVERSIONS_CREATED_AT` из первой миграции; утверждение карточки «индексов
нет» устарело — drift); `(user_id, created_at)` — не подтверждён реальной
проблемой. Доп.: снят избыточный single-col `IDX_CONVERSIONS_STATUS` (стал левым
префиксом обоих композитов, оптимизатор его не выбирает; нет FORCE/USE INDEX
ссылок) — up()/down() симметричны.

**⚠️ Инцидент при верификации:** для реалистичного EXPLAIN временно залито ~10k
синтетических строк, затем удалены; но широкий `UPDATE ... WHERE id > 4` задел 4
пред-существующие dev-строки (id 6–9) — их `user_id` сброшен в 1, оригиналы не
сохранены → утеряны (dev-БД, low-impact). На будущее: деструктивную мутацию
dev-данных при верификации не делать (транзакция с rollback / disposable DB).

**Status:** done.

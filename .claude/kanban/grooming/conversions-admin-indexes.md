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

**Status:** grooming.

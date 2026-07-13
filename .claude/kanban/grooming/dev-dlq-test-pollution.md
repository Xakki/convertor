### Dev `conv.dead` замусорен stale-записями из unit-тестов

**Criticality:** Minor

**TAGS:**
- tech-debt
- tests

**Description:**
В dev-стриме `conv.dead` лежит ~14 устаревших записей от unit-тестов
(`conversionId 9999`, стримы `conv.testrdq_*`). Безвредно, но засоряет
инспекцию DLQ.

**Problem:**
Тестовые прогоны оставляют свои DLQ-записи в dev `conv.dead`; их не отличить с
ходу от реальных провалов при ручном разборе очереди.

**Impact:**
Низкий — только шум при инспекции DLQ в dev. На прод/логику не влияет.

**Recommendation:**
- teardown тестов должен подчищать свои DLQ-записи, ЛИБО
- добавить dev-таргет очистки DLQ (напр. `make dlq-purge-dev`), выпиливающий
  тестовые записи (`conversionId 9999` / `conv.testrdq_*`).

**Acceptance Criteria:**
- После прогона тестов dev `conv.dead` не содержит их артефактов, ИЛИ есть
  явный dev-таргет очистки.
- Продовый/реальный DLQ-путь не затронут.
- Tests/QA green: `make test`.

**Open questions:** *(grooming)*
- teardown в тестах vs отдельный make-таргет очистки — что предпочесть.
- Не безопаснее ли тестам вообще использовать изолированный DLQ-стрим/префикс,
  чтобы не касаться dev `conv.dead`.

**Контекст:** разбор dev-DLQ (2026-07-12): ~14 записей `conversionId 9999`,
`conv.testrdq_*`.

**Status:** grooming.

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

**Decisions:** Решение = тесты пишут в ИЗОЛИРОВАННЫЙ тест-префикс DLQ-стрима
(напр. `conv.dead.test_*` / префикс тест-стека), НИКОГДА не в dev `conv.dead`.
Это и устраняет засорение, и убирает нужду в отдельной очистке (изоляция вместо
teardown-cleanup). Заодно вычистить текущие ~14 stale-записей
(`conversionId 9999`) из dev `conv.dead` разово.

**Контекст:** разбор dev-DLQ (2026-07-12): ~14 записей `conversionId 9999`,
`conv.testrdq_*`.

**Status:** grooming.

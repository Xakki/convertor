### Удалить хардкод матрицы: единственный источник — репозиторий

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
Четвёртый шаг Phase 2 эпика `[[registry-00-self-registration]]`. Удаляет
`ConversionRegistry::workerCapabilities()` и `buildMatrixFromHardcode()`
(`app-symfony/src/Service/Conversion/ConversionRegistry.php:374`, вызывается из
`buildRoutingPairs()` L276-292 при пустой БД/исключении) — `buildRoutingPairs()` отныне читает
ТОЛЬКО `WorkerCapabilityRepository`. Безопасно только после `[[registry-03-seed-migration]]`
(БД никогда не пуста) и `[[registry-04-matrix-tooling-tests]]` (тесты уже переведены на DB-путь).

**Problem:**
Хардкод — второй источник истины, который дрейфует от реального Python `CAPABILITIES`
(эпик, Description) и мешает выразить multi-candidate маршрутизацию (Phase 3).

**Impact:**
Пока хардкод жив как fallback — любой баг в DB-пути маскируется, а Stage-7 «coming-soon» пары
(xls/xlsx/ods/csv→pdf, ppt/pptx/odp→pdf, dwg/dxf→pdf/svg/png, pdf→jpg — существуют только в
хардкоде, `workers/libreoffice/worker.py:86-98` их не декларирует) продолжают отдаваться в
`/formats` и приниматься на submit, хотя реально уходят в DLQ (`[[align-document-stream-matrix-dlq]]`
fast-DLQ покрывает это как временную меру).

**Recommendation:**
- Удалить `workerCapabilities()` и `buildMatrixFromHardcode()`; `buildRoutingPairs()` — только
  репозиторий, без try/catch-фолбэка на хардкод.
- Объединение матриц по нескольким инстансам одного типа (после
  `[[registry-02-schema-multi-instance]]` их может быть несколько с разным `instance_id`) —
  дедуп пар при построении матрицы.
- Политика коллизий — как зафиксировано в эпике (Decisions): non-AI precedence, last-registered-wins
  (интерим до Phase 3 multi-candidate router).
- Кеш матрицы и его инвалидация при `register()` — сохраняются как в Phase 1, но источник
  инвалидации теперь единственный (репозиторий).
- Поведение при пустой/недоступной БД: после seed-миграции пустой она быть не должна —
  зафиксировать в коде/тесте, что происходит, если всё же пуста (напр. пустой `/formats` +
  явный лог/алерт, а не тихий fallback) — явное решение принять по факту реализации и записать
  в карточку.
- Здесь Stage-7 пары исчезают ОКОНЧАТЕЛЬНО из матрицы → submit отдаёт честный 400 вместо
  accept→DLQ (`[[align-document-stream-matrix-dlq]]`'s "не удалять Stage-7 пары" — это ПЕРЕКРЫВАЕТСЯ
  здесь по [USER DECISION 2026-07-01], зафиксированному в эпике).
- Проверить, что публичные сигнатуры `getSupportedFormats()`, `isSupported()`, `streamFor()`,
  `isAi()` НЕ меняются — вызывающие: `ConversionToggleController.php:47,68`,
  `ConversionController.php:552`, `ConversionPageController.php:42`,
  `ConversionManager.php:72,76,237`.

**Acceptance Criteria:**
- `workerCapabilities()`/`buildMatrixFromHardcode()` удалены из кодовой базы, нет остаточных
  вызовов/ссылок.
- Матрица строится только из БД; submit на Stage-7-паре отдаёт 400 (не accept→DLQ).
- Несколько инстансов одного `worker_type` (разный `instance_id`) корректно объединяются в
  матрице без дублей пар; коллизия pair'ов между типами разрешается по non-AI precedence
  last-registered-wins.
- Поведение на пустой БД задокументировано и покрыто тестом.
- Сигнатуры `getSupportedFormats/isSupported/streamFor/isAi` не изменились; все перечисленные
  вызывающие места компилируются и работают без изменений на своей стороне.
- `test_routing_drift.py` (переписанный в [[registry-04-matrix-tooling-tests]]) и
  `ConversionRegistryGoldenTest` — зелёные без хардкод-fallback.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest.

**Decisions:**
- Груминг 2026-07-22: удаление хардкода — четвёртый, не первый шаг Phase 2 (см. порядок
  подкарточек в эпике) — сначала схема+seed+тесты, потом снос старого источника.

**Зависит от:** `[[registry-04-matrix-tooling-tests]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** todo

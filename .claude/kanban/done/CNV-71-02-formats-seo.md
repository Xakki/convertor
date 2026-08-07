### Перевести /formats, SEO-страницы и валидацию пар на каталог

**Criticality:** High

**TAGS:**
- feature

**Description:**
Часть эпика CNV-71. Требует завершённой CNV-71-01 (каталог форматов уже существует в репо).

**Problem:**
`GET /api/v1/formats`, `/convert/{src}-to-{dst}` и построение пар/chain-BFS сегодня зависят от `worker_capabilities`: без строк воркеров в БД сайт останется с пустым списком форматов и 400 на любую конвертацию.

**Impact:**
Пока валидация и `/formats` завязаны на `worker_capabilities`, удаление seed-строк (CNV-71-04) невозможно без риска пустого каталога форматов на сайте.

**Recommendation:**
`GET /api/v1/formats`, `/convert/{src}-to-{dst}` и построение пар/chain-BFS берут данные из каталога (CNV-71-01), а не из `worker_capabilities`. Строки воркеров остаются источником ответа «есть ли такой тип воркера», но больше не определяют, какие форматы существуют. Разобраться, что делать с ручным дублем `CuratedConversionPairs.php` и с таблицей в `ROADMAP.md:182-207` — как минимум пометить каталог единственным источником правды и убрать расхождения. Кэш `conv.worker.matrix` и его инвалидацию пересмотреть: каталог статичен, инвалидация по регистрации воркера ему не нужна.

**Acceptance Criteria:**
- `/api/v1/formats`, SEO-страницы `/convert/{src}-to-{dst}` и валидация пар/chain-BFS читают каталог, а не `worker_capabilities`
- `CuratedConversionPairs.php` и таблица в `ROADMAP.md:182-207` приведены в соответствие каталогу (расхождения устранены)
- Кэш/инвалидация `conv.worker.matrix` пересмотрены под статичный каталог
- Tests/QA green: `make phpstan`, `make cs-check`, тесты — см. CLAUDE.md

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Формат виден в UI без воркера, без пометки недоступности — подтверждено пользователем 2026-08-04
- Переход `/formats`/SEO на статический каталог (CNV-71-01) теряет 8 пар `pages->{docx,epub,html,md,odt,pdf,rtf,txt}`: LibreOffice добавляет `pages` в матрицу условно в рантайме (проверка наличия libetonyek в образе, `workers/libreoffice/worker.py:54-66,165-166`), статический AST-каталог рантайм-проверку смоделировать не может — либо принять потерю, либо явный carve-out.

**Execution Log** *(ревью-фиксы CNV-71-02, 2026-08-05)*
- **Пустой каталог — громкая ошибка.** `ConversionRegistry::loadCatalogMatrix()` раньше молча принимал синтаксически валидный, но ПУСТОЙ `[]` каталог и отдавал пустую матрицу без исключения/лога. Добавлен `\RuntimeException` при `$matrix === []` (тот же класс ошибки, что у отсутствующего/невалидного файла) — легитимного случая коммиченного пустого каталога нет. Обновлены докблоки класса и метода. Тест `ConversionRegistryCatalogLoadingTest::testSyntacticallyValidEmptyArrayYieldsEmptyMatrixWithoutThrowing` переименован в `testSyntacticallyValidEmptyArrayThrows` и теперь ждёт throw. Также поправлен `ConversionRegistryFindPathTest::testEmptyMatrixReturnsNull` → `testEmptyMatrixThrows` (тот же самый пробел — пустой каталог там раньше тоже тихо проглатывался). Другие тесты/фикстуры на пустой каталог не завязаны (`GenerateConversionPairsCommandTest` пишет `[]` только для сравнения СЫРЫХ байт в `--check`, `loadCatalogMatrix()` там не вызывается).
- **Докблок `workers/tests/test_routing_drift.py` актуализирован.** Убраны ложные упоминания «LIVE PHP registry»/«register round-trip» — `bin/dump-matrix.php` с CNV-71-02 читает статический каталог, БД/`WorkerCapabilityRepository`/`register()` не трогает. Явно отмечено, что assertion B (`test_worker_matrix_subset_of_registry`) в основном перекрывается `test_catalog_drift.py` (побайтовое сравнение `worker_capabilities.json` со свежим AST-извлечением, диф точнее), а assertion A (`routing_keys`) остаётся независимой и значимой. Обе assertion сохранены без изменений.
- **Стале-комментарий про `make test-drift`.** Было: «`make test-drift` is NOT a prerequisite of `make test`». Проверено `make -n test` → `test: test-up → test-php test-python test-drift` (корневой `Makefile:166-167`) — `test-drift` ВХОДИТ в `make test`. Комментарий исправлен. Грепнул репозиторий на другие «hand-run-only» заявления — не нашёл: `README.md` и `docs/queue-streams.md` уже корректно описывают `test-drift` как часть блокирующих CI-гейтов.
- **Routing-parity доказательство (before vs after).** Старый DB-backed `bin/dump-matrix.php` (`git show 9a2df2a^:app-symfony/bin/dump-matrix.php`, сохранён в scratchpad как `backup_dump-matrix-old-9a2df2a-parent.php`) прогнан на изолированном тест-стенде (`make TEST=1 test-up`, замигрированная `convertor-test` БД с seed-строками `__seed__`) — вместе с 12 файлами того же родительского коммита (`ConversionRegistry`, `WorkerCapabilityRepository`, `WorkerCapability`, `WorkerLivenessStatus`, `services.yaml`, `cache.yaml`, оба `WorkerController`, `QueueStatsProvider`, `ConversionToggleService`, `WorkerCapabilityGcService`, `WorkerLivenessReconciler` — всё дерево, изменённое коммитом 9a2df2a), собранными во ВРЕМЕННУЮ копию внутри контейнера (`/opt/old-app-symfony`, НЕ bind-mount, репозиторий на хосте не тронут). Текущий `bin/dump-matrix.php --json` (статический каталог) прогнан отдельно.
  Сравнение кортежей `(from, to, category, isAi, stream)`:
  - before (DB-backed, родитель 9a2df2a): **394** пары, routingKeys `{ai, audio, data, document, image, video}`
  - after (статический каталог, HEAD): **394** пары, routingKeys `{ai, audio, data, document, image, video}`
  - added: **0**, removed: **0**, changed: **0**
  Полное совпадение подтверждено реальным DB-backed прогоном (не предположением о неизменности golden-фикстуры). Команды и json-снапшоты сохранены в scratchpad (`backup_before-db-backed.json`, `backup_after-static-catalog.json`).
  ⚠️ Побочный инцидент при подготовке: первая попытка скопировать старые файлы через `docker cp` в контейнер `xakki-convertor-test-php` случайно попала на `/app-symfony`, который смонтирован в контейнер как bind-mount РЕПОЗИТОРИЯ хоста (`rw`) — 12 файлов на хосте кратковременно откатились к до-9a2df2a содержимому. Обнаружено сразу по system-reminder, восстановлено через `git show HEAD:<path>` для 11 файлов и `git show HEAD:...ConversionRegistry.php` + повторное применение правки item 1 для двенадцатого. Финальный `git status`/`git diff` подтверждён — расхождений с намеченными правками нет. Повторный прогон сделан через контейнер-локальную копию (`/opt/old-app-symfony`, не bind-mount) — риска повтора нет.

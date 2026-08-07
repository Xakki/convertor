### Удалить seed-строки и снять спец-обработку __seed__

**Criticality:** High

**TAGS:**
- tech-debt

**Description:**
Часть эпика CNV-71. Выполнять ТОЛЬКО ПОСЛЕ CNV-71-01, CNV-71-02, CNV-71-03 — иначе при пустой `worker_capabilities` сайт останется с пустым списком форматов и 400 на любую конвертацию.

**Problem:**
Seed-строки `instance_id='__seed__'` (6 строк) сегодня — единственная гарантия «матрица не опустеет» и требуют спец-обработки в 6 местах кода.

**Impact:**
Пока seed-строки существуют, каталог форматов и код обрастают спец-обработкой в 6 местах — постоянный источник рассинхрона и дублирования логики.

**Recommendation:**
Удалить 6 строк `instance_id='__seed__'` (миграцией, а не админ-кнопкой — кнопка их сознательно исключает) и вычистить спец-обработку в перечисленных 6 местах, оставив только то, что осмысленно без seed (например, блокировку регистрации под зарезервированным instanceId можно сохранить):
- участие в матрице (`ConversionRegistry`)
- исключение из GC (`WorkerCapabilityGcService.php:52`)
- исключение из `markSilentDisconnected()` (`WorkerCapabilityRepository.php:268-289`)
- исключение из admin bulk-delete `deleteStaleByStatus()` (`WorkerCapabilityRepository.php:317-335`)
- бейдж в админке (`WorkerStatsProvider.php:51,108,252,362`)
- блокировка регистрации под этим instanceId (`WorkerController.php:50,68-96`)
- **(recon-находка при выполнении CNV-71-04)** бейдж/ветвление `w.isSeed`/`host.hasSeed` в `templates/admin/workers.html.twig` — не было отдельным пунктом списка выше, но это тот же класс спец-обработки (Alpine.js читает поля `isSeed`/`hasSeed`, которые уходят из бэкенда вместе с остальной спец-обработкой): комментарий-блок, бейдж хоста "Seed", бейдж/две альтернативные `x-if`-ветки строки воркера (провенанс "из seed-миграции" vs "из register()"), `hasSeed` в JS data model — схлопнуть в единственную non-seed ветку.

Проверить, что при полностью пустой `worker_capabilities` сайт по-прежнему показывает все форматы, а создание конвертации даёт понятный отказ, а не 400 «формат не поддерживается».

**Acceptance Criteria:**
- Миграция удаляет 6 строк `instance_id='__seed__'`
- Спец-обработка `__seed__` вычищена во всех 6 перечисленных местах (кроме осмысленных исключений), включая бейдж/ветвление в `templates/admin/workers.html.twig`
- При пустой `worker_capabilities` `/api/v1/formats` и SEO-страницы по-прежнему отдают полный каталог форматов
- При пустой `worker_capabilities` создание конвертации даёт понятный отказ («конвертация временно недоступна»), а не 400 «формат не поддерживается»
- Tests/QA green: `make phpstan`, `make cs-check`, тесты — см. CLAUDE.md

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Порядок выполнения строго после CNV-71-01..03 — подтверждено пользователем 2026-08-04

**Execution Log:**
- Миграция `app-symfony/migrations/Version20260807060000.php`: `DELETE FROM worker_capabilities WHERE instance_id='__seed__'`. `down()` — осознанный no-op с комментарием (не `throwIrreversibleMigrationException()`) — repo-прецедент того же класса миграций (`Version20260801120000.php`, junk-очистка той же таблицы) делает именно так; это расхождение с исходным предположением карточки зафиксировано, следуем прецеденту репозитория.
- **PHP-код (спец-обработка `__seed__` вычищена):** `WorkerCapabilityGcService.php` (const + TTL-DELETE clause), `WorkerCapabilityRepository.php` (const + `markSilentDisconnected()` + `deleteStaleByStatus()`; `existsForWorkerType()` НЕ тронут, по требованию карточки), `WorkerStatsProvider.php` (const, `isSeed`-поле, `hasSeed`-агрегат, сортировка). `Controller/Api/WorkerController.php::RESERVED_SEED_INSTANCE_ID` + тест на него — оставлены как есть (осмысленный guard).
- **Twig (`templates/admin/workers.html.twig`):** убраны оба бейджа "Seed", обе `x-if`-ветки схлопнуты в единственную non-seed, `hasSeed`/`isSeed` убраны из JS data model и confirm-диалога.
- **Doc-only:** `WorkerLivenessStatus.php` (переформулирован смысл `Unknown` — DB-DEFAULT, который теперь НИКОГДА не пишется прикладным кодом, `upsert()` всегда пишет `alive`), `Schedule.php`, `config/services.yaml`, `Controller/Admin/Api/WorkerController.php` (комментарий), `ConversionRegistry.php` (докблок `OCR_SOURCES` — raster-OCR-пары теперь из статического каталога, не из DB-строки). Дополнительно (найдено при вычистке, не было в исходном списке карточки, но напрямую противоречило изменённому коду): `WorkerLivenessReconciler.php` (условие (d) сверки описывало удалённое seed-исключение) и `Controller/Api/InternalWorkerController.php` (комментарий про Unknown).
- **Тесты:** `ConversionManagerWorkerAvailabilityFunctionalTest` упрощён (убран DELETE-в-транзакции workaround, но DELETE оставлен как belt-and-braces для самодостаточности теста); `ConversionManagerWorkerAvailabilityTest` (unit) — обновлён докблок "unreachable"→"reachable"; `WorkerCapabilityGcServiceTest` — удалён `testAncientSeedRowSurvivesGc` (премиса исчезла), удалён `testMixedBatchDeletesOnlyNonSeedStaleRow` (та же причина), `testJunkTestWorkerInstanceIsAlwaysDeleted` переписан без seed-сиблинга; `WorkerCapabilityRepositoryTest` — удалены `testRealRegisterUpsertsAlongsideSeedRowWithoutUniqueViolation` и `testExistsForWorkerTypeTrueForSeedOnlyRow` (обе премисы исчезли, обе тавтологичны с уже существующими тестами composite-key/exists-behaviour); `InternalWorkerControllerTest` — удалён `testSeedRowIsNeverOfflinedByReconcile` (премиса исчезла); `WorkerControllerTest` (admin) — удалён `testDeleteStaleExcludesSeedRows` (премиса исчезла), `testWorkersReturnsStructuredJsonForAdmin` урезан до базовой структуры (убран seed-блок), `testWorkersReflectsRealNonSeedInstance`→`testWorkersReflectsRealInstance`, null-host-bucket тесты (`testWorkersHostsAggregatesPerHost...`, `testWorkersHostNullFilterReturnsLegacyNullBucket`) получили собственные no-host-фикстуры вместо неявной зависимости от seed-строк; `WorkerStatsProviderTest` (unit) — удалён `testSeedRowIsFlaggedIsSeedWithUnknownStatusRegardlessOfAge` (премиса исчезла), сортировочный тест переписан без `__seed__` в качестве instanceId, `hasSeed`-ассерты убраны из host-агрегатных тестов.
- **Проверка «миграции реально накатываются на тест-БД»:** `make TEST=1 test-up` = `up migrate` → `doctrine:migrations:migrate --no-interaction` в php-контейнере — да, реальные миграции, не дамп/фикстура. Подтверждено live: `doctrine:migrations:status` после `test-up` показал `Current = Version20260807060000`, прямой SQL-запрос — `SELECT COUNT(*) ... WHERE instance_id='__seed__'` = 0.
- **Поведенческие AC (реальный прогон):** 503 `worker_unavailable` — `ConversionManagerWorkerAvailabilityFunctionalTest::testNoWorkerRowAgainstRealEmptyTableRejectsWithWorkerUnavailable` зелёный против реальной БД; полный каталог при пустой таблице — `FormatsCatalogIndependenceTest` (обе проверки, `/api/v1/formats` = 394 пары + `/convert/csv-to-json` рендерится) зелёный.
- **QA:** `make phpstan` — 0 ошибок (обе конфигурации, основная + `phpstan-migrations.neon`). `make cs-check` — 0 файлов с расхождениями. `make TEST=1 test-php` — 722 теста, 3193 assertions, 2 failures (оба — известные CNV-60 `ConversionTextInputControllerTest`, `BillingMode` enum нельзя замокать, НЕ связаны с этой карточкой).
- Ветка `epic/CNV-71`, не мержил, не пушил. Карточка остаётся в `progress/` — переход в `test/`→`ready/` за тимлидом.
- **Итог проверок:** `phpstan` — 0 ошибок (обе конфигурации); `cs-check` — 0 из 274; `test-php` — 722 теста / 3193 assertions / 2 известных падения CNV-60; `test-python` — 318 passed; `test-gateway` — 217 passed; `test-drift` — 22 passed; `docker-check` dev+test ok; в тестовой БД 0 строк `__seed__`; `/api/v1/formats` на dev отдаёт 394 пары.

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
  дедуп пар при построении матрице.
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

**Контекст для критерия (a):** Ревью карточки `[[registry-04-matrix-tooling-tests]]` нашло TOCTOU в `bin/dump-matrix.php` — скрипт проверяет репозиторий напрямую, но `getSupportedFormats()` делает ВТОРОЙ независимый запрос к БД, и при сбое между ними реестр молча отдавал бы ~90 пар хардкода, а проверка `$formats === []` этого не ловит, потому что хардкод не пуст. Эта находка закрывается именно здесь: после удаления фолбэка проваливаться некуда — пустая матрица ловится существующей проверкой, исключение даёт ненулевой выход.

**Acceptance Criteria:**
- `workerCapabilities()`/`buildMatrixFromHardcode()` удалены из кодовой базы, нет остаточных
  вызовов/ссылок.
- Матрица строится только из БД; submit на Stage-7-паре отдаёт 400 (не accept→DLQ).
- Несколько инстансов одного `worker_type` (разный `instance_id`) корректно объединяются в
  матрице без дублей пар; коллизия pair'ов между типами разрешается по `NON_AI_PRECEDENCE`
  (registry-03: явный приоритет между non-AI worker-type'ами; last-write — только тай-брейк
  при РАВНОМ ранге, напр. несколько инстансов одного типа). [ИСПРАВЛЕНО при выполнении
  registry-05 — было устаревшее "non-AI precedence, last-registered-wins", см. Execution Log.]
- Поведение на пустой БД задокументировано и покрыто тестом.
- Сигнатуры `getSupportedFormats/isSupported/streamFor/isAi` не изменились; все перечисленные
  вызывающие места компилируются и работают без изменений на своей стороне.
- `test_routing_drift.py` (переписанный в [[registry-04-matrix-tooling-tests]]) и
  `ConversionRegistryGoldenTest` — зелёные без хардкод-fallback.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest.
- (a) Никаких молчаливых непустых фолбэков. После удаления хардкода `buildRoutingPairs()`
  больше не имеет куда «провалиться»: его текущий `catch (\Throwable)` → `buildMatrixFromHardcode()`
  физически не может пережить удаление. Любой путь ошибки БД или пустого результата — включая
  ВНУТРЕННИЙ повторный `findAllCapabilities()` внутри `getSupportedFormats()` — обязан либо
  бросить исключение, либо вернуть пустую матрицу. ЗАПРЕЩЕНО заменять хардкод любым другим
  непустым фолбэком (устаревший кэш, «последнее известное хорошее» значение) — это воспроизведёт
  ровно ту проблему, которую снятие хардкода решает.
- (b) Поведение при пустой БД определено явно: честная пустая матрица (и, как следствие, 400
  на submit и пустой список в `/formats`), а НЕ подстановка каких-либо значений. Это прямое
  следствие решения D1 (seed заменяет фолбэк), а не новый выбор дизайна.
- (c) Инструменты из `[[registry-04-matrix-tooling-tests]]` перепроверяются ЗАНОВО после
  удаления хардкода, а не наследуют зелёный статус той карточки: прогнать `bin/dump-matrix.php --json`,
  прогнать drift-тест, заново сверить golden-фикстуру. Причина: эта карточка меняет ровно тот
  код, который те гейты проверяют — уже был прецедент, когда изменение в одной карточке молча
  перенацеливало гейт, доказанный в другой (`[[registry-03-seed-migration]]`).

**Decisions:**
- Груминг 2026-07-22: удаление хардкода — четвёртый, не первый шаг Phase 2 (см. порядок
  подкарточек в эпике) — сначала схема+seed+тесты, потом снос старого источника.

**Зависит от:** `[[registry-04-matrix-tooling-tests]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress

## Execution Log (backend-php)

- Удалены `ConversionRegistry::workerCapabilities()` и `buildMatrixFromHardcode()`
  целиком (161 строка) — репозиторий теперь единственный источник матрицы.
  Остаточных вызовов не найдено (`grep -rn workerCapabilities\|buildMatrixFromHardcode
  app-symfony/src app-symfony/tests app-symfony/bin` — пусто).
- `buildRoutingPairs()` переписан: три вырожденных пути (repository===null,
  DB-исключение, пустая таблица) отдают ЧЕСТНУЮ пустую матрицу — НЕ throw, НЕ
  хардкод-фолбэк. `repository===null` — тихо (чисто тестовый кейс, autowiring
  всегда даёт репозиторий в проде). DB-исключение и пустая таблица — оба логируют
  `error()` (громко, как требует (b): "означает, что миграции не прогнаны или
  таблицу truncate-нули") и возвращают `[]`.
- **Важная находка ревью (advisor), не описанная в карточке явно, но подпадающая
  под запрет (a) на "любой другой скрытый непустой фолбэк":** кеш матрицы
  (`cache.app`, TTL 1ч, `buildMatrix()`) кешировал бы РЕЗУЛЬТАТ `buildRoutingPairs()`
  без разбора — включая пустой/ошибочный. Кратковременный blip БД на холодном
  кеше замораживал бы честную пустую матрицу на весь TTL: секундный сбой →
  часовой отказ `/formats` и submit. Исправлено через `$save = ($pairs !== [])`
  в callback `cache->get()` (Symfony `ItemInterface`/`bool &$save` контракт) —
  пустой/ошибочный результат больше не персистится, следующий запрос снова
  бьёт в БД. Покрыто новым тестом `testCacheDoesNotPersistEmptyOrErrorResult`
  (две инстанции `ConversionRegistry` на общем `ArrayAdapter`-кеше: первая
  ловит throw → пустая; вторая должна снова обратиться к БД и увидеть
  восстановленные данные).
- `OCR_RASTER` (константа, использовалась только внутри удалённого хардкод-блока)
  оказалась мёртвой — phpstan level 8 поймал сразу (`unused class constant`).
  Удалена; docblock `OCR_SOURCES`/`OCR_TARGETS` поправлен (raster-пары теперь
  приходят напрямую из seed-строки image-воркера, а не из константы).
- Тесты: создана `tests/Support/ConversionRegistrySeedFixture.php` — намеренный
  литеральный дубль `Version20260722150301::seedRows()` как `WorkerCapability[]`
  (не reflection в миграцию — миграции не библиотека) + trait
  `tests/Support/SeedsConversionRegistry.php` (`$this->newSeedRegistry()`, нужен
  как trait, а не статик-метод, т.к. `TestCase::createStub()` — `protected`).
  Заменено ~16 вызовов `new ConversionRegistry()` без аргументов (implicit
  hardcode-фолбэк) на `$this->newSeedRegistry()` в 8 тестовых файлах — во всех
  проверено ПОКРЫТИЕ каждой тестируемой пары в seed-данных перед заменой.
- **Найдена и исправлена одна реальная рассинхронизация ассерта** (не просто
  механическая замена конструктора): `ConversionManagerTextInputTest::
  testTextInputReachesS3AndDispatchesToDocumentStream` ожидал
  `getCategory()->value === 'markup'` для md→html — это была категория из
  СТАРОГО хардкода (отдельный блок `'markup'`, которого в DB/seed никогда не
  было — `NOT` предположение, а факт: golden-фикстура уже фиксирует
  `md->html = document|document|0`). Тест эксплуатировал тот факт, что
  `new ConversionRegistry()` без аргументов давал ему хардкод, а не реальный
  DB-путь — т.е. с registry-03 этот тест УЖЕ не отражал прод-поведение, просто
  никто не проверял. Ассерт исправлен на `'document'`, добавлен комментарий-
  объяснение. **Это единственное изменение ассерта во всей задаче** — все
  остальные замены чисто механические (конструктор), т.к. заранее проверено
  совпадение категории/isAi для каждой пары.
- `ConversionRegistryFallbackTest.php` переписан: убраны `testUsesHardcodedFallback
  WhenDbEmpty/WhenDbUnreachable/WithNoRepository`, `testDbPathAiPairsMatch
  HardcodedFallback` (все ссылались на удалённый хардкод); добавлены
  `testEmptyMatrixWhenNoRepository`, `testEmptyMatrixAndLoudErrorWhenDbUnreachable`,
  `testEmptyMatrixAndLoudErrorWhenDbEmpty`, `testCacheDoesNotPersistEmptyOrErrorResult`.
  Остальные тесты (non-AI precedence, union инстансов, AI matrix_categories и т.д.)
  — без изменений в логике, только докблок класса.
  Заодно: `testInvalidateMatrixResetsPerRequestCache` использовал `createMock()`
  без `expects()` — PHPUnit 13 стал ловить это как Notice ("No expectations were
  configured... use a stub instead"); заменено на `createStub()`.
- Побочная зачистка drift-докблоков (не только код, но и упоминания): класс-докблок
  `ConversionRegistry`, докблок конструктора, `CuratedConversionPairs.php`,
  `WorkerCapability.php` (entity), комментарий в `ConversionManagerOcrTest.php`
  ("Archive was removed from workerCapabilities()"), докблок
  `ConversionRegistryGoldenTest.php`, докблок `bin/dump-matrix.php` — все
  ссылки на удалённые методы поправлены на актуальное поведение.
- **Multi-instance union (registry-02)** — код `buildMatrixFromCapabilities()`
  не тронут этой карточкой; `testUnionsPairsFromTwoInstancesOfSameWorkerType`
  прогнан в составе полного сьюта — зелёный, без изменений. Подтверждено
  недоступность влияния удаления хардкода на union-путь.
- **Callers не менялись** (сигнатуры `getSupportedFormats/isSupported/streamFor/
  isAi` не тронуты) — `ConversionToggleController`, `ConversionController`,
  `ConversionPageController`, `ConversionManager` покрыты Functional-тестами,
  все зелёные в общем прогоне.
- **Критерий (c) — переверификация registry-04 tooling против НОВОГО кода:**
  - `bin/dump-matrix.php --json` (APP_ENV=test, реальная контейнерная БД) —
    exit 0, непустая матрица.
  - `bin/dump-matrix.php` (text) сверен `diff` с `tests/Fixtures/conversion_matrix
    .golden.txt` — **NO DRIFT, побайтово совпадает**. Ожидаемо: хардкод был
    мёртв при рантайме с registry-03, снятие фолбэка не меняет DB-путь.
  - Отдельно проверена TOCTOU-цель (registry-04 review finding): запуск
    `dump-matrix.php` с намеренно недостижимым `DATABASE_URL` (порт 1,
    connection refused) → exit 1, честный stderr
    ("worker_capabilities DB unreachable: ... Connection refused"), stdout
    пуст — не печатает правдоподобный, но пустой документ. Раньше при сбое
    МЕЖДУ прямой проверкой репозитория и внутренним вызовом
    `getSupportedFormats()` реестр мог тихо отдать ~90 хардкод-пар; теперь
    провалиться некуда — эта дыра закрыта самим фактом отсутствия фолбэка,
    без изменений в самом `dump-matrix.php`.
  - `make test-drift` (`workers/tests/test_routing_drift.py`) — 2/2 passed.
- **QA (все зелёные):**
  - `make phpstan` — `[OK] No errors` (level 8, после удаления `OCR_RASTER`).
  - `make cs` → `make cs-check` — `Found 0 of 200 files that can be fixed`.
  - `make test-php-live` (провижининг test-DB + `test-php` + `test-drift`) —
    `OK (429 tests, 1772 assertions)`, drift 2/2 passed.
- **Расхождений с постановкой карточки, требующих остановки, не найдено** — команда
  тимлида "хардкод уже мёртв при рантайме, вы удаляете недостижимый код, а не
  меняете поведение" подтверждена: golden-фикстура не изменилась (0 delta).
  Единственное расхождение — описанный выше кеш-landmine (advisor review),
  не поведенческая смена production-пути, а latent-бага, которую ЭТА карточка
  и обязана была закрыть по духу критерия (a) ("никакого другого скрытого
  непустого фолбэка") — исправлено в рамках той же задачи, не отдельной карточкой.
- Стейл-пункты карточки исправлены (см. Acceptance Criteria выше): формулировка
  коллизий обновлена на `NON_AI_PRECEDENCE` + last-write-only-at-equal-rank;
  multi-instance union — подтверждён неизменным, отдельного кода не потребовалось.

### Доп. раунд ревью — закрыты 2 найденные тимлидом дыры покрытия

- **Sync-guard фикстуры vs миграции:** добавлен
  `tests/Unit/Support/ConversionRegistrySeedFixtureSyncTest.php` —
  reflection-вызов `Version20260722150301::seedRows()` (класс миграции не
  под Composer PSR-4, поэтому `require_once` файла миграции перед рефлексией)
  и побайтовое `assertSame` с данными `ConversionRegistrySeedFixture::capabilities()`.
  Рефлексия в саму миграцию — осознанный выбор (не то же самое, что рефлексия
  из ПРОД-кода, которую избегали изначально: там причина была не тащить
  runtime-зависимость на миграцию; тест на такую зависимость не завязан).
  Тест зелёный (данные сегодня идентичны), при расхождении в будущем упадёт
  явно, а не "почини golden — и мимо".
- **Позитивный кейс кеша:** добавлен sibling-тест `testCachePersistsHealthyResult`
  рядом с `testCacheDoesNotPersistEmptyOrErrorResult` — две инстанции
  `ConversionRegistry` на общем `ArrayAdapter`, здоровые данные, второй запрос
  не должен снова бить в БД (`assertSame(1, $callCount, ...)`). Проверено
  вручную (не автоматизированный шаг, разовая sabotage-проверка перед
  коммитом): временно заменил `$save = $pairs !== []` на `$save = false`,
  прогнал только этот тест — упал ожидаемо ("2 identical to 1"), затем вернул
  строку обратно, `git diff` по файлу — пусто, подтверждено что откат чистый.
- QA перепрогнан целиком после обоих добавлений: `make phpstan` — `[OK] No
  errors`; `make cs` → `make cs-check` — `Found 0 of 201 files that can be
  fixed`; `make test-php-live` — `OK (431 tests, 1777 assertions)`, drift 2/2.

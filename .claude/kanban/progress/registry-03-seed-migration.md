### Seed-миграция: матрица в БД, чтобы она никогда не была пустой

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Второй шаг Phase 2 эпика `[[registry-00-self-registration]]`. Готовит почву для удаления
хардкода (`[[registry-05-drop-hardcode]]`): Doctrine-миграция заливает текущий снапшот
`ConversionRegistry::workerCapabilities()` (`app-symfony/src/Service/Conversion/ConversionRegistry.php:374`)
в таблицу `worker_capabilities`, по одной строке на worker-type — так БД никогда не оказывается
пустой даже до того, как хоть один воркер успел зарегистрироваться (D1: fallback-хардкод
убирается, вместо него — seed).

**Problem:**
После удаления `workerCapabilities()`-fallback (шаг [[registry-05-drop-hardcode]]) пустая/только
что поднятая БД оставила бы `buildRoutingPairs()` без единой пары — `/formats` и submit
отваливаются до первого успешного register любого воркера (окно простоя при деплое/рестарте
БД).

**Impact:**
Без seed — регрессия доступности API на старте системы; с seed — деградация плавная (снапшот
устаревает до первого live register, но не исчезает).

**Recommendation:**
- Новая Doctrine-миграция, читающая текущий хардкод-снапшот (по состоянию на момент написания
  миграции — статичные данные внутри самой миграции, не runtime-чтение кода) и заливающая по
  одной строке `WorkerCapability` на worker-type с зарезервированным seed-значением `instance_id`
  (напр. `"__seed__"`), используя схему `(worker_type, instance_id)` из `[[registry-02-schema-multi-instance]]`.
  Идемпотентность: повторный прогон миграции (или повторное применение на уже засеянной БД) не
  дублирует строки — INSERT IGNORE / проверка существования по составному ключу перед вставкой.
- Явно НЕ включать в seed Stage-7 «coming-soon» пары (xls/xlsx/ods/csv→pdf, ppt/pptx/odp→pdf,
  dwg/dxf→pdf/svg/png, pdf→jpg) — они существуют только в хардкод-fallback и по решению
  [USER DECISION 2026-07-01, зафиксировано в эпике] должны исчезнуть, а не мигрировать в БД.
  `workers/libreoffice/worker.py:86-98` их сознательно не декларирует — seed должен быть
  согласован с реальными Python CAPABILITIES, а не с полным хардкод-списком PHP.
- Живая регистрация воркера (`POST /worker/register`) ПЕРЕЗАПИСЫВАЕТ seed-строку того же
  `worker_type` (при первом реальном register `instance_id` заменяет/дополняет `__seed__`-строку
  реального инстанса) — это и есть механизм устаревания снапшота: seed живёт только до первого
  живого register своего типа, дальше матрица отражает реальность. Зафиксировать явно, что
  seed-строка НЕ удаляется автоматически при появлении реального инстанса другого `instance_id`
  того же типа (несколько инстансов сосуществуют по схеме registry-02) — вопрос вывода
  устаревшего `__seed__` из выдачи решается TTL-механизмом [[registry-06-liveness-push]], не здесь.

**Acceptance Criteria:**
- Миграция применяется на чистой БД без ошибок; таблица `worker_capabilities` после миграции
  содержит по строке на каждый worker-type из текущего хардкода, БЕЗ Stage-7 пар.
- Повторное применение (или запуск на уже засеянной БД) не создаёт дублей.
- Реальный register того же worker-type успешно апсертит поверх seed-строки (не конфликтует
  по UNIQUE-схеме из `[[registry-02-schema-multi-instance]]`).
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- Груминг 2026-07-22: seed заменяет отклонённый вариант «эмулировать хардкод в рантайме» (D1) —
  простой снапшот в миграции, устаревающий через живой register, а не вечнозелёный код-путь.

**Зависит от:** `[[registry-02-schema-multi-instance]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress

**Execution Log (2026-07-22, PHP-зона):**

Файлы: `app-symfony/migrations/Version20260722150301.php` (новый),
`app-symfony/tests/Functional/Repository/WorkerCapabilityRepositoryTest.php`.

- **Снапшот сверен с реальными Python CAPABILITIES**, не с PHP-хардкодом
  `ConversionRegistry::workerCapabilities()`: прочитаны READ-ONLY
  `workers/libreoffice/worker.py` (`_MATRIX`, L86-101), `workers/image/worker.py`
  (`_MATRIX`, L67-78), `workers/ffmpeg/worker.py` (`SUPPORTED`/`AUDIO_CAPABILITIES`/
  `VIDEO_CAPABILITIES`, L63-123), `workers/data/worker.py` (`SUPPORTED`, L20-27),
  `workers/ai/worker.py` (`CAPABILITIES`, L40-65). Засеяно **6 строк**, по одной на
  реальный `workerType`, который Python реально шлёт в `POST /worker/register`
  (`document`, `image`, `audio`, `video`, `data`, `ai`) — НЕ 7-й хардкод-блок
  `'markup'` (несуществующий отдельный воркер, PHP схлопывает его в `document`
  только на этапе роутинга, `workerCapabilities()` его никогда не регистрирует).
  Пар: document 10 from-ключей, image 10, audio 8, video 8, data 6, ai 8 (по
  количеству source-форматов в соответствующей матрице).
- **Stage-7 пары подтверждённо отсутствуют** — сверено построчно: в
  `_MATRIX`/`SUPPORTED`/`CAPABILITIES` реальных воркеров нет ключей
  `xls/xlsx/ods/csv→pdf` (data-воркер поддерживает csv только data-форматами,
  не pdf), `ppt/pptx/odp`, `dwg/dxf`, `pdf→jpg` (`libreoffice` даёt `pdf` только
  в `{docx,txt,md}`; `pdf→jpg` вообще нигде не объявлен на Python-стороне).
  Комментарий над `_MATRIX` в `workers/libreoffice/worker.py:78-83` явно
  подтверждает: «Stage-7 pairs... intentionally absent».
- **Форма seed-блоба** — полный `register`-payload (не только `matrix`), включая
  `workerType`/`instanceId`/`streams`/`routingKeys`/`matrix_categories`/`image`/
  `version`, той же формы, что реальное тело `_build_register_body()` из
  `workers/common/ws_client.py`, — по требованию «seed неотличим по форме от
  реальной регистрации». `matrix_categories` заполнена только для `ai`
  (audio/document по source-формату), для остальных — пустой массив (как и в
  реальном wire-теле: Python всегда шлёт ключ, просто пустым для non-AI).
  `version` = строка `'seed'` (не используется PHP-логикой нигде — проверено
  grep — чисто для отличимости в БД/админке от реальной семверсии).
- **`instance_id = '__seed__'`** — проверено против контракта registry-02
  (`^[A-Za-z0-9._:-]+$`, непустая, ≤128 символов): `_` разрешён явно в
  charset-классе контроллера, `__seed__` проходит.
- **Ревью-фикс:** `__seed__` не был зарезервирован — реальный воркер мог
  случайно/явно (`WORKER_INSTANCE_ID=__seed__`) занять этот instanceId, и его
  строку молча снёс бы `down()` этой миграции (удаляет строго по литералу).
  `WorkerController::validateRegisterPayload()` теперь отклоняет
  `instanceId === '__seed__'` 400-кой (`RESERVED_SEED_INSTANCE_ID` — литерал
  задублирован в миграции с комментарием, миграция намеренно не зависит от
  кода приложения); добавлен тест `reserved instanceId` в
  `WorkerRegisterControllerTest::invalidPayloadProvider`.
- **Идемпотентность** — `INSERT IGNORE` на составном `UNIQUE(worker_type,
  instance_id)`. Проверено вживую на dev: повторный `INSERT IGNORE` тем же
  набором строк напрямую через `doctrine:query:sql` (в обход
  migration-tracking, который сам не даёт повторно отметить версию как
  применённую) — `0 rows affected`, счётчик `__seed__`-строк остался 6. Тестов
  на саму миграцию в проекте нет прецедента (грепнул `tests/` — 0 совпадений на
  `AbstractMigration`), идемпотентность SQL подтверждена вручную, а не юнит-тестом.
- **Коллизия с существующими `legacy`-строками отсутствует по конструкции**:
  на dev БД уже было 6 строк `instance_id='legacy'` от бэкофилла
  `registry-02` (реальные регистрации, случившиеся до этой карточки) — seed
  добавил ЕЩЁ 6 строк с другим `instance_id`, итого 12 (не перезаписал и не
  конфликтовал). На чистой тест-БД (`convertor-test`, пересоздаётся каждый
  прогон `test-db-migrate`) `worker_capabilities` пуста до этой миграции →
  seed стал первыми 6 строками; полный прогон `test-php-live` (426/426,
  включая уже существовавшие функциональные тесты `/formats`, submit-гейты,
  golden/drift) остался зелёным — засеянные данные не меняют поведение
  публичных эндпоинтов относительно прежнего hardcoded-fallback (та же
  форма пар, Stage-7 так же отсутствует).
- **Тест на сосуществование seed+live** (акцептанс-критерий «реальный register
  апсертит поверх seed без конфликта UNIQUE»):
  `testRealRegisterUpsertsAlongsideSeedRowWithoutUniqueViolation` — seed-строка
  (`instance_id='__seed__'`) + live-register (другой `instanceId`) сосуществуют
  как 2 строки одного `workerType`; повторный upsert по `__seed__` обновляет
  seed in place, не трогая live-строку и не дублируя.
- **Judgment calls**: (1) не трогал `workerCapabilities()`/
  `buildMatrixFromHardcode()` — вне зоны (registry-05). (2) `version: 'seed'`
  вместо `null` — выбрано для отличимости в будущей admin-странице
  (registry-07), функционально не используется нигде в PHP. (3) Отдельная
  PHPUnit-миграционная проверка идемпотентности не писалась — в проекте нет
  прецедента тестировать миграции напрямую, идемпотентность подтверждена
  live-прогоном на dev (см. выше) и косвенно — стабильным row-count в
  тест-БД при каждом полном `test-php-live` (БД пересоздаётся с нуля на
  каждый прогон, так что «повторный прогон на уже засеянной БД» тест-сьютом
  не покрывается напрямую, только ручной проверкой).
- **QA**: `make phpstan` — 0 ошибок (`migrations/` вне `phpstan.neon` paths,
  сканируется только `src/` — миграция проверена компиляцией/применением, а не
  статическим анализом). `make cs` → `make cs-check` — 0 файлов на исправление.
  `make test-php-live` — 426/426 зелёных (1 pre-existing notice, тот же, что и
  в registry-02, не regression). Миграция применена на dev через `make migrate`.

**Ревью-фикс #2 (2026-07-22): DB-путь включился уже здесь, не в registry-05, и
вскрыл mis-routing pdf→document.** `buildRoutingPairs()` уходит на hardcoded
fallback ТОЛЬКО когда список capability-рядов из БД пуст; seed делает его
непустым — значит `buildMatrixFromHardcode()`/`workerCapabilities()`
фактически МЁРТВЫ на рантайме на любом смигрированном окружении уже с этой
карточки, а не с `[[registry-05-drop-hardcode]]`, как предполагалось при
груминге эпика (сам код удалять здесь всё равно не стали — вне зоны).
Full pair-set diff (hardcode vs засеянная БД) регрессий по «пропавшим парам»
не нашёл, но нашёл ОДИН реальный баг: `pdf→docx/md/txt` стали резолвиться в
category/stream `image` вместо `document` — обе seed-строки (`document` и
`image`) честно объявляют эту пару (libreoffice — plain poppler-извлечение
текста; image-воркер — OCR-ветка, тоже принимает pdf source), а
`buildMatrixFromCapabilities()` умела разруливать только non-AI vs AI,
между двумя non-AI коллизии не было — побеждал тот, чья строка обработана
последней (зависело от порядка `findAllCapabilities()`, т.е. от PK/порядка
БД). Итог до фикса: любое обычное (без флага `ocr`) `pdf→txt` уезжало на
OCR-воркер — вопреки собственному докблоку класса (OCR — только по флагу).
**USER DECISION: фиксить tie-break'ом в `ConversionRegistry`, не трогая то,
что декларируют воркеры** (воркеры остаются flag-agnostic и честно
объявляют возможности; бэк выбирает флаг/stream). Добавлена
`NON_AI_PRECEDENCE = ['document','data','audio','video','image']` (индекс =
ранг, меньше = выше приоритет) + `nonAiPrecedenceRank()`; в
`buildMatrixFromCapabilities()` при коллизии между двумя non-AI строками
побеждает строго более высокий ранг, независимо от порядка строк (при
равном ранге — как раньше, last-write, для мульти-инстансов одного типа).
Явно помечено как INTERIM — снимается Phase 3 multi-candidate router эпика,
где `pdf→txt` (document-extract vs image-OCR) — эталонный кейс. Тест
`testDocumentWinsOverImageForOverlappingPdfPairs` — оба порядка рядов дают
`document`. Живая проверка на dev (`GET /formats`): `pdf→docx/md/txt` теперь
`"category":"document"` (было `"image"` до фикса), `ocrCapable:true`
сохранился (флаг-путь на OCR не тронут, отдельная ветка `streamFor(ocr=true)`).
QA: phpstan/cs-check чисто, `test-php-live` 428/428 зелёных.

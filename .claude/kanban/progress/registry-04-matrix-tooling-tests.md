### Восстановить dump-matrix + переписать drift-тест на register-round-trip

**Criticality:** High

**TAGS:**
- tech-debt
- test

**Description:**
Третий шаг Phase 2 эпика `[[registry-00-self-registration]]`, идёт ДО удаления хардкода
(`[[registry-05-drop-hardcode]]`), чтобы тестовая обвязка не покраснела на следующем шаге.
Закрывает молчаливую регрессию, найденную при груминге: инструмент `app-symfony/bin/dump-matrix.php`
был случайно удалён 2026-07-10 коммитом `2105d70` (не связанный с реестром коммит про auth), из-за
чего `workers/tests/test_routing_drift.py` (загрузчик PHP-стороны `_load_php_matrix()` L75-96)
последние ~12 дней **тихо скипается** (`pytest.skip` L92-95) вместо падения — drift между PHP и
Python-матрицами всё это время никем не проверялся.

**Problem:**
- `bin/dump-matrix.php` не существует → `test_all_routing_keys_have_worker` (L173-194) и
  `test_worker_matrix_subset_of_registry` (L201-247) не выполняются, а помечаются skip — CI
  зелёный без реальной проверки.
- `ConversionRegistryGoldenTest::__construct()` вызывает `new ConversionRegistry()` без аргументов
  (L31) → `repository=null` → тест ВСЕГДА идёт по хардкод-пути, даже после того как БД станет
  источником истины — golden-фикстура перестанет отражать реальное поведение.
- Docblock-инструкция регенерации golden-фикстуры (L21-23) ссылается на удалённый
  `bin/dump-matrix.php --write`.

**Impact:**
Пока эта карточка не закрыта, следующий шаг ([[registry-05-drop-hardcode]]) либо ломает тесты,
либо (хуже) тесты остаются молча зелёными и не ловят реальный дрейф PHP/Python матриц —
повторение уже случившейся 12-дневной слепой зоны.

**Recommendation:**
- (a) Воскресить `app-symfony/bin/dump-matrix.php`: теперь он должен строить матрицу через
  контейнер/DI, читая `ConversionRegistry` из DB-репозитория (после `[[registry-03-seed-migration]]`
  БД не пуста), а не через `new ConversionRegistry()` без аргументов.
- (b) `workers/tests/test_routing_drift.py` — переписать с «PHP-хардкод vs Python-CAPABILITIES»
  на **register-round-trip**: то, что реально осело в `worker_capabilities` после регистрации
  (объединение задекларированных Python-`CAPABILITIES` всех воркеров) == то, что отдаёт
  `dump-matrix.php`. Убрать skip-на-отсутствие-инструмента — отсутствие `dump-matrix.php`
  должно ПАДАТЬ тест, не пропускать его.
- (c) `ConversionRegistryGoldenTest` — перевести на seeded DB (использовать фикстуру/сид из
  `[[registry-03-seed-migration]]` вместо `new ConversionRegistry()` без аргументов) или
  регенерировать `tests/Fixtures/conversion_matrix.golden.txt` под DB-путь; обновить
  docblock-инструкцию регенерации на актуальную команду.

**Acceptance Criteria:**
- `app-symfony/bin/dump-matrix.php` существует, исполняется, строит матрицу из DB-репозитория.
- `test_routing_drift.py` реально выполняется (не skip) в CI и падает при намеренно внесённом
  расхождении (проверить локально перед сдачей).
- `ConversionRegistryGoldenTest` использует seeded DB, а не хардкод-конструктор без аргументов;
  докблок с инструкцией регенерации актуален.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest (`workers/tests/test_routing_drift.py`).

**Decisions:**
- Груминг 2026-07-22: карточка сознательно поставлена ДО [[registry-05-drop-hardcode]] — правило
  «не удалять источник данных, пока проверяющая его обвязка не переведена на новый источник».

**Зависит от:** `[[registry-03-seed-migration]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress

**Execution Log (2026-07-22, PHP-зона):**

Файлы: `app-symfony/bin/dump-matrix.php` (восстановлен), `app-symfony/config/services.yaml`,
`app-symfony/tests/Functional/Service/Conversion/ConversionRegistryGoldenTest.php` (перемещён из
`tests/Unit/...`, переписан), `app-symfony/tests/Fixtures/conversion_matrix.golden.txt`
(регенерирован). `workers/` НЕ трогал — Python-половину (переписать `test_routing_drift.py` на
register-round-trip) делает отдельный агент против контракта ниже.

- **`bin/dump-matrix.php` восстановлен из `git show 2105d70^:...`** и адаптирован: вместо
  `new ConversionRegistry()` (repository=null → всегда хардкод) грузит полный Kernel (тот же
  `Dotenv::bootEnv()`, что и `bin/console`/`public/index.php` под капотом `symfony/runtime`) и
  достаёт `ConversionRegistry`/`WorkerCapabilityRepository` из контейнера. Оба сервиса помечены
  `public: true` в `services.yaml` — ТОЛЬКО ради этого CLI-скрипта (не Command, отдельный
  standalone-файл, путь фиксирован Python-тестом), приложение продолжает получать их через
  обычный autowire-конструктор. После получения `ConversionRegistry` явно зовёт
  `invalidateMatrix()` — иначе тёплый `cache.app` (Redis, общий с рантаймом) мог бы отдать
  устаревший снапшот вместо текущего состояния БД, что для диагностического инструмента
  недопустимо.
- **Non-zero exit** — два явных чек-пойнта ДО печати чего-либо: (1) `WorkerCapabilityRepository::findAllCapabilities()`
  дёргается НАПРЯМУЮ (в обход `ConversionRegistry`) — если бросает исключение (БД недоступна)
  или возвращает `[]` (таблица пуста), скрипт падает с `exit(1)` и явным сообщением в STDERR,
  НЕ давая `ConversionRegistry` тихо откатиться на хардкод-fallback (который совсем не пуст —
  ~90 пар — и был бы неотличим от валидного DB-снапшота, если бы не эта явная проверка);
  (2) если каким-то образом непустой набор capability-строк всё равно даёт пустую матрицу
  (все ряды нераспознаваемы) — тоже `exit(1)`. Живая проверка: `DATABASE_URL` на
  несуществующий хост → `exit 1` с понятным сообщением (см. ниже).
- **Output contract (--json)** — задокументирован в докблоке файла И передан тимлиду отдельным
  сообщением дословно (см. отчёт): `{"routingKeys": [...], "matrix": [{"from","to","category","stream","isAi"}, ...]}`,
  `matrix` отсортирован по `"{from}->{to}"`. Формат ПОЛНОСТЬЮ идентичен старому (до удаления
  2105d70) — Python-сторона может продолжать парсить так же, как раньше.
- **Живая проверка**: `php bin/dump-matrix.php --json` на dev — 309 пар, `exit 0`, `pdf→docx/md/txt`
  корректно `category:"document"` (подтверждает фикс из предыдущего ревью). `--write` — записал
  фикстуру. Проверка отказа: `DATABASE_URL` на несуществующий хост → `exit 1` с сообщением
  "worker_capabilities DB unreachable: ...".
- **`ConversionRegistryGoldenTest` перемещён** `tests/Unit/Service/Conversion/` →
  `tests/Functional/Service/Conversion/` (namespace вслед за PSR-4), теперь `KernelTestCase`.
  **Judgment call (seed-specific, не «что в БД»):** тест берёт `WorkerCapabilityRepository` из
  контейнера, но ФИЛЬТРУЕТ ряды по `instanceId === '__seed__'` перед тем как скормить их в новый
  `ConversionRegistry($stub)` — не доверяет «что бы ни лежало в таблице целиком». Причина: в
  проекте нет транзакционного отката между тестами (`dama/doctrine-test-bundle` не подключён,
  грепнул `composer.json` — отсутствует), т.е. содержимое `worker_capabilities` за весь прогон
  `test-php-live` НЕ изолировано по тестам сама по себе — только конвенцией (каждый DB-тест сам
  чистит за собой через `tearDown()`). Фильтр по `__seed__` делает golden-тест воспроизводимым
  независимо от того, что ещё могло осесть в общей таблице (сейчас — ничего постороннего, но
  тест не полагается на это как на инвариант). Alternative («что в БД без фильтра») была бы
  фактически идентична СЕЙЧАС (в свежесмигрированной test-БД лежат ровно seed-строки), но
  team-lead прямо просил рекомендацию — рекомендую фильтр: reproducibility > простота, разница
  в коде — 3 строки.
- **Регенерация golden**: `make test-db-setup` (пересоздаёт `convertor-test` и мигрирует с нуля
  → ТОЛЬКО 6 seed-строк, без `legacy`-мусора, который есть на dev от реальных прошлых
  регистраций) → `docker exec -e APP_ENV=test <php> php bin/dump-matrix.php --write`. Докблок
  класса и сообщение ассерта обновлены на эту команду (старая ссылка на голый
  `php bin/dump-matrix.php --write` без `APP_ENV=test` осталась бы неверна — по умолчанию
  скрипт бьёт в DEV БД, где вперемешку legacy+seed).
- **Санити-чек регенерированной фикстуры против предсказанных тимлидом дельт** (diff проанализирован
  скриптом, не глазами): 57 dropped ✓ точное совпадение, 36 added aliases ✓ точное совпадение,
  32 audio→video recategorisation ✓ точное совпадение, `pdf→docx/md/txt` → `document` ✓
  подтверждено (это НЕ показалось как «changed»-строка в diff, потому что уже было `document` и
  в СТАРОЙ хардкод-фикстуре — хардкодный `image`-блок никогда не объявлял `pdf` источником для
  OCR, это и была исходная находка предыдущего ревью; DB-путь с tie-break воспроизводит тот же
  результат, просто другим механизмом). **Найдена ОДНА дельта СВЕРХ списка тимлида**: 10 пар
  `html/md → *` сменили `category` с `markup` на `document` (stream не менялся — уже был
  `document` и раньше, `streamFor()` и так фолдил markup→document на роутинге). Объяснение:
  единственный источник этих пар в DB-пути — реальный `document`-воркер (libreoffice), который
  сам держит md/html в СВОЁМ `_MATRIX`; отдельного `workerType='markup'` нет и никогда не было
  (подтверждено ещё в registry-03) — в хардкоде эти же пары искусственно жили в ОТДЕЛЬНОМ блоке
  `'markup'` (только ради override-порядка), из-за чего несли `category=markup`. Это НЕ баг и не
  результат моего изменения кода — чистое следствие структуры seed-данных vs хардкода. Видимый
  внешний эффект: `GET /formats` для этих 10 пар отдавал `"category":"markup"`, теперь отдаёт
  `"category":"document"`. Не останавливался (STOP) — довёл карточку до конца и явно репортю
  тимлиду для подтверждения, а не тихо благословил результат.
- **QA**: `make phpstan` — 0 ошибок (`bin/` тоже вне scanned paths — как и `migrations/`,
  см. карточку `migrate-diff-schema-drift`/`phpstan-skips-migrations`, не расширял их здесь).
  `make cs` → `make cs-check` — 0 файлов (примечание: `bin/` тоже вне Finder'а
  `.php-cs-fixer.php`, только `src/`+`tests/` — проверил `php -l` вручную, синтаксис чист).
  `make test-php-live` — 429/429 зелёных (тот же pre-existing notice).

#### Python-зона

Файл: `workers/tests/test_routing_drift.py` (полностью переписан). `workers/` больше нигде
не трогал; `app-symfony/bin/dump-matrix.php` НЕ трогал (собственность PHP-зоны, контракт
`--json` использован как есть).

- **Смена источника данных**: было — PHP-хардкод (`workerCapabilities()`) vs Python
  `CAPABILITIES`; стало — **register-round-trip**: живой снапшот `dump-matrix.php --json`
  (читает `WorkerCapabilityRepository`/`ConversionRegistry` из БД, тот же путь, что наполняет
  `POST /worker/register`) vs union `CAPABILITIES` всех `workers/*/worker.py`. Сама структура
  двух assert'ов (A: registry routingKeys ⊆ worker routing_keys; B: worker matrix ⊆ registry
  matrix) СОХРАНЕНА — она уже была корректной по направлению, поменялся только источник
  правой стороны сравнения. Явно задокументировал В КОДЕ (докстринг B), почему направление
  остаётся ОДНОСТОРОННИМ, а не превращено в равенство: по дизайну эпика eviction — «long-TTL
  GC, не liveness-гейтинг» (`registry-00`) — строка capability переживает исчезновение/редеплой
  воркера с другим набором пар; строгое равенство `registry == workers` флапало бы на
  обычной операционной устарелости данных, а не на реальном дрейфе кода. Односторонний
  `workers ⊆ registry` — именно то, что ловит «код воркера объявляет то, чего роутер не знает».
- **`category` vs `stream`**: НЕ нормализовывал и не трогал — ни assert A (использует только
  `stream`/`routingKeys`), ни assert B (использует только идентичность пары `from`/`to`) поле
  `category` вообще не читают, так что расхождение category≠stream (напр. `markup`→`document`)
  для этого файла не имеет значения. Явно написал это в докстринге, а не привёл к молчаливой
  нормализации несуществующей проблемы.
- **Убран `pytest.skip()` полностью** (был единственный, L92-95 старой версии, на non-zero
  exit `docker exec`). `_load_registry()` переписан: native `php` → `docker exec` fallback
  СОХРАНЁН (механизм выбора СПОСОБА вызова инструмента, не про пропуск теста), но любой отказ
  на любом шаге (нет `php` И нет `docker`, `docker exec` упал, non-zero exit тула, невалидный
  JSON) — теперь `pytest.fail()` с диагностикой (включая STDERR инструмента), НЕ skip.
  Грепнул файл на `pytest.skip`/`.skip(` — 0 совпадений (только упоминания в докстринге,
  объясняющие, почему skip здесь запрещён).
- **Проверено эмпирически, что тест реально может упасть** (не просто «зелёный по
  умолчанию»), тремя способами:
  1. **Реальный pytest-прогон с внесённой порчей**: временно добавил в
     `workers/data/worker.py::DataWorker.CAPABILITIES.matrix` пару
     `"totally-bogus-src": ["totally-bogus-tgt"]`, прогнал `pytest workers/tests/test_routing_drift.py -v`
     — `test_worker_matrix_subset_of_registry` **FAILED** с точным указанием
     `workers/data: totally-bogus-src→totally-bogus-tgt` в сообщении; `test_all_routing_keys_have_worker`
     остался PASSED (ожидаемо — расхождение не про routing-keys). Откатил правку тем же Edit
     (НЕ через git checkout/restore — обычная правка-и-обратная-правка), `git diff --stat` после
     отката — пусто, файл побитово идентичен закоммиченному; повторный прогон — 2 passed.
  2. **Прямой вызов чистых helper'ов** (`_uncovered_routing_keys`, `_worker_pairs_missing_from_registry`
     — вынесены из тела test-функций именно для такой проверки) с реальными данными + одной
     точечной порчей на копии (без изменения файлов на диске): убрал `image` из списка
     воркеров → assert A обнаружил `{'image'}` как uncovered; вставил bogus-пару в копию
     capabilities воркера `ai` → assert B обнаружил ровно её.
  3. **Never-skip drill**: замокал `_container_name()` на несуществующий контейнер —
     `_load_registry()` бросил `pytest.fail.Exception` (не `pytest.skip.Exception`) с сообщением
     про `docker exec` exit 1 — подтверждает, что путь отказа инструмента ведёт к жёсткому
     failure, а не к тихому skip.
  Базовый прогон (без порчи) — `make test-drift` → 2 passed за ~1с (docker exec fallback,
  на этом хосте нет нативного `php`).
- **Wiring-находка (репортю тимлиду, Makefile НЕ трогал)**: `make test-drift` НЕ входит ни в
  один агрегатный таргет. `test-python` явно ИСКЛЮЧАЕТ его (`##`-хелp: «excludes e2e +
  routing-drift; see test-e2e / test-drift»), `test: test-php test-python` — тоже без него.
  CI-конфигурации в репо НЕТ ВООБЩЕ (`find` не нашёл ни `.github/workflows`, ни
  `.gitlab-ci.yml`, ни другого workflow-файла). Значит сегодня этот guard выполняется ТОЛЬКО
  по ручному вызову `make test-drift` человеком/агентом — тот самый паттерн «guard, который
  никто не запускает», о котором просил проверить тимлид. Комментарий над таргетом в
  `workers/Makefile:258-259` («запускается напрямую на хосте... нужен лишь src/ и stdlib»)
  тоже устарел: тест теперь требует `docker` + запущенный `php`-контейнер + непустую БД, а не
  только stdlib — но Makefile не трогал (явный запрет тимлида на реструктуризацию без
  согласования), только фиксирую находку здесь и в отчёте.
- **QA**: `PYTHONPATH=. pytest workers/tests/test_routing_drift.py -v` — 2 passed (реальный
  прогон против dev-контейнеров, не мок). `make test-drift` — 2 passed. Другие Python-сьюты
  этот файл не задевает (не импортируется ничем, кроме себя).

**Дозаказ тимлида (2026-07-22, wiring): `test-drift` вшит в `make test-php-live`.** Root
`Makefile::test-php-live` (уже "CANONICAL CI target", `test-db-setup`-зависимость гарантирует
`$(DC) up -d --wait mariadb keydb php` — тот самый живой `php`-контейнер, в который
`test-drift` делает `docker exec`) — добавлен `$(MAKE) test-drift` второй строкой рецепта,
после `$(MAKE) test-php` (тот же `$(MAKE)`-паттерн, что уже использовался для test-php —
гарантия порядка под `make -j`). НЕ вшивал в `test`/`test-python` — они НЕ требуют живого
стека по дизайну (`test` явно комментирует «БЕЗ провижининга», `test-python` гоняет
контейнеризованные unit-сьюты по одному воркеру). Стале-комментарий над `test-drift` в
`workers/Makefile:258` переписан (докстринг-часть — не `##` — по-прежнему разрешена длиннее,
но факты актуализированы: docker+php-контейнер+непустая БД, не «src+stdlib»); `##`-строка
сокращена и обновлена терсно (не абзац, одна строка): «Routing register-round-trip: Python
CAPABILITIES vs live dump-matrix.php (needs docker+php+DB; run via test-php-live)».
**Проверено реальным прогоном** `make test-php-live` целиком (не только test-drift изолированно):
PHPUnit 429/429 OK, ЗАТЕМ `test-drift` — 2 passed, оба видны в одном логе одного `make`-вызова,
exit 0. Guard больше не «никем не запускаемый» — часть канонического CI-таргета.

**Ревью-фикс (2026-07-22): `_load_workers()` больше не глотает пропавший CAPABILITIES молча.**
Было: `if parsed is None: continue` — воркер без CAPABILITIES тихо выпадал из сравнения, ноль
следа в выводе. Стало: `pytest.fail()` с именем файла. Аудит на «другие места, где сбор входа
может тихо ужаться» нашёл ЕЩЁ ДВА реальных случая той же формы (не гипотетических — оба
воспроизведены):
1. `_EXTRACT_CAPS`-скрипт брал `caps.get('routing_keys', [])`/`caps.get('matrix', {})` с
   молчаливым дефолтом на отсутствующий ключ → теперь `sys.exit(1)`, если ключ отсутствует.
   **Драйл вскрыл кейс СЕРЬЁЗНЕЕ ожидаемого**: временно переименовал
   `DataWorker.CAPABILITIES` → `CAPABILITIES_TEMP_DRILL_RENAME` (симуляция «воркер без
   CAPABILITIES») — оказалось, `StreamConsumerBase` (базовый класс) объявляет
   `CAPABILITIES: dict = {}` как дефолт → `DataWorker` ВСЁ РАВНО отвечает `hasattr(..,
   'CAPABILITIES')=True` (унаследованный пустой dict), `caps = {}` — это НЕ `None`! Старая
   (и даже уже пофикшенная только для None) проверка это НЕ ловила бы — воркер тихо ушёл бы
   в результат с `routing_keys=[]`, `matrix={}`, полностью выпав из обоих assert'ов БЕЗ
   единого намёка. Новая required-key проверка ловит именно этот случай. Реальный прогон
   pytest на этой порче — `Failed: CAPABILITIES missing required key(s): ['routing_keys',
   'matrix']`; откат правки (той же Edit, не git checkout) — `git diff --stat` пусто, повтор —
   2 passed.
2. Сам скан `WORKERS_DIR.iterdir()` мог тихо вернуть 0 воркеров (не найдено ни одного
   worker.py — неверный REPO_ROOT, съехавшая директория) — добавлен явный
   `found_any_worker_file`-флаг → `pytest.fail()`, если ни одного worker.py не найдено.
3. Заодно — belt-and-suspenders на стороне registry: `_parse_registry_json()` теперь
   `pytest.fail()`, если `dump-matrix.php --json` вернул exit 0, но с ПУСТЫМ
   matrix/routingKeys (страхует Python-сторону на случай регресса собственной проверки
   PHP-инструмента на пустую БД — той же формы, что уже случилось однажды с самим файлом
   `dump-matrix.php`).
Все 4 новых failure-пути проверены эмпирически (не чтением кода): #1 — реальным pytest-прогоном
с откатом (см. выше); #2, #3 и «истинный None» (модуль без единого CAPABILITIES-подобного
атрибута, включая базовый класс) — прямым вызовом функций с смонтированными входами
(`mock.patch.object`/временный файл), каждый подтверждённо поднимает `pytest.fail.Exception`.
Прочие `continue`/`.get(...)` в файле (не-directory skip, self-pair skip, fallback имени
контейнера из `.env`) — рассмотрены и оставлены: это структурные/best-effort ветки, не
сокращающие СРАВНИВАЕМЫЙ набор данных. `make test-drift` — 2 passed (базовый прогон,
без порчи).

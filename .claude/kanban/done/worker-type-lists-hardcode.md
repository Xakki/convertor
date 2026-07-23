### Хардкод-списки типов воркеров разъезжаются

**Criticality:** Minor

**TAGS:**
- tech-debt

**Description:**
После удаления `WorkerController::ALLOWED_TYPES` (S1, коммит `17b1ac8`, 2026-07-03, вместе с
claim-by-type action) набор типов воркеров продублирован в **пяти** независимых местах, ни одно
из которых не выводится из реестра capability:

- `workers/common/ws_client.py` — `ALLOWED_WORKER_TYPES` (`("ai","document","image","audio","video","data")`).
  Локальная sanity-проверка конфига воркера (`WsClientConfig.validate()`) **до** WS-connect'а, сети нет.
- `workers/gateway/keydb.py:38` — `WORKER_TYPES`. Определяет, какие `conv.<type>` стримы gateway
  читает/реклеймит/роутит (`__main__.py`, `reclaim.py`, `ws_server.py`). Gateway-процесс, только KeyDB.
- `app-symfony/src/Service/Admin/QueueStatsProvider.php:47` — `STREAM_TYPES`. Только метрики
  админ-панели `/admin/queues` (ленивое чтение в `collect()`), нет bootstrap-зависимости.
- `config/packages/messenger.yaml` — 6 транспортов `conv_document…conv_ai`, каждый хардкодит имя
  стрима `conv.<type>`. Читается на DI-compile (bootstrap-time, структурная проводка транспортов).
- `app-symfony/migrations/Version20260722150301.php` (`seedRows()`) — тот же список хардкодом,
  засевает `worker_capabilities`, чтобы таблица не была пустой на старте (это и есть причина, по
  которой реестр может ответить «какие типы есть» до регистрации первого воркера).

**Problem:**
Пять списков сейчас совпадают по значению, но ничто не гарантирует, что они останутся
синхронизированными при добавлении нового типа воркера — правка требует найти и обновить все
места вручную, без единого источника истины и без автоматической проверки на расхождение.

**Impact:**
Тихий дрейф: новый worker-type добавлен в одном месте (напр. Python-конфиг), но забыт в другом
(напр. seed-миграция или messenger.yaml) → метрики/стримы/реестр молча расходятся.

**Decisions:** (груминг 2026-07-23, подтверждено пользователем)

Контекст: Phase 2 эпика `[[registry-00-self-registration]]` **полностью приземлилась**
(registry-00…07 в `done/`). `worker_capabilities` — живой источник истины матрицы конвертаций;
`ConversionRegistry` читает только его (PHP-фолбэк удалён в registry-05). Набор ТИПОВ воркеров
канонически = distinct `workerType` в `worker_capabilities`, засеян seed-миграцией.

1. **Полное выведение Python-списков из реестра — отвергнуто по дизайну.** `ws_client` и
   `gateway/keydb` живут в PHP-независимых процессах, которые обязаны стартовать и работать, даже
   если PHP/БД недоступны (эпик реестра явно проектировал регистрацию как best-effort,
   non-fatal). PHP-round-trip на старте воркера/gateway = новая жёсткая зависимость, регрессия
   резилиенса. Эти два списка остаются **намеренно независимыми статическими whitelist'ами**.
2. **Единый PHP-канон = enum `WorkerType`** (backed-string enum, значения = 6 типов). Заменяет
   разрозненные PHP-копии: `QueueStatsProvider::STREAM_TYPES` удаляется и читает enum; валидация
   `register()` (см. п.6) валидирует против enum. Метрики берём из enum (а НЕ из репозитория):
   статический канон правильнее для ops-панели — показывает `conv.video`, даже когда живых
   video-воркеров ноль (репо-чтение такой канал бы спрятало) и не зависит от того, кто успел
   зарегистрироваться. Реально схлопываем 3 PHP-копии в одну.
3. **Валидация `workerType` на входе `register()`** (внесено по запросу пользователя 2026-07-23):
   `WorkerController::register()` сейчас принимает любой непустой стринг и апсертит его в
   `worker_capabilities`. Добавить boundary-guard: `workerType` не из enum `WorkerType` → 422
   (или 400) с внятной ошибкой, БЕЗ апсерта. Статический whitelist на границе — сознательно не
   зависит от уже зарегистрированных строк (защита от мусорного/опечаточного типа, который создал
   бы «мёртвый» `conv.<type>` стрим, никем не читаемый).
4. **seed-миграция** `Version20260722150301::seedRows()` — историческая, уже отработала, НЕ
   трогаем (миграции иммутабельны). Будущие типы засеваются новой миграцией, использующей enum.
5. **Drift-guard:** pytest `workers/tests/test_worker_type_drift.py` читает канон (enum `WorkerType`,
   регэкспом) и сверяет множество типов с `ws_client.ALLOWED_WORKER_TYPES`,
   `gateway/keydb.WORKER_TYPES`, транспортами `messenger.yaml`; падает при расхождении. Дрейф
   ловится автоматически, без runtime-связывания процессов. Запуск: **`make test-drift` из корня**
   (не `make -C workers test-drift` — `workers/Makefile` инклюдится в корневой, пути
   репо-корневые). Трейд-офф: guard кросс-язычный (всё читается регэкспом из текста файлов, без
   импорта тяжёлых Python-модулей) — принято как приемлемое для Minor-карточки.
6. Python-списки помечаются комментарием «намеренно независимый whitelist на границе процесса
   (резилиенс: старт без PHP); синхронизация с каноном покрыта drift-guard тестом» со ссылкой на
   enum и guard.

**Acceptance criteria:**
- [x] Добавлен enum `WorkerType` (backed-string, 6 значений) — единый PHP-канон типов воркеров.
- [x] `QueueStatsProvider` больше не содержит `STREAM_TYPES`; типы для метрик берутся из enum
      `WorkerType`; `/admin/queues` показывает те же 6 каналов, что и раньше.
- [x] `WorkerController::register()` валидирует `workerType` против enum `WorkerType`; неизвестный
      тип → **400** с внятной ошибкой и БЕЗ апсерта в `worker_capabilities`; валидный тип
      обрабатывается как раньше.
- [x] Drift-guard тест `workers/tests/test_worker_type_drift.py` сверяет 4 статических источника
      (enum `WorkerType` как канон, `ws_client`, `gateway/keydb`, `messenger.yaml`) на равенство
      множества типов, hard-fail (никогда не skip); интегрирован в `make test-drift`.
- [x] Комментарии в `ws_client.py` и `gateway/keydb.py` описывают статус «намеренно независимый
      whitelist + покрыт guard» со ссылкой на канон.
- [x] `make phpstan` / `make cs-check` зелёные; тесты (PHPUnit + pytest + drift) зелёные.
- [x] Бонус: 2 admin-функциональных теста (`WorkerControllerTest`, `QueueControllerTest`), тоже
      хардкодившие список типов, сведены к `WorkerType::cases()`.

**Files:**
- `app-symfony/src/Enum/WorkerType.php` (новый) — enum-канон.
- `app-symfony/src/Service/Admin/QueueStatsProvider.php` — убрать `STREAM_TYPES`, читать enum.
- `app-symfony/src/Controller/**/WorkerController.php` — валидация `workerType` в `register()`.
- Новый guard-тест: `workers/tests/` (pytest) — предпочтительно (2 из 4 источников — Python,
  импортируются напрямую) — либо `make check-worker-types`.
- `workers/common/ws_client.py`, `workers/gateway/keydb.py` — комментарии.

**Notes (устаревшее в исходной карточке, исправлено при груминге):**
- Номера строк были устаревшими: `ws_client` константа теперь ~:259 (не :118), `STREAM_TYPES` :47 (не :42).
- Комментарий `ws_client.py` про удалённую `WorkerController::ALLOWED_TYPES` **уже исправлен** в
  коде (текущие строки ~255-258 явно фиксируют удаление и ссылаются на эту карточку) — пункт из
  старого Problem про «устаревший комментарий» снят.

**Reference:** `[[registry-00-self-registration]]`

**Execution Log:**
- 2026-07-23: груминг завершён, решения зафиксированы, добавлена валидация `workerType` по запросу
  пользователя. Ветка `task/worker-type-lists-hardcode`. Разведка: enum-паттерн = `FileCategory`
  (backed-string); `register()` валидирует в `validateRegisterPayload()` (все ошибки → 400 —
  держим консистентно, не 422); drift-guard-эталон = `workers/tests/test_routing_drift.py` +
  таргет `make test-drift` (из корня). Реализация: PHP-зона → Python-зона (guard читает enum).
- 2026-07-23: реализация завершена, 3 коммита на ветке:
  - `90b29e8` feat(worker): enum `WorkerType` + валидация `register()` + перевод `QueueStatsProvider`.
  - `1e2bfce` refactor(test): admin-тесты каналов на `WorkerType::cases()`.
  - `dbf4b20` test(workers): drift-guard `test_worker_type_drift.py` + wiring в `make test-drift`.
  Проверки зелёные: `make phpstan`/`make cs-check` чисто, `make test-php-live` = 494 теста OK,
  `make test-drift` = 5 passed (2 routing + 3 worker-type). 4 источника типов совпадают:
  `{ai,audio,data,document,image,video}`. Корректировка: канон команды drift = `make test-drift`
  из корня (не `make -C workers ...`).

### Gateway пушит liveness воркеров в PHP + long-TTL GC

**Criticality:** Medium

**TAGS:**
- feature
- infra

**Description:**
Пятый шаг Phase 2 эпика `[[registry-00-self-registration]]`. Сейчас gateway получает
`ping{cpu,mem,load}` от воркеров (`_handle_ping()`, `workers/gateway/ws_server.py:425-437`) и
отвечает `pong`, но только логирует — данные никуда не персистятся и не доходят до PHP;
`WorkerCapability.lastSeen` обновляется единственный раз, при `register()`. По эпику (Decisions:
«Eviction = long-TTL GC, NOT short liveness gating») liveness НЕ гейтит роутинг — это чистый
мониторинг + основа для будущего candidate selection в Phase 3.

**Problem:**
- Нет способа узнать, жив ли зарегистрированный воркер, кроме как ждать нового register
  (который может не случиться месяцами, если процесс не рестартует).
- `worker_capabilities` копится бессрочно — отключённый и никогда не переподключившийся
  воркер/seed-строка остаётся в матрице навсегда.

**Impact:**
Без пуша liveness — админ-страница `[[registry-07-admin-workers-page]]` не сможет показать
реальный статус «жив/устарел», а без GC — матрица засоряется мёртвыми записями (в т.ч.
`__seed__`-строками из `[[registry-03-seed-migration]]`, которые не устаревают сами по себе).

**Recommendation:**
- Gateway агрегирует входящие `ping{cpu,mem,load}` по `(workerType, instanceId)` и периодически
  (интервал — batch, не per-ping) пушит их батчем на новый internal-эндпоинт PHP.
- Авторизация — тем же механизмом, что существующие internal-роуты
  (`app-symfony/src/Controller/Api/InternalWorkerController.php`, `#[Route('/api/v1/internal/worker')]`,
  firewall `internal_api`, токен `GATEWAY_INTERNAL_TOKEN`, см. `GatewayInternalAuthenticator.php`) —
  проверить точное имя эндпоинт-группы и токена при реализации, не изобретать новый механизм.
- PHP обновляет `lastSeen` по составному ключу `(workerType, instanceId)` (схема из
  `[[registry-02-schema-multi-instance]]`).
- Отдельная команда/Scheduler-джоба (Symfony Scheduler, как auto-delete файлов через 24ч,
  см. project CLAUDE.md «File Handling») — long-TTL GC, вычищающая capability-строки старше
  настраиваемого TTL (часы–дни, env-параметр). По эпику liveness НЕ гейтит роутинг — GC только
  чистит явно мёртвые записи, не влияет на выбор воркера для живых.
- Gateway при обрыве WS-соединения помечает соответствующий инстанс отключённым (сигнал для
  админ-вью, не для матрицы маршрутизации).

**Acceptance Criteria:**
- Gateway пушит батч liveness на новый internal-эндпоинт с существующей internal-авторизацией
  (`GATEWAY_INTERNAL_TOKEN`); неавторизованный запрос — 401/403.
- `WorkerCapability.lastSeen` обновляется по `(workerType, instanceId)` из push, без нового
  `register()`.
- GC-джоба удаляет строки старше TTL; TTL конфигурируем; живые инстансы (свежий `lastSeen`)
  не трогает.
- Обрыв WS у воркера отражается в статусе инстанса (для последующей админ-страницы), но НЕ
  убирает его пары из активной матрицы маршрутизации до истечения TTL.
- TTL-GC НИКОГДА не удаляет seed-строки (`instance_id='__seed__'`). Иначе пустая БД становится
  достижимой в обычной эксплуатации, и гарантия D1 «БД никогда не пуста» молча аннулируется —
  вместе с ней исчезают `/formats` и submit до первой живой регистрации. Согласовано с
  контрактом пустой БД из `[[registry-05-drop-hardcode]]`.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest (`workers/tests`, gateway).

**Decisions:**
- Груминг 2026-07-22 (D2): вариант «периодический re-register» отклонён — gateway и так видит
  реальный обрыв WS и уже получает cpu/mem/load через ping, отдельный push-эндпоинт дешевле и
  точнее.

**Зависит от:** `[[registry-05-drop-hardcode]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress

## Execution Log

#### PHP-зона (backend-php)

- **Auth-контракт сверен с кодом, дрейфа не найдено:** firewall `internal_api`
  (`^/api/v1/internal`), `GatewayInternalAuthenticator` читает
  `GATEWAY_INTERNAL_TOKEN`, `access_control` требует `IS_AUTHENTICATED_FULLY`,
  неверный/отсутствующий/чужой (worker_api) токен → 401. Эндпоинт добавлен в
  существующий `InternalWorkerController` (`#[Route('/api/v1/internal/worker')]`).
- **`POST /api/v1/internal/worker/liveness`** — батч-пуш `{"instances":[...]}`,
  ответ `{"updated":<int>,"unknown":[...]}`. UPDATE ONLY —
  `WorkerCapabilityRepository::updateLiveness()` никогда не вставляет строку
  (см. ниже). Malformed-batch policy: ЛЮБАЯ невалидная запись отклоняет ВЕСЬ
  батч 400-кой (не partial-apply) — обосновано и задокументировано в
  докблоке контроллера: внутренний доверенный контракт gateway↔PHP, не
  публичный API; малформед-запись почти всегда сигнализирует версийный
  рассинхрон gateway, а long-TTL GC (дни) делает разовый отклонённый батч
  несущественным.
- **`updateLiveness()` — 2 запроса, НЕ полагается на affected-rows UPDATE**
  (SELECT существующих ключей → CASE-batched UPDATE только по найденным).
  Реализовано так намеренно (не наивный affected-rows подсчёт): MySQL/MariaDB
  по умолчанию считают ИЗМЕНЁННЫЕ, а не СОВПАВШИЕ строки — идемпотентный
  повторный пуш с тем же `lastSeenAt` дал бы affected=0 и ложно попал бы в
  `unknown`, вынуждая gateway форсировать re-register на пустом месте.
- **`status` (a) — ХРАНИТСЯ**, как согласовано: новая колонка
  `worker_capabilities.status` (`App\Enum\WorkerLivenessStatus`:
  Alive/Disconnected/Unknown), миграция `Version20260722212523` (hand-written,
  тот же повод не использовать `migrate-diff`, что registry-02). НЕ гейтит
  роутинг — `ConversionRegistry::buildMatrixFromCapabilities()` никогда не
  читает `getStatus()`, явный защитный докблок на месте + отдельный тест
  `ConversionRegistryLivenessStatusTest::testDisconnectedInstanceStillServesItsPairs`
  (disconnected-инстанс продолжает отдавать свои пары до GC). GC тоже не
  смотрит на `status` — доказано парой тестов в обе стороны
  (`testDisconnectedButFreshInstanceSurvivesGc`,
  `testAliveButAncientInstanceIsDeleted`).
  - Seed-строки получают `unknown` (НЕ `alive`/`disconnected` — обе были бы
    нечестными: seed не живой процесс и никогда не получает liveness-пуш).
    DEFAULT колонки = `'unknown'`, поэтому это работает автоматически на
    КАЖДОМ прогоне миграций с нуля (registry-03 INSERT предшествует этой
    ALTER'е и ничего не знает о колонке) — историческая миграция не
    редактировалась.
  - `WorkerCapabilityRepository::upsert()` (т.е. `register()`) теперь
    безусловно сбрасывает `status` в `alive` и на INSERT, и на
    ON DUPLICATE KEY UPDATE — реконнект воркера это живое соединение, даже
    если до этого он был помечен `disconnected`. Покрыто тестом
    `testRegisterResetsStatusToAliveOnReconnect`.
- **`metrics` (b) — ACCEPT-AND-IGNORE**, как согласовано: форма валидируется
  (malformed → 400, часть общей batch-policy), не персистится. Схема НЕ
  тронута — ни колонки, ни второй миграции. Причина и явный "это не баг"
  маркер — в докблоке `liveness()`: ни один текущий потребитель (в т.ч.
  `[[registry-07-admin-workers-page]]`) метрики не читает.
- **GC** — `WorkerCapabilityGcService` + `WorkerCapabilityGcMessage`/Handler,
  ежечасный тик через тот же Scheduler-транспорт, что `FileCleanupMessage`
  (`src/Schedule.php`). TTL — `WORKER_CAPABILITY_GC_TTL_HOURS` (дефолт 168ч =
  7 суток). Seed-строки исключены безусловно (`instance_id != '__seed__'` в
  WHERE) — 4 живых теста против convertor-test, включая смешанный батч
  (seed+non-seed в одном прогоне), доказывающий что исключение per-row, а не
  случайный skip-всего-при-наличии-seed. При `deleted > 0` вызывает
  `ConversionRegistry::invalidateMatrix()` — без этого удалённые пары могли
  бы оставаться в `/formats` до часа (registry-05 cache TTL) вместо
  немедленного исчезновения.
- **Деградация `/formats`/submit при GC последнего живого инстанса
  workerType** (запрошено тимлидом явно):
  - Для ВСЕХ 6 сегодняшних registry-03 seed-типов (document/image/audio/
    video/data/ai) — матрица деградирует к статичному seed-снапшоту, НЕ к
    пустой: `buildMatrixFromCapabilities()` объединяет все оставшиеся ряды
    (включая seed), так что seed-пары продолжают обслуживать submit/formats
    как временный fallback.
  - Для гипотетического будущего workerType БЕЗ seed-строки — его пары
    исчезают из матрицы полностью: `/formats` их не покажет, submit отдаст
    честный 400. Это НЕ регрессия, а ровно то поведение, которое
    `[[registry-05-drop-hardcode]]` сделало намеренным.
- **Находка при прогоне (операционная, не баг карты):** `test-drift`
  (`workers/tests/test_routing_drift.py`) гоняет `dump-matrix.php` БЕЗ
  `APP_ENV=test` — т.е. против DEV-базы, не `convertor-test`. Новая миграция
  была нужна в ОБЕИХ БД (`make test-db-setup` для test + `make migrate` для
  dev) — без второго прогона `make test-php-live` падал с "Unknown column
  't0.status'" на dev-стороне. Стоит иметь в виду для будущих карт со
  схемой: одного `test-db-setup` недостаточно.
- **QA:** `make phpstan` — `[OK] No errors`; `make cs` → `make cs-check` —
  чисто; `make test-php-live` — `OK (449 tests, 1826 assertions)`, drift 2/2;
  golden-фикстура сверена `diff` — без изменений (status не влияет на
  `getSupportedFormats()`/`streamFor()`).
- **Доп. drift-фикс:** добавил `/liveness` (и отсутствовавший ранее
  `/dlq-fail`) в карту эндпоинтов скилла `api-design`.
- Расхождений с зафиксированным тимлидом контрактом не найдено — оба форка
  (status/metrics) реализованы ровно так, как согласовано в переписке.

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
- **Доп. раунд ревью (2 closure):** добавлен regression-тест
  `testLivenessIdempotentRetryWithIdenticalLastSeenStillReportsUpdated`
  (идентичный повторный пуш всё ещё `updated`, не `unknown`, дубль-ряд не
  создаётся) — закрывает дыру покрытия для SELECT-based дизайна
  `updateLiveness()`. Поправлена вводящая в заблуждение формулировка в
  докблоке `liveness()`: `unknown` НЕ означает самоисцеление к следующему
  циклу — ничего не форсирует re-register, `register()` срабатывает только
  на собственный реконнект воркера, так что разрыв может держаться
  бессрочно, если GC снёс ряд, а WS-соединение воркера не падало. Реальный
  фикс (forced re-register) — отдельная grooming-карточка тимлида, здесь не
  трогал. QA: `make phpstan`/`cs`/`cs-check` чисто, `make test-php-live` —
  `OK (450 tests, 1835 assertions)`, drift 2/2.

#### Python-зона

Файлы: `workers/common/ws_client.py` (ready-фрейм), `workers/gateway/ws_server.py`,
`workers/gateway/liveness.py` (новый), `workers/gateway/relay.py`, `workers/gateway/config.py`,
`workers/gateway/__main__.py`, `workers/Makefile` (добавил новый тест-файл в `test-gateway`),
`workers/tests/test_gateway_liveness_push.py` (новый). `app-symfony/` не трогал.

- **Блокер `instanceId` — STOP-вопрос тимлиду ДО реализации.** Проверил код: gateway
  (`ws_server.py::_handshake()`) до этой карты НЕ знал `instanceId` вообще — `ready`-фрейм нёс
  только `workerId/workerType/slots/version/cpu/mem/load`, `ping` — только `cpu/mem/load`;
  единственное место, где `instanceId` вообще существовал — HTTP `POST /worker/register`
  (registry-02, `ws_client.py::_build_register_body()`), gateway в этом HTTP-обмене не участвует.
  Предложил тимлиду: расширить `ready`-фрейм полем `instanceId` = ТА ЖЕ `_instance_id()`, что уже
  используется в `_build_register_body()` — без отдельной деривации, гарантированно то же
  значение. Тимлид подтвердил («Extend ready frame»). Реализовано: `_send_ready()` шлёт
  `instanceId`, `_handshake()` его читает (аддитивно — `frame.get("instanceId")`, невалидное/
  отсутствующее → `None`, СТАРЫЙ воркер без этого поля продолжает работать как раньше, просто
  не трекается для liveness; job-диспетч от этого поля не зависит НИКАК).
- **Агрегация** — новый модуль `workers/gateway/liveness.py::LivenessAggregator`, ключ
  `(workerType, instanceId)`. `record_connect()` на успешный handshake, `record_ping()` на
  каждый `ping`-фрейм (обновляет cpu/mem/load + `lastSeenAt`), `record_disconnect()` в
  `finally` блока `handle()` (после teardown reader/dispatcher). Санитизация/валидация
  `instanceId` ПОВТОРЕНА на стороне gateway (тот же charset-regex, что в `ws_client.py`) —
  причина: PHP-эндпоинт (см. PHP-зону выше) отклоняет ВЕСЬ батч 400-кой на ОДНОЙ невалидной
  записи — один битый/старый воркер не должен топить liveness-репортинг всех остальных в этом
  же push-цикле.
- **Push НЕ per-ping — батч на интервале.** `LIVENESS_PUSH_INTERVAL_S` (новый env, дефолт
  **30с**, `Config.liveness_push_interval_s`) — отдельная asyncio-задача
  `run_liveness_push_loop()`, запущена в `__main__.py` рядом с reclaim-loop/dlq-consumer.
  Обоснование дефолта: воркерский `WS_PING_INTERVAL_S` = 20с (s1-08) — 30с даёт ~1.5 пинга на
  цикл, разумная свежесть без пуша на каждый одиночный ping (собственно смысл батчинга).
- **Endpoint/авторизация** — сверено с УЖЕ РЕАЛИЗОВАННЫМ PHP-эндпоинтом (см. PHP-зону выше,
  готова к моменту моей реализации): `POST /api/v1/internal/worker/liveness`, тело
  `{"instances":[...]}`, ответ `{"updated":int,"unknown":[...]}` — контракт совпал 1:1 с тем,
  что дал тимлид, PHP ничего не пришлось перепроверять читкой кода отдельно (уже подтверждено
  их Execution Log). Новый метод `RelayClient.post_liveness()` — база URL/токен те же
  env-driven `SYMFONY_INTERNAL_URL`/`GATEWAY_INTERNAL_TOKEN`, что у result/fail/dlq-fail (ничего
  нового не хардкожено). Отдельный `liveness_relay` instance в `__main__.py` (не тот, что
  лениво создаёт `WsGateway` для result/fail, не `dlq_relay`) — независимый lifecycle/aclose.
- **`unknown` — НЕ игнорируется, но и НЕ форсит re-register.** Gateway не может удалённо
  заставить воркера перерегистрироваться — `register()` вызывается САМИМ воркером на его
  собственный connect (`ws_client.py::_register()`), у gateway нет канала это спровоцировать
  без насильного разрыва соединения этого воркера — а это прервало бы его in-flight задачу
  ради чисто телеметрийной проблемы, худший trade-off, чем один цикл с устаревшей
  capability-строкой. Решение: громкий `logger.error()` на каждую `unknown`-запись (workerType +
  instanceId) — минимальная планка по ТЗ, задокументировано в докстринге `_push_once()`.
- **Резилиенс (это то, что тимлид просил больше всего):**
  - Push — ОТДЕЛЬНАЯ asyncio-задача, не на пути диспетча job'ов: медленный/подвисший PHP
    задерживает только СЛЕДУЮЩИЙ liveness-цикл (await уступает event loop кооперативно),
    WS-обработку других соединений не блокирует.
    `LIVENESS_TIMEOUT_S=10.0` (короче `RELAY_TIMEOUT_S=30.0` result/fail-пути — телеметрия,
    маленький JSON, не обязана ждать так же долго).
  - Любой сбой (сеть, таймаут, не-2xx, не-JSON тело, тело не dict) — `RelayClient.post_liveness()`
    возвращает `(False, None)`, НИКОГДА не бросает; `run_liveness_push_loop` дополнительно
    оборачивает весь цикл в `try/except Exception` — цикл переживёт любую неожиданность.
  - **Политика на сбой:** DROP+retry-next-cycle, БЕЗ backoff. Alive-записи не нужно
    «ретраить» — они пересобираются заново из текущего состояния на КАЖДОМ цикле (следующий
    push всё равно отправит свежие метрики). Pending-disconnect маркеры остаются в очереди
    (ограниченной, см. ниже) и уходят в следующий цикл как есть — retry автоматический,
    отдельного backoff не делал (интервал push и так 30с — уже достаточный натуральный
    троттлинг; агрессивный backoff усложнил бы код ради сценария, где push и так почти
    бесплатный маленький батч).
  - **Ограничение памяти на churn (явно запрошено тимлидом):** `_alive` НЕ может расти
    неограниченно — bounded реальным ресурсом (число одновременно открытых WS-соединений с
    валидным instanceId); запись УДАЛЯЕТСЯ из `_alive` на disconnect (переезжает в
    one-shot-маркер), не накапливается. `_pending_disconnects` — единственная структура,
    которая теоретически могла бы расти неограниченно (если PHP недоступен долго при высоком
    churn) → жёсткий кап `_MAX_PENDING_DISCONNECTS=2000`, при переполнении дропается САМЫЙ
    старый маркер (лог warning) — телеметрия, не критичные данные, роутинг не затронут.
    Дренится ПОЛНОСТЬЮ на каждый успешный push (`mark_pushed`) — disconnected-маркер шлётся
    ОДИН раз, не вечно, alive-записи остаются и рефрешатся каждый цикл.
- **Судьба ready-фрейма для СТАРЫХ воркеров** — аддитивное поле, обратная совместимость
  проверена тестом `test_ws_ready_without_instance_id_backward_compat_not_tracked`: handshake/
  ping/pong работают как раньше, просто инстанс не появляется в liveness-снапшоте.
- **Тесты**: новый `workers/tests/test_gateway_liveness_push.py` (19 тестов, 4 уровня —
  `LivenessAggregator` unit, `RelayClient.post_liveness` HTTP-форма, `run_liveness_push_loop`/
  `_push_once` сквозной резилиенс, WS-уровень ready/ping/disconnect + backward-compat).
  Добавил файл в список `workers/Makefile::test-gateway` — иначе он бы существовал, но
  НИКОГДА не запускался в CI-пути (тот самый паттерн «guard, который никто не гоняет» из
  предыдущей карты — не повторяю его здесь). Прогон: `make test-gateway` — 156 passed, 1
  skipped (тот же, pre-existing, image/pdf2image); `make test-python` — все per-worker сьюты
  (данные ниже в разделе QA/report team-lead'у).

**Ревью-фикс (2026-07-23, только докстринг, `_push_once()`):** комментарий про `unknown`
неверно рамил устарелость как «одна capability-строка на один push-цикл». По факту это НЕ
так: если PHP GC делает строку до истечения WS-соединения воркера, НИЧЕГО не пересоздаёт её
до случайного реконнекта по несвязанной причине — `unknown` может стрелять всю оставшуюся
жизнь этого соединения, не один цикл. Комментарий переписан явно на это. Добавлена заметка,
что ОДИН И ТОТ ЖЕ `logger.error()` срабатывает и на этот персистентный кейс, И на безобидную
стартовую гонку (ping пришёл раньше, чем успел отработать HTTP `register()` этого же воркера)
— лог сам по себе их не различает. Поведение НЕ менялось (без forced re-register/self-heal —
реальный фикс тимлид заводит отдельной grooming-карточкой). `make test-gateway` — зелёный
(перепрогнан после правки).

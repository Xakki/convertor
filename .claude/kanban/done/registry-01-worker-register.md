### Registry Phase 1 — worker self-register → DB-matrix с hardcoded fallback

**Критичность:** Medium

**TAGS:**
- feature
- worker
- backend

**Описание:**
Первая фаза эпика `[[registry-00-self-registration]]` — **инфраструктура, без изменения поведения**.
Воркеры при старте регистрируют свои возможности в PHP через pull-API; `ConversionRegistry`
строит матрицу конвертаций из БД, **с сохранением hardcoded `workerCapabilities()` как fallback**
(когда БД пуста/недоступна). Ни один advertised pair не исчезает, `/formats` не меняется внешне,
drift/golden-тесты остаются зелёными за счёт fallback. Удаление hardcode + eviction + исчезновение
Stage-7 pairs — это Phase 2 (заблокирована `[[php-ai-virtual-key-submit-resolution]]`), НЕ здесь.

**Seam (уточнён после s1-11):** все воркеры теперь идут через общий `WsClient`
(on-server — `StreamConsumerBase.run()`; ffmpeg — `run_dual()`; AI — свой путь поверх того же
клиента). → `register()` цепляем **в `WsClient` в момент установления соединения / `ready`**,
единообразно для всех. Это заменяет старую заметку «`StreamConsumerBase.__init__`» из эпика.

**Контракт register (закрепить, общий для PHP и Python зон):**
- Транспорт: **HTTP** `POST /api/v1/worker/register` на pull-API (НЕ WS-фрейм), статичный
  bearer `WORKER_API_TOKEN` (тот же, что у `GET /jobs/{id}/input`). Только HTTP — потому что
  правило «remote-воркеры не получают прямой доступ к БД/KeyDB», а pull-API достижим и on-server, и remote.
- Тело запроса = JSON, зеркалит Python-`CAPABILITIES` воркера 1:1:
  `{ "workerType": str, "isAi": bool, "streams": [str], "routingKeys": [str],
     "matrix": { "<source>": ["<target>", ...] }, "image": str|null, "version": str|null }`.
  (Поля брать из фактического `CAPABILITIES`-дикта — сверить в `workers/*/worker.py` и общей базе.)
- Ответ: `200 {"ok": true}` достаточно; тело воркер игнорирует.
- **Best-effort / non-fatal (жёсткое требование):** сбой register (таймаут, 5xx, PHP недоступен)
  логируется и НЕ роняет воркер — он всё равно поднимается и потребляет из своего stream'а.
  Никаких ретраев-блокировок старта.

**Файлы (PHP зона):**
- Создать сущность `src/Entity/WorkerCapability.php` — одна строка на `workerType` (уникальный ключ),
  JSON-колонка `capabilities` (весь блоб выше), `lastSeen` (для мониторинга; liveness пока не гейтит),
  `image`/`version`. Doctrine-миграция.
- Создать `src/Repository/WorkerCapabilityRepository.php` — upsert по `workerType`, чтение активных.
- Эндпоинт `POST /register` в `WorkerController` (`/api/v1/worker`, firewall статик-bearer) —
  валидация тела + upsert через репозиторий. Инвалидировать кеш матрицы.
- `ConversionRegistry` — строить матрицу из `WorkerCapabilityRepository` **с fallback на
  `workerCapabilities()`**, когда БД пуста/недоступна. Кешировать собранную матрицу.
  Сигнатуры `getSupportedFormats()`/`isSupported()`/`streamFor()` **не менять**.

**Файлы (Python зона):**
- `workers/common/ws_client.py` — на `ready`/после connect дёрнуть best-effort `register()`:
  HTTP POST на `${API_BASE_URL}/api/v1/worker/register` с bearer + телом из `CAPABILITIES`.
  WsClient должен получить `CAPABILITIES` воркера (через конфиг/колбэк — деталь реализации).
  Обёрнуть в try/except: любой сбой → log.warning, продолжаем.
- ffmpeg (`run_dual()`): каждое из двух соединений регистрирует свой `workerType`
  (`audio` / `video`) отдельно.
- Конфиг воркеров: убедиться, что `API_BASE_URL` + `WORKER_API_TOKEN` доступны в WsClient
  (уже есть для input-fetch).

**Критерии приёмки:**
- `POST /api/v1/worker/register` принимает валидный блоб, upsert'ит строку `WorkerCapability`;
  повторный register того же `workerType` обновляет, не дублирует.
- `ConversionRegistry` при непустой БД строит матрицу из репозитория; при пустой/недоступной —
  из `workerCapabilities()` (fallback). `/formats` внешне не меняется; ни один pair не пропал.
- Воркеры при старте шлют register; **сбой register не роняет воркер** (тест: PHP недоступен →
  воркер поднялся и обрабатывает задачу).
- ffmpeg регистрирует ДВА `workerType` (audio+video), по одному на соединение.
- drift-тест и `ConversionRegistryGoldenTest` — зелёные (fallback покрывает).
- `make phpstan` чисто; `make test-python` + PHPUnit — зелёные.

**Вне скоупа (Phase 2+):** удаление `workerCapabilities()`, вывод `WorkerController::ALLOWED_TYPES`
из реестра, seed-миграция, heartbeat + long-TTL GC, register-round-trip тест, исчезновение
Stage-7 pairs (→ 400). Multi-candidate router — Phase 3.

**Зависит от:** `[[s1-11-onserver-workers-migrate]]` (seam — приземлён). Независим от AI-pair shape.

**Эпик:** `[[registry-00-self-registration]]`

**Status:** ready

**Реализация:**
- PHP (`811b195`): `WorkerCapability` entity (UNIQUE `worker_type`, JSON `capabilities`, `lastSeen`) + миграция `Version20260708071901` + `WorkerCapabilityRepository::upsert()`; `POST /api/v1/worker/register` в `WorkerController` (валидация + upsert + инвалидация кеша); `ConversionRegistry` строит матрицу из репозитория с fallback на `workerCapabilities()` (БД пуста/недоступна → hardcode), per-request + cross-request кеш, сигнатуры `getSupportedFormats/isSupported/streamFor` не тронуты. PHPUnit: `WorkerRegisterControllerTest` + `ConversionRegistryFallbackTest`.
- Python (`e42f664`): best-effort `_register()` в `WsClient` — фон-таск на connect, HTTP POST тела из `CAPABILITIES`; non-fatal (except исключает CancelledError, teardown чистит). ffmpeg `run_dual()` регистрирует два типа (audio+video) со своими CAPABILITIES. Тесты: `test_register_called_on_connect`, `test_register_failure_does_not_stop_worker`, `test_no_register_when_no_capabilities`.

**Ревью (reviewer-registry):** APPROVE-WITH-NITS. Fix-now (`fd44145`): MEDIUM-1 — тихий skip AI-воркера в `buildMatrixFromCapabilities` (убран misleading warning на каждом rebuild); MEDIUM-2a — фикстура теста выровнена под реальный payload (`streams=['image']`). Отложено в Phase 2 (записано в эпик): семантика streams vs routingKeys, `isAi` из CAPABILITIES, upsert TOCTOU → нативный ON DUPLICATE KEY.

**Гейты (все зелёные):** phpstan — No errors; PHPUnit — 93 tests / 324 assertions; `make test-python` — exit 0 (data 97 / ffmpeg 18 / image 33 / libreoffice 31 / metrics 15 / ai 110); `test-gateway` — 96 passed (вкл. 3 register-теста); `test-drift` — 2 passed (routing-контракт цел, fallback покрывает).

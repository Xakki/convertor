
### CNV-27 — Конфигурируемый worker-api для внешних API, CLI и AI-моделей

**Criticality:** High

**TAGS:**
- feature
- ai
- worker
- configuration

**Description:**
Разморозить и переписать прежнюю инициативу external AI. Создать отдельный
`worker-api` — прослойку для внешних API и CLI, включая AI, но не ограниченную
AI-инференсом. Первый внешний backend и scope MVP — self-hosted g4f на
`https://aip.xakki.ru`, только OpenAI-compatible chat/completions. Native
MarkItDown, STT/TTS, text→image и остальные API/CLI остаются следующими этапами.

Настройки доступных backend'ов, CLI и моделей задаются отдельным YAML-файлом.
У операции может быть список конкретных имён моделей либо map логических ключей
на конкретные модели. В запросе передаётся ключ, если он объявлен; иначе —
разрешённое значение модели. YAML описывает разрешённый каталог и параметры
выполнения; реальные секреты в него не попадают и берутся только из `.env.local`
по именам переменных. При старте `worker-api` выполняет chat/completions probe
каждой объявленной модели до регистрации в gateway.

Зафиксированные OpenAPI-снимки и их manifest располагаются в
`workers/api/schema/`; YAML связывает provider со схемой и явно описывает
startup check. Воркер не загружает и не принимает новую схему из сети во время
запуска: обновление snapshots — отдельная проверяемая поставка в Git.

Первый rollout выполняется только на production-стенде. Подключение воркера на
других remote-хостах и изменение их Compose-профилей — вне scope этой карточки.

**Problem:**
Текущий AI-воркер поддерживает только локальные `ollama` и `llamacpp`; общего
исполнителя для внешних API/CLI нет. Нельзя публично и безопасно выбрать модель
из allowlist, использовать g4f или управлять доступным набором API/CLI/model
через единый декларативный каталог. Замороженная версия карточки содержала
устаревший scope и небезопасные данные конфигурации.

**Impact:**
Без задачи нельзя подключить управляемые внешние chat-модели. Жёстко зашитые
настройки повышают риск неявного выбора нестабильной или дорогой модели; секреты
нельзя безопасно размещать в versioned-конфигурации.

**Recommendation:**
Реализовать первый production-only MVP как отдельный `worker-api`: YAML-каталог,
g4f chat/completions, allowlist моделей, startup health checks, public model
selection, AI quota defaults и отдельные `executionKind=api` / `conv.api`.
Остальные API/CLI-адаптеры оставить следующим этапам.

Реализованная стартовая структура YAML (без секретов):

```yaml
version: 1
providers:
  - id: aip-g4f
    kind: g4f
    base_url: https://aip.xakki.ru
    schema: aip-g4f/v0.5
    credentials:
      bearer_env: G4F_API_KEY
    operations:
      chat:
        endpoint: /v1/chat/completions
        models:
          fast:
            model: gpt-4o
            label: GPT-4o
            fallback: [balanced]
          balanced:
            model: gpt-4.1
            label: GPT-4.1
        startup_check:
          mode: chat_completion
          timeout_sec: 15
routing:
  defaults:
    chat: fast
```

**Acceptance Criteria:**
- CNV-27 находится в `test/`; прежний freeze снят, исторический scope
  заменён на актуальный и карточка не содержит credential value.
- Зафиксировано: отдельный `worker-api` для внешних API/CLI; g4f/aip — первый
  внешний provider; rollout первого этапа — только production-стенд.
- YAML-каталог хранится в корне репозитория как `worker-api.yaml`, содержит
  только декларативную конфигурацию и ссылки на env-переменные; секреты
  находятся исключительно в `.env.local`.
- Определены schema YAML, приоритет/provider routing, execution kind `api` и
  stream `conv.api`, изоляция/деплой секрета и политика startup ping failure.
- Контракт g4f подтверждён свежей OpenAPI-спецификацией перед реализацией;
  проверены auth и результаты endpoint'ов, а не только старый дамп.
- Реализация покрыта pytest и соответствующими проектными Make-проверками.
- Public API принимает разрешённый `model` key/value, валидирует его против
  YAML-каталога и применяет существующие default AI-квоты.
- До регистрации в gateway `worker-api` проверяет каждую объявленную пару
  provider/model через operation-specific ping без пользовательских данных и
  регистрирует только прошедшие возможности.
- Если не прошла ни одна chat model, `worker-api` не регистрируется как consumer
  `conv.api`; API не принимает заведомо невыполнимые задачи.
- Ошибки startup ping структурированно логируются с provider/model/operation и
  безопасной причиной; credential и payload в логи не попадают.
- `worker-api` читает только versioned OpenAPI snapshots из `workers/api/schema/`;
  снапшоты имеют manifest с provider, source URL, версией и checksum.

**Decisions:**
- 2026-08-25: карточка разморожена по прямому решению владельца и перенесена из
  `freeze/` в `grooming/`.
- 2026-08-25: не создавать отдельный `external-ai` worker или stream; внешний
  provider работает внутри существующего `worker-ai`, который единолично
  потребляет `conv.ai`.
- 2026-08-25: сразу поддержать `https://aip.xakki.ru` / g4f как первый внешний
  provider, включая OpenAI-compatible surface и native MarkItDown API.
- 2026-08-25: `worker-api` — новый отдельный worker, прослойка между сервисом и
  внешними API/CLI, в том числе не-AI; предыдущее решение расширять `worker-ai`
  этим scope заменено.
- 2026-08-25: доступные API, CLI и модели описываются отдельным YAML-каталогом;
  ключи и прочие секреты не versioned и передаются только через `.env.local`.
- 2026-08-25: YAML позволяет list реальных model IDs или map логических ключей
  на реальные model IDs; job/API принимает key, если он задан, иначе разрешённое
  значение; произвольные модели отклоняются.
- 2026-08-25: при старте `worker-api` обязан ping-проверить все объявленные
  provider/model пары до регистрации в gateway.
- 2026-08-25: выбор разрешённой модели публикуется сразу в public API; применяются
  существующие default AI-квоты.
- 2026-08-25: `worker-api` владеет отдельным `executionKind=api` и stream
  `conv.api`; формула маршрутизации меняется на
  `executionKind ?? (isAi ? 'ai' : category)`. Это отделяет external API/CLI
  от локального `worker-ai` и `conv.ai`.
- 2026-08-25: YAML-каталог расположен в корне репозитория по пути
  `worker-api.yaml`, versioned и mounted read-only в контейнер; секреты в нём
  запрещены.
- 2026-08-25: OpenAPI snapshots и manifest хранятся в
  `workers/api/schema/`; startup не получает схему из сети. YAML ссылается на
  snapshot и явно задаёт проверку доступности provider/model.
- 2026-08-25: неуспешный startup ping отключает только соответствующую
  provider/model/operation возможность. `worker-api` регистрируется с
  оставшимся validated capability set и пишет структурированные безопасные логи
  о каждой недоступной возможности.
- 2026-08-25: если не прошла ни одна chat model, `worker-api` не регистрируется
  в gateway как consumer `conv.api`; backend не принимает jobs для пустого
  capability set.
- 2026-08-25: первый MVP ограничен chat/completions и выбором разрешённых
  моделей. Transcription, speech, MarkItDown и text→image остаются следующими
  этапами с отдельными контрактами и тестами.
- 2026-08-25: первым и единственным external provider MVP является g4f/aip.
  Интеграция Hermes / `myherm.xakki.ru` откладывается до отдельного grooming
  решения по его remote API, авторизации и model-selection contract.
- 2026-08-25: если выбранная g4f-модель недоступна при обработке, `worker-api`
  пробует только явно заданную в YAML fallback-цепочку разрешённых model keys
  того же provider и только после transient transport/HTTP ошибки
  (`408`/`425`/`429`/`5xx`) либо явного HTTP `404` для модели. Остальные HTTP
  `4xx`, malformed response и неожиданные ошибки fallback не запускают. Local
  `worker-ai` и произвольные внешние модели не fallback.
- 2026-08-25: выбранная модель передаётся в public request и job только как
  `options.model`. Для file и text input этот объект должен одинаково пройти
  catalog validation, сохранение `Conversion.options` и `ConversionMessage`.
- 2026-08-25: если `options.model` отсутствует, chat использует обязательный
  YAML default `routing.defaults.chat: fast`; отсутствующий или неразрешённый
  key вне YAML отклоняется backend-валидацией до постановки в очередь.
- 2026-08-25: доступные ключи моделей и labels публикуются через расширение
  существующего conversion-settings catalog. Backend формирует поле из
  validated capability set без URL, схем, secret refs и внутренних ошибок.
- 2026-08-25: первый запуск — только production-стенд; remote rollout вынесен
  из scope.
- Историческое (superseded 2026-08-25): `worker-ai` как единственный consumer
  `conv.ai` не расширяется внешними API/CLI; эту роль получает `worker-api`.

**Execution Log:**
- 2026-08-25: freeze снят, карточка переписана для текущей архитектуры;
  реализация не начиналась.
- 2026-08-26: реализован отдельный `worker-api` для MVP chat/completions: корневой
  `worker-api.yaml`, фиксированный OpenAPI snapshot с manifest, startup probe до
  создания `WsClient`, `executionKind=api` / `conv.api`, allowlist model keys,
  YAML-default и только явно объявленная fallback-цепочка. Job читает
  `_localInput`, сохраняет выбор из `options.model` либо default и возвращает
  UTF-8 JSON bytes; произвольные model IDs не принимаются.
- 2026-08-26: live-контракт перепроверен напрямую: URL
  `https://aip.xakki.ru/openapi.json`, HTTP `200`, service version `0.5`,
  `45223` bytes, SHA-256
  `dbc8edb6c12087f85e02c7794a5b28954494313817a01b2313a0012e3f15a96f`.
  Значения совпали с versioned manifest; startup снапшот из сети не загружает.
- 2026-08-26: registration payload принимает и целиком персистит optional
  `executionKind`/`settings`; admin worker catalog публикует их. Статические и
  public conversion catalogs содержат только model keys, labels и default — без
  provider URL, env-name, credential, schema URL и underlying model IDs.
- 2026-08-26: Compose добавляет `worker-api` только в dedicated `api` profile, с
  read-only mount каталога и schema dir, `WORKER_TYPE=api`, общей pull policy и
  пустым безопасным `G4F_API_KEY=` placeholder в `.env.local_example`.
  Remote Compose (`deploy/docker-compose.yml`) не изменялся.
- 2026-08-26: строгие RED→GREEN циклы предыдущих проходов сохранены как
  execution evidence: `test-python-api` сначала RED из-за отсутствующей test
  config, затем GREEN `3 passed`; startup probe RED при отсутствии worker и
  GREEN после отказа от регистрации; PHP routing RED до поддержки
  `executionKind`, GREEN после маршрута `conv_api`; text DTO RED из-за потери
  `options`, GREEN после одинакового file/text propagation; расширенное API
  behavior RED, затем GREEN для auth/error/fallback/model handling; public
  catalog/settings RED из-за утечки/неполной формы, затем GREEN только для
  key/label/default.
- 2026-08-26: дополнительный RED→GREEN этого прохода: JSON-result tests дали
  `2 failed, 15 passed`, затем `17 passed`; worker admin publication дала
  `1 failed` из `23`, затем combined worker registration/stats suite —
  `45 tests, 117 assertions`; gateway сначала остановился на collection drift
  (`CONV_ID` без `api`), затем стал GREEN.
- 2026-08-26: финальные Makefile-only проверки этого прохода:
  - `make test-python-api` — `17 passed` (оба файла:
    `test_api_worker.py` + `test_api_schema_drift.py`);
  - targeted PHP command с фильтром
    `ConversionRegistry|ConversionRequestDTO|ConversionCatalogPresenter|ConversionOptionsValidator|ConversionManagerOcr|ConversionTextInput`
    — `226 tests, 1359 assertions`;
  - `make TEST=1 test-gateway` — `224 passed, 1 skipped` (optional
    `pdf2image` отсутствует в worker-data:test);
  - `make TEST=1 test-drift` — `28 passed`;
  - `make phpstan` — application `166/166` и migrations `26/26`, `No errors`;
  - `make cs-check` — `0 of 291 files` fixable;
  - `make docker-check` — `dev: ok`, `test: ok` (ожидаемое предупреждение:
    локальный `G4F_API_KEY` не задан, Compose подставил пустую строку).
- 2026-08-26: завершающий repair после предыдущего iteration limit: обязательный
  PHP-фильтр сначала дал `1 failure` из `229 tests` — старый
  `FormatsCatalogIndependenceTest` ожидал все 402 пары при пустом registry.
  Assertion уточнён по принятому контракту: все 401 non-API пары остаются
  независимы от registry, а `txt->json` скрыт без live validated API capability.
  Повторный точный фильтр — `229 tests, 1851 assertions`, `OK`.
- 2026-08-26: итоговые QA gates после repair:
  - `make test-python-api` — `22 passed`;
  - `make TEST=1 test-gateway` — `224 passed, 1 skipped` (optional
    `pdf2image` отсутствует в worker-data:test);
  - `make TEST=1 test-drift` — `28 passed`;
  - `make phpstan` — application `167/167` и migrations `26/26`, `No errors`;
  - `make cs-check` — `0 of 293 files` fixable (после formatter repair четырёх
    прямых style failures);
  - `make docker-check` — `dev: ok`, `test: ok` с ожидаемым пустым локальным
    `G4F_API_KEY`;
  - `git diff --check` — clean;
  - `git diff --exit-code -- deploy/docker-compose.yml` — unchanged.
- 2026-08-26: reviewer repair для произвольных YAML model IDs/keys выполнен
  строгим TDD. Точный RED-фильтр новых presenter/validator тестов дал `2 tests`:
  `1 error, 1 failure` — live `quality`/`openai/gpt-4o` исчезали при пересечении
  со статическими `fast`/`balanced`. После удаления статического model allowlist
  и ввода dynamic select, чей default/options берутся только из probe-validated
  capability, тот же фильтр GREEN: `2 tests, 5 assertions`, `OK`.
- 2026-08-26: targeted PHP gate
  `make TEST=1 test-php FILTER='ApiModelAvailability|ConversionCatalogPresenter|ConversionOptionsValidator|ConversionSettingsCatalog'`
  сначала обнаружил одно устаревшее ожидание CNV-103 для `txt→json`; ожидание
  уточнено на назначенный CNV-27 профиль `api.chat`. Повтор — `267 tests, 2487
  assertions`, `OK`. Тесты подтверждают публикацию live default/choices
  `quality` и `openai/gpt-4o`, defaulting/acceptance и отказ для
  `unregistered/model`; без live capability пара по-прежнему скрыта и запрос
  отклоняется.
- 2026-08-26: обязательные QA gates после arbitrary-model repair:
  - `make test-python-api` — `22 passed`;
  - `make TEST=1 test-gateway` — `224 passed, 1 skipped` (optional
    `pdf2image` отсутствует в worker-data:test);
  - `make TEST=1 test-drift` — `28 passed`;
  - `make phpstan` — application `167/167` и migrations `26/26`, `No errors`;
  - `make cs-check` — `0 of 293 files` fixable;
  - `make docker-check` — `dev: ok`, `test: ok` с ожидаемым предупреждением о
    пустом локальном `G4F_API_KEY`;
  - `git diff --check` — clean;
  - `git diff --exit-code -- deploy/docker-compose.yml` — unchanged.
- 2026-08-26: финальный partial-list startup repair выполнен строгим TDD.
  Первый `make test-python-api` выявил ошибку только в новом test fixture
  (`WsClientConfig.__init__()` без обязательных аргументов); после исправления
  fixture без production-кода корректный RED дал `1 failed, 22 passed`:
  `test_startup_registers_surviving_list_model_as_live_default` воспроизвёл
  `RuntimeError: coroutine raised StopIteration`. Минимальный GREEN в
  `workers/api/worker.py::capabilities` сначала сохраняет первый surviving model
  из явной fallback-цепочки configured default, а при её полном отсутствии
  выбирает первый validated model в YAML-порядке. `choices` строится в том же
  детерминированном YAML-порядке. Повторный `make test-python-api` — `23 passed`;
  существующий mapping fallback regression также GREEN.
- 2026-08-26: итоговые QA gates после partial-list repair:
  - `make TEST=1 test-php FILTER='ApiModelAvailability|ConversionCatalogPresenter|ConversionOptionsValidator|ConversionSettingsCatalog'`
    — `267 tests, 2487 assertions`, `OK`;
  - `make TEST=1 test-gateway` — `224 passed, 1 skipped` (optional
    `pdf2image` отсутствует в worker-data:test);
  - `make TEST=1 test-drift` — `28 passed`;
  - `make phpstan` — application `167/167` и migrations `26/26`, `No errors`;
  - `make cs-check` — `0 of 293 files` fixable (информационное предупреждение:
    fixer запущен на PHP 8.5.7 при minimum PHP 8.1 проекта);
  - `make docker-check` — `dev: ok`, `test: ok` с ожидаемым предупреждением о
    пустом локальном `G4F_API_KEY`;
  - `git diff --check` — clean;
  - `git diff --exit-code -- deploy/docker-compose.yml` — unchanged.
- 2026-08-26: blocking review repair запинил нормализованный provider origin на
  `https://aip.xakki.ru`: отклоняются alternate host/subdomain, userinfo,
  non-default port, HTTP, path, query и fragment до отправки bearer auth/payload.
  Строгий RED `make test-python-api` — `6 failed, 27 passed`; после минимального
  config fix тот же GREEN — `33 passed`. Канонический queue contract и соседняя
  topology doc теперь включают `conv.api`/`api` и фактический приоритет
  `executionKind ?? (isAi ? 'ai' : category)`, сохраняя browser как transport
  без consumer. Focused checks: `make TEST=1 test-php FILTER='ConversionRegistry'`
  — `62 tests, 210 assertions`, `OK`; `make TEST=1 test-drift` — `28 passed`;
  `git diff --check` — clean; `deploy/docker-compose.yml` unchanged.
- 2026-08-26: fix-cycle 2/2 закрыл оставшийся queue-contract doc drift: job-field
  formula теперь учитывает `executionKind`; drift coverage и `WORKER_TYPE`
  inventory включают активный `api`, а `browser` явно остаётся transport/stream
  без consumer и live catalog pair. Соседний terse comment в `WsClientConfig`
  синхронизирован с текущими worker types. Targeted search двух канонических docs
  не нашёл старой formula/inventory; `make TEST=1 test-drift` — `28 passed`;
  `git diff --check` — clean.
- 2026-08-26: финальная интеграция discrepancy repair batch подтвердила девять
  operational/contract блоков: (1) canonical provider-origin/credential/schema
  validation; (2) mapping и list model allowlists; (3) partial-probe capability
  и детерминированный live default; (4) fallback только по явно объявленной
  цепочке после transport/HTTP transient или HTTP 404; (5) безопасный JSON
  result и error classification; (6) dynamic public model catalog без
  внутренних данных; (7) registration persistence, liveness admission и
  unavailable-model rejection; (8) production-only image/health/version/logging/
  lifecycle/resource wiring; (9) `executionKind=api`/`conv.api` routing и drift
  guards. Нового production behavior в интеграционном проходе не потребовалось,
  поэтому новый RED не создавался; точные RED→GREEN evidence предыдущих repair
  циклов выше сохранены без переинтерпретации.
- 2026-08-26: шесть current-live inventory surfaces синхронизированы и
  перепроверены отдельно от защищённых historical/deploy docs: (1) README
  service inventory; (2) ROADMAP scope/pair/quota/fallback statements; (3)
  `QuotaTier` contract comment; (4) Python-worker agent inventory; (5) queue
  topology/contract inventories; (6) local smoke inventory с явным исключением
  provider-backed `api` без live provider calls. Historical design/migrations,
  deploy docs и `deploy/docker-compose.yml` не правились.
- 2026-08-26: resource limits зафиксированы консервативно для network-bound
  adapter: reservation `0.05 CPU`/`64M`, limit `0.5 CPU`/`256M`; headroom нужен
  для concurrent HTTP buffers и catalog/schema parsing, а не для локального
  inference. Стартовый YAML карточки исправлен по фактическому MVP (`kind: g4f`,
  snapshot `v0.5`, только chat/completions), статус — `test`, execution kind —
  `api`, stream — `conv.api`.
- 2026-08-26: финальные integration checks: `make test-python-api` — `50
  passed`; CNV-27 PHP filter — `436 tests, 3151 assertions` (одна существующая
  PHPUnit deprecation); `make TEST=1 test-gateway` — `224 passed, 1 skipped`
  (optional `pdf2image`); final focused quota/GC filter — `11 tests, 17
  assertions`; `make TEST=1 test-drift` — `31 passed`; `make phpstan` —
  application `167/167` и migrations `26/26`, no errors; `make cs-check` — `0
  of 294 files` fixable; `make docker-check` — `dev: ok`, `test: ok` с ожидаемым
  пустым локальным `G4F_API_KEY`; current-live drift scan — `637` файлов, `0`
  stale matches по шести группам; added-secret scan — `0`; `git diff --check` —
  clean; protected deploy/historical path diff — empty.
- 2026-08-26: review findings 3/4 repair отделил production-only операции:
  `build-workers` и `worker-logs` снова покрывают только шесть remote-safe
  образов/контейнеров, новые `build-server-workers`/`server-worker-logs`
  добавляют `worker-api`, а `release-workers` зависит от server aggregate.
  Static ops guard сначала RED (`build-server-workers` отсутствовал), затем
  GREEN — `3 passed`; generated `make help` показывает оба scope явно.
- 2026-08-26: current-live contract/comment repair синхронизировал durable
  admission обычных очередей с fresh-alive API exception, precedence
  `executionKind` перед `isAi`, 8 `conv_*` transports, 7 registering workers и
  402 catalog pairs. Focused checks: `make TEST=1 test-drift` — `31 passed`;
  PHP filter `ConversionRegistryGolden|ConversionRegistryReduceCapabilities|MessengerWorkerTypeTransportDrift`
  — `11 tests, 79 assertions`; `make docker-check` — `dev: ok`, `test: ok`;
  `git diff --check` — clean.
- 2026-08-26: review finding 1 закрыт строгим RED→GREEN: новый
  `RemoteProtocolError` regression сначала падал при одном provider call, тогда как
  boundary `TooManyRedirects` уже проходил; после замены узкого catch на
  `httpx.TransportError` оба теста GREEN. `TooManyRedirects` остаётся вне catch и
  не запускает fallback. Полный worker-api gate — `52 passed`.
- 2026-08-26: review finding 2 закрыт вертикальными RED→GREEN slices:
  `ApiModelAvailability` fail-closed при любой malformed fresh/alive API row;
  API registration до `upsert()` требует `executionKind=api`, только `api`
  routing key/stream и валидный `settings.model` с default среди choices.
  Поведение регистрации остальных worker types не изменено. Focused GREEN —
  `30 tests, 79 assertions`.
- 2026-08-26: review finding 5 закрыт функциональными controller tests с реальной
  fresh API capability: multipart `options[model]=balanced` сохраняется в
  dispatched `ConversionMessage`, а text path без выбора получает live default
  `fast`. RED — `2 failed`; GREEN — `2 tests, 7 assertions`; весь
  `ConversionTextInputControllerTest` — `13 tests, 33 assertions`.
- 2026-08-26: финальная cumulative verification после пяти repair findings:
  CNV-27 PHP filter — `468 tests, 3246 assertions` с одной существующей PHPUnit
  deprecation; `make test-python-api` — `52 passed`; `make TEST=1 test-drift` —
  `31 passed`; PHPStan application `167/167` и migrations `26/26`, no errors;
  `cs-check` — `0 of 294 files`; `docker-check` — `dev: ok`, `test: ok` с
  ожидаемым пустым локальным `G4F_API_KEY`; `git diff --check` — clean;
  protected deploy/historical path diff — empty. Residual current-live scan
  нашёл только защищённый `CLAUDE.md` (и его symlink `AGENTS.md`) со старым
  release worker count, HTTP pull statement и API/browser stream inventory:
  Hermes write guard отклонил явную правку после approval timeout, поэтому этот
  один documentation blocker остаётся внешним к рабочему дереву.
- 2026-08-26: активная запись `distributed-workers` в `ROADMAP.md` исправлена на
  публичный `wss://` WS-Gateway + Symfony API без прямого доступа к KeyDB/S3.
- 2026-08-26: выражение образа `worker-ai` в `README.md` синхронизировано с
  Compose: `${IMAGE_NS}/worker-ai:${IMAGE_TAG:-latest}-${AI_VARIANT:-cpu}`.
- 2026-08-26: blocking strict-list repair выполнен TDD на обеих границах
  capability contract. Registration regression для associative/object
  `settings.model.choices` RED: `1 test, 1 assertion, 1 failure` (controller
  дошёл до запрещённого `upsert()` и вернул `500` вместо `400`). Multi-instance
  fresh-row regression RED: `1 test, 1 assertion, 1 failure` (malformed row
  был принят и опубликован вместо fail-closed `null`). Минимальный GREEN добавил
  `array_is_list()` в `ApiCapabilityContract`; совместный точный повтор — `2
  tests, 4 assertions`, полный focused `WorkerRegisterControllerTest|ApiModelAvailabilityTest`
  — `43 tests, 94 assertions`. Точные `routingKeys=['api']` и `streams=['api']`
  остались без изменений и покрыты тем же focused run.
- 2026-08-26: финальная evidence-сводка CNV-27: broad PHP — `336 tests, 1779
  assertions`, одна существующая deprecation; worker-api exact read-only
  container equivalent для `test_api_worker.py` + `test_api_schema_drift.py` —
  `52 passed` (literal `make test-python-api` в этом проходе не заявляется);
  drift — `31 passed`; PHPStan — application `168/168`, migrations `26/26`, no
  errors; CS — `0 of 295 files` fixable; Docker config — `dev: ok`, `test: ok`;
  `git diff --check` — clean. Current-live docs проверены; protected deploy и
  historical paths не изменены.
- 2026-08-26: финальная независимая verification — PASS: focused PHP — `43 tests,
  94 assertions`; broad relevant PHP — `482 tests, 3248 assertions`, одна
  существующая PHPUnit deprecation; literal `make test-python-api` — `52 passed`;
  `make test-python-api` side effects restored exactly; drift — `31 passed`;
  PHPStan — application `168/168` и migrations `26/26`, `No errors`; CS — `0 of
  295 files` fixable; Docker config — `dev: ok`, `test: ok`; `git diff --check` —
  clean. Exact stale contradiction scans — README `0`, ROADMAP `0`; protected
  deploy/migrations/done/design unchanged; historical ROADMAP Stage 1
  byte-identical. Ожидаемые non-blocking warnings: существующая PHPUnit
  deprecation, пустой локальный `G4F_API_KEY` и PHP CS Fixer на PHP 8.5.7 при
  minimum PHP 8.1 проекта.
- 2026-08-26: финальный HTTP 503 public-contract blocker закрыт без изменения
  runtime behavior. OpenAPI для `POST /api/v1/convert` и
  `POST /api/v1/convert/{id}/retry`, controller comments и docblock
  `WorkerUnavailableException` теперь различают fresh-alive API admission
  (каждая свежая строка соответствует `ApiCapabilityContract`, общее множество
  валидированных моделей непусто) и durable capability admission остальных
  worker types без liveness-требования. Новый OpenAPI regression сначала RED:
  `1 test, 2 assertions, 1 failure` на старом «никогда не регистрировался», затем
  focused GREEN — `1 test, 5 assertions`. Relevant PHP filter
  `ConversionOpenApiTest|ConversionManagerWorkerAvailability|ApiModelAvailability`
  — `29 tests, 85 assertions`; PHPStan — application `168/168`, migrations
  `26/26`, `No errors`; CS — `0 of 295 files` fixable (то же информационное
  предупреждение PHP 8.5.7/minimum PHP 8.1); `git diff --check` — clean.
- 2026-08-26: test-stack credential isolation исправлена строгим TDD. Новый
  profile-matrix guard сначала дал ожидаемый RED: `1 failed, 31 passed`,
  `worker-api` имел `server` вместо отдельного `api`. Минимальный GREEN перевёл
  сервис на `profiles: ["api"]`, добавил `api` только в tracked main `.env`,
  оставил `.env.test` и remote example без `api`, а явные server API recreate/log
  операции активируют `server,api`; focused drift — `32 passed`. После удаления
  старого orphan через Makefile test project был пуст, canonical `make test`
  завершился exit 0 без `G4F_API_KEY`: PHPUnit `1058 tests, 5963 assertions`,
  worker-api `52 passed`, gateway `224 passed, 1 skipped`, drift `32 passed`.
  `make TEST=1 ps` во время полного прогона показал 11 test services и ни одного
  `worker-api`; final `make test-down` + `make TEST=1 ps` left the test project
  empty. Единственный credential-related вывод — ожидаемое Compose warning о
  blank `G4F_API_KEY`, provider requests не выполнялись.
- 2026-08-30: final readiness handoff на ветке `task/CNV-27`, commit
  `93efdb9` (`api: initialize worker API logging`), рабочее дерево до записи
  было чистым. Повторены Makefile-only gates: `make test-python-api` —
  `52 passed`; `make TEST=1 test-drift` — `32 passed`; targeted
  `make TEST=1 test-php FILTER='ConversionRegistry|ConversionRequestDTO|ConversionCatalogPresenter|ConversionOptionsValidator|ConversionManagerOcr|ConversionTextInput|WorkerRegisterController|ApiModelAvailability|ConversionOpenApi'`
  — `277 tests, 1485 assertions`, `OK`; `make phpstan` — application
  `168/168` и migrations `26/26`, `No errors`; `make cs-check` — `0 of 295`
  files fixable; `make docker-check` — `dev: ok`, `test: ok`; `git diff --check`
  — clean. Ожидается только предупреждение PHP CS Fixer о PHP 8.5.7 при
  minimum PHP 8.1 и стандартный Docker build warning в API test image.
- 2026-08-30: текущий production runtime подтверждён через Makefile logs/ps:
  `worker-api` и `ws-gateway` healthy; G4F `fast` получил HTTP 200, а
  `aip-g4f/balanced` — HTTP 500 от внешнего upstream и записан как
  `startup capability unavailable`, поэтому balanced документирован как
  внешне недоступный и fail-closed, без удаления из YAML/fallback-каталога.
  Gonka выполнил authenticated `GET /v1/models` с HTTP 200; `fast`, `normal` и
  `hard` получили `startup capability validated`. Затем `worker-api` подключился
  к gateway, зарегистрировался как `workerType=api`, получил stream `conv.api`,
  а Symfony registration вернула HTTP 200. В логах не раскрываются credentials,
  payload или upstream model IDs.

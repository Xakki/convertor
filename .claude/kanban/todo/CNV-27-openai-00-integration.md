
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
AI-инференсом. Первый внешний backend — self-hosted g4f на
`https://aip.xakki.ru`, включая OpenAI-compatible API и native MarkItDown.

Настройки доступных backend'ов, CLI и моделей задаются отдельным YAML-файлом.
У операции может быть список конкретных имён моделей либо map логических ключей
на конкретные модели. В запросе передаётся ключ, если он объявлен; иначе —
разрешённое значение модели. YAML описывает разрешённый каталог и параметры
выполнения; реальные секреты в него не попадают и берутся только из `.env.local`
по именам переменных. При старте `worker-api` выполняет ping каждой объявленной
пары API/model до регистрации в gateway.

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
Создать отдельный EPIC после закрытия вопросов ниже. В EPIC реализовать YAML
каталог, его схему и валидацию; `worker-api`; адаптеры `openai_compatible`,
`g4f_native` и локальных CLI; ограниченный allowlist моделей; startup health
checks; public API для выбора модели; AI quota defaults; новый execution kind и
queue contract; production-only deployment и целевые тесты.

Предлагаемая стартовая структура YAML (без секретов):

```yaml
version: 1
providers:
  - id: aip-g4f
    kind: g4f_native
    base_url: https://aip.xakki.ru
    schema: aip-g4f/v1
    credentials:
      bearer_env: G4F_API_KEY
    operations:
      chat:
        endpoint: /v1/chat/completions
        models:
          fast:
            model: deepseek-chat
            fallback: [opus]
          opus:
            model: anthropic/opus-5
      transcription:
        endpoint: /v1/audio/transcriptions
        models: [approved-stt-model]
      speech:
        endpoint: /v1/audio/speech
        models: [approved-tts-model]
      markitdown:
        endpoint: /api/markitdown
    startup_check:
      mode: model_request
      timeout_sec: 15
      on_failure: refuse_registration
  - id: external-openai
    kind: openai_compatible
    base_url: https://example.invalid/v1
    schema: external-openai/v1
    credentials:
      bearer_env: EXTERNAL_OPENAI_API_KEY
    operations:
      chat:
        models: [openai/chatgpt5.6]
cli_backends: []
routing:
  defaults:
    chat: fast
  allow_model_override: true
```

**Acceptance Criteria:**
- CNV-27 находится в `grooming/`; прежний freeze снят, исторический scope
  заменён на актуальный и карточка не содержит credential value.
- Зафиксировано: отдельный `worker-api` для внешних API/CLI; g4f/aip — первый
  внешний provider; rollout первого этапа — только production-стенд.
- YAML-каталог хранится в корне репозитория как `worker-api.yaml`, содержит
  только декларативную конфигурацию и ссылки на env-переменные; секреты
  находятся исключительно в `.env.local`.
- До перехода в `todo/` определены schema YAML, приоритет/provider routing,
  execution kind/stream `worker-api`, изоляция/деплой секрета и политика startup
  ping failure.
- Контракт g4f подтверждён свежей OpenAPI-спецификацией перед реализацией;
  проверены auth и результаты endpoint'ов, а не только старый дамп.
- Реализация будет покрыта pytest и соответствующими проверками `make test`.
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
  того же provider. Local `worker-ai` и произвольные внешние модели не fallback.
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

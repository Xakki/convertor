# Add openAI 

* docs https://aip.xakki.ru/docs#/
* G4F_API_KEY=urw5crp4ytf*MFX9trh

реализуй интерфесы на этой основе :
- convertFileToMarkdown() -  /api/markitdown
- transcriptAudioToText() - /v1/audio/transcriptions
- textToAudio() - /api/audio/speech
- textToImage() - /images/{filename}


-------------------
# Краткая сводка

OpenAPI v0.5, 21 path / 31 операция. Три слоя авторизации (важно для понимания):

┌─────────────────────────┬───────────────────────────┬───────────────────────────────────────────────┐                                                                                                                                                                                                                                                             
│          Слой           │            Где            │                    На что                     │                                                                                                                                                                                                                                                             
├─────────────────────────┼───────────────────────────┼───────────────────────────────────────────────┤                                                                                                                                                                                                                                                             
│ nginx Basic auth        │ aip.xakki.ru.conf:166-168 │ всё кроме exempt                              │                                                                                                                                                                                                                                                             
├─────────────────────────┼───────────────────────────┼───────────────────────────────────────────────┤                                                                                                                                                                                                                                                             
│ nginx hard-bearer guard │ строки 38/56/76/95        │ /v1/embeddings*, /v1/chat/completions/mistral │                                                                                                                                                                                                                                                             
├─────────────────────────┼───────────────────────────┼───────────────────────────────────────────────┤                                                                                                                                                                                                                                                             
│ FastAPI middleware      │ __init__.py:228-316       │ /v1/*, /api/*, /pa/*                          │                                                                                                                                                                                                                                                             
└─────────────────────────┴───────────────────────────┴───────────────────────────────────────────────┘

Exempt от Basic-auth: /v1/, /dist/, /health.html, /docs, /redoc, /openapi.json, /swagger*.                                                                                                                                                                                                                                                                          
Exempt от FastAPI Bearer: пути на /models, /images/, /media/.

Группы endpoint'ов

Models — GET /v1/models?only_healthy=0&stale_hours=24 (787 моделей live, OpenAI-совместим), GET/POST /v1/models/{name}, GET /api/{provider}/models.

Chat — POST /v1/chat/completions, POST /api/{provider}/chat/completions, POST /api/{provider}/{conversation_id}/chat/completions (multi-turn). Body — schema ChatCompletionsConfig (g4f/api/stubs.py:58): messages, model, provider, stream, images[], media[], tools[], reasoning_effort, response_format, web_search, conversation, и т.д.

Image/Media — POST /v1/images/generate (+/generations алиас), POST /v1/media/generate, POST /api/{provider}/images/generations. Body ImageGenerationConfig (stubs.py:76).

Audio — POST /v1/audio/transcriptions (Whisper-совместим, multipart), POST /v1/audio/speech (TTS, body AudioSpeechConfig — кстати с опечаткой instrcutions в stubs.py:162), POST /api/markitdown.

Providers — GET /v1/providers, GET /v1/providers/{provider} — см. раздел 3.

PA-providers (Playable Agent, кастомные провайдеры из workspace) — GET /pa/providers, GET /pa/providers/{id}, POST /pa/chat/completions, POST /pa/{id}/chat/completions, GET /pa/files/{path} (с CSP).

Misc — POST /v1/upload_cookies (multipart .json/.har), GET /api/{provider}/quota, GET /images/{file}, GET /media/{file}, GET /thumbnail/{file} (PIL).

Nginx-only прокси (НЕ в openapi.json):
- POST /v1/embeddings → Google generativelanguage.googleapis.com (захардкоженный ключ в vhost)
- POST /v1/embeddings/jina → Jina
- POST /v1/embeddings/mistral → Mistral
- POST /v1/chat/completions/mistral → Mistral
- GET /dist/* → Flask-статика SPA

  ---                                                                                                                                                                                                                                                                                                                                                                 
3. /v1/providers и /v1/providers/{provider} — ответ на вопросы

/v1/providers (код __init__.py:867-877) — возвращает только working=True (live: 76 провайдеров). Никакого needs_auth-учёта, никакого live-healthcheck. Атрибут working — статический class-attribute провайдера (можно глобально выключить через AppConfig.ignored_providers).

/v1/providers/{provider} (код __init__.py:879-903) — чистая статика, никакого live-вызова:
- safe_get_models() зовёт provider.get_models() (у большинства — hard-coded список или одноразовый caching-fetch метаданных, не chat-проба). Если падает — возвращает [] (бессигнал).
- image_models, default_vision_model, get_parameters() — class-атрибуты / inspect kwargs.

Связь с healthcheck'ом:
- scripts/g4f_healthcheck.py — отдельный процесс, пишет в ~/.g4f/cookies/.health/<Provider>.json структуру {last_check_ts, last_ok_ts, latency_ms, model_used, last_error, preview}.
- /v1/providers и /v1/providers/{provider} healthcheck-данные не учитывают вообще. Провайдер с working=True, но мёртвый по healthcheck'у, всё равно в списке.
- Единственное место в API, где учитывается healthcheck — GET /v1/models?only_healthy=1 (через ModelRegistry.get_healthy_providers, g4f/models.py:113). По умолчанию only_healthy=0 — fail-open.

Live-цифры: из 76 working-провайдеров только 19 реально прошли последний healthcheck (summary: ok=19, stale=4, dead=16, skip=37).

  ---                                                                                                                                                                               
4. Все ресурсы под https://aip.xakki.ru/*

┌────────────────────────────────────────────────────┬──────┬────────────────────────┬───────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                        Путь                        │ HTTP │       Кто отдаёт       │                                                    Что                                                    │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /                                                  │ 401  │ nginx Basic            │ g4f GUI home                                                                                              │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /docs, /redoc, /openapi.json                       │ 200  │ FastAPI                │ спека                                                                                                     │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /swagger*                                          │ 301  │ nginx                  │ алиасы → /docs                                                                                            │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /health.html                                       │ 200  │ FastAPI (exempt)       │ таблица health                                                                                            │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /health.json                                       │ 401  │ nginx (баг exempt)     │ machine-readable health                                                                                   │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /v1                                                │ 301  │ FastAPI                │ заглушка → /v1/models                                                                                     │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /swagger*                                          │ 301  │ nginx                  │ алиасы → /docs                                                                                            │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /health.html                                       │ 200  │ FastAPI (exempt)       │ таблица health                                                                                            │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /health.json                                       │ 401  │ nginx (баг exempt)     │ machine-readable health                                                                                   │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /v1                                                │ 301  │ FastAPI                │ заглушка → /v1/models                                                                                     │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /v1/models                                         │ 200  │ FastAPI                │ 787 моделей                                                                                               │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /v1/providers, /v1/chat/completions, …             │ 401  │ FastAPI Bearer         │ OpenAI-совместимое API                                                                                    │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /v1/embeddings*, /v1/chat/completions/mistral      │ 401  │ nginx hard-bearer      │ проксируются наружу                                                                                       │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /chat/, /private/, /apps/                          │ 401  │ nginx → Flask          │ родной gpt4free UI                                                                                        │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /playground/                                       │ 401  │ nginx → Flask          │ LLMPlayground SPA                                                                                         │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /backend-api/v2/*                                  │ 401  │ nginx → Flask          │ backend для UI (conversation, models, providers, oauth, synthesize, usage, quota, log, files, public-key) │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /dist/*                                            │ 200  │ nginx (exempt) → Flask │ SPA-статика                                                                                               │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /images/{f}, /media/{f}, /thumbnail/{f}            │ 200  │ FastAPI                │ медиа-выдача                                                                                              │
├────────────────────────────────────────────────────┼──────┼────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ /pa/providers, /pa/chat/completions, /pa/files/{p} │ 401  │ nginx → FastAPI        │ Playable Agent                                                                                            │
└────────────────────────────────────────────────────┴──────┴────────────────────────┴───────────────────────────────────────────────────────────────────────────────────────────────────────────┘

/static/ нет — Flask отдаёт статику под /dist/. Контейнер g4f не публикует портов наружу — только через shared-nginx-1 (сеть common).

**Rename intent:** this card is to be renamed `external-ai-worker` (file kept as-is for now).

**Decisions:**
- Implement external/hosted AI as a **SEPARATE worker** ("external-ai" worker) whose ONLY job is calling an external AI API (Q7.1, Q7.3) — kept apart from the local-only inference AI worker, so the local-only design of the existing AI worker is NOT reversed.
- **Q7.2 РАЗРЕШЁН ресёрчем (см. секцию ниже); остаются точечные scope-решения (a–f) перед переносом в todo**. The 4 interfaces are heterogeneous: STT/TTS = hosted alt-backend ; markitdown = new doc→md (overlaps OCR) ; text→image = NEW conversion category (absent from FileCategory).
- Card endpoint nit: textToImage generation = POST /v1/images/generate (card's GET /images/{f} is retrieval).

**Status:** grooming — blocked on Q7.2 research. Rename intent: external-ai-worker.

## Ресёрч Q7.2 (2026-07-17) — возможности внешнего endpoint

- Endpoint определён: `https://aip.xakki.ru` — self-hosted **gpt4free (g4f)** инстанс, НЕ публичный OpenAI. OpenAI-совместимый surface (`/v1/*`) + native g4f (`/api/markitdown`, `/images/{f}`). Auth: `G4F_API_KEY` (в репозитории пока нигде нет).
- В репозитории проводки НЕТ: grep по `aip.xakki|g4f|G4F_API_KEY` пуст. Существующий `workers/ai/` — ЯВНО local-only (faster-whisper STT, espeak/pyttsx3 TTS, ollama/llama.cpp LLM). Стрим `conv.ai` уже существует и принадлежит локальному воркеру.
- Статус по 4 возможностям:
  - **STT** — частично (локальный Whisper есть, хостовый alt не подключён; `POST /v1/audio/transcriptions`).
  - **TTS** — частично (локальный есть, хостовый alt не подключён; `POST /api/audio/speech`).
  - **markitdown (doc→md)** — нет вовсе (`POST /api/markitdown`, пересекается с OCR/Document).
  - **text→image** — нет вовсе, НОВАЯ категория (правильный путь `POST /v1/images/generate`, а не `GET /images/{f}`; ломает file→file модель, т.к. источник — текст).
- Оставшиеся вопросы scope:
  - (a) имя нового воркера external-ai и роутинг, чтобы не коллидировать с локальным `conv.ai`;
  - (b) где живут `G4F_API_KEY` и base URL (`.env.local`);
  - (c) markitdown как новый target внутри Document или отдельный флаг (нужно решение Registry);
  - (d) text→image требует новый FileCategory case или спец-обработку не-файлового источника;
  - (e) выбор моделей/провайдеров (у aip 787 моделей, ~19 проходят healthcheck — риск флаки, нужно пиннить);
  - (f) слоёная авторизация Bearer vs Basic по путям.
- Риск/допущение: отчёт опирается на OpenAPI-дамп из карточки, живого пробинга aip.xakki.ru не было.
                                                                                                                                     


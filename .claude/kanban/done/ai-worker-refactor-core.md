### AI-воркер — рефактор ядра: разбивка модулей, удаление внешних API, флаг PULL_ENABLED

**Критичность:** High

**TAGS:**
- tech-debt
- feature

**Описание:**
Переписать `workers/ai` в читаемый, разбитый по ответственности код. Монолитный
`worker.py` (~513 строк) расщепляем на модули. Параллельно **убираем из воркера всю
логику внешних API** (OpenAI / Gemini / Claude SDK, fallback-цепочки, env-ключи) —
в воркере остаётся ТОЛЬКО локальный инференс. Добавляем единый флаг включения
обработки реальной очереди.

Это фундамент эпика рефактора AI-воркера; блокирует остальные карточки
[[ai-worker-llm-text2text]] · [[ai-worker-devserver]] · [[ai-worker-benchmarks]] · [[ai-worker-docker-images]].

**Целевая структура модулей:**
```
workers/ai/
├── __main__.py     # точка входа: режим worker | devserver
├── config.py       # единый источник конфига (env → dataclass), валидация
├── worker.py       # прод pull-loop (claim/input/result/fail), gate по PULL_ENABLED
├── pull_api.py     # тонкий HTTP-клиент backend pull-API
├── convert.py      # деривация mode из (src,tgt) + диспатч к провайдерам (flag-agnostic)
├── providers/
│   ├── base.py     # интерфейсы провайдеров
│   ├── stt.py      # ТОЛЬКО локальный faster-whisper
│   ├── streaming_stt.py
│   ├── tts.py      # ТОЛЬКО локальный espeak-ng / pyttsx3
│   └── embedding.py
└── utils.py        # mime, форматирование субтитров (SRT/VTT)
```
(`providers/llm.py` добавляется в [[ai-worker-llm-text2text]]; пакет `devserver/` и
`bench/store.py` — в своих карточках.)

**Проблема:**
- `worker.py` смешивает poll-loop, диспатч, провайдеры, выбор внешних API — нечитаемо,
  тяжело тестировать и расширять.
- Код внешних провайдеров (openai/gemini/claude) больше не нужен (решение: воркер —
  только локальный инференс), но тянет зависимости, ключи и fallback-сложность.
- Тесты `workers/tests/test_ai_worker.py` патчат функции (`_speech_to_text`, `_tts_*`…),
  которые при прошлом рефакторе уехали в `providers/` — тесты рассинхронены.

**Рекомендация:**
1. Расщепить `worker.py` по модулям выше; каждый модуль — одна ответственность,
   тестируемый изолированно.
2. `config.py` — единый dataclass-конфиг из env (поля для STT/TTS/embedding/LLM/pull),
   единственный источник истины; убрать россыпь `os.getenv` по коду.
3. **Удалить** провайдеры внешних API и связанные env (`AI_STT_PROVIDER`,
   `AI_TTS_PROVIDER`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `CLAUDE_API_KEY`), fallback-цепочки.
   STT — только faster-whisper; TTS — только espeak-ng/pyttsx3.
4. `convert.py` остаётся **flag-agnostic**: режим выводится ТОЛЬКО из пары форматов
   (audio→{txt,srt,vtt}=STT; {txt,md}→audio=TTS; text→text=LLM (см. [[ai-worker-llm-text2text]]));
   на невыводимой паре — ошибка. `subType`/флаги воркер не читает.
5. Флаг **`PULL_ENABLED`** (default `false`): в worker-режиме при `false` воркер не тянет
   задачи (idle + предупреждение в лог), при `true` — обычный poll-loop. Защита от
   случайного разбора реальной очереди при локальной разработке.
6. Привести `workers/tests/test_ai_worker.py` к новой структуре (патчи на новые модули),
   снять рассинхрон.

**Влияние:**
Без рефактора дальнейшие фичи (dev-сервер, бенчмарки, LLM) наслаиваются на нечитаемый
монолит; внешние SDK раздувают «лёгкий» публикуемый образ.

**Критерии приёмки:**
- `workers/ai` разбит по модулям выше; в `worker.py` нет провайдерной/внешней логики.
- В коде воркера НЕТ обращений к OpenAI/Gemini/Claude SDK и их env-ключей.
- STT/TTS/embedding — только локальные; `convert.py` flag-agnostic (покрыто тестом
  деривации режима из форматов).
- `PULL_ENABLED=false` по умолчанию: воркер не клеймит задачи; при `true` — клеймит.
- `config.py` — единый источник конфига; остальные модули читают конфиг через него.
- Тесты `pytest workers/tests` зелёные; `make phpstan` не затрагивается (PHP не меняется).

**Decisions:**
- Удаление внешних API касается ТОЛЬКО скрипта воркера. Карточка [[add-open-ai]]
  (шлюз `aip.xakki.ru`/g4f) — про бэкенд, её судьбу пользователь решает отдельно.
- LLM (text→text) выносится в отдельную карточку [[ai-worker-llm-text2text]].
- Переосмысливает раздел про гибридные провайдеры из [[validate-ai-worker]] (ready/):
  направление сменилось на «только локальный инференс».

## Execution Log

Branch `task/ai-worker-refactor-core`.

### Final module layout (workers/ai/)
- `__main__.py` — entry point; `worker` mode (default) + `devserver` stub branch (deferred).
- `config.py` — single config source; `load_config()` → frozen `Config` dataclass; `.validate()` (only enforces token when `pull_enabled`). All `os.getenv` reads live here. Side-effect-free import. NO LLM fields, NO external-API keys.
- `worker.py` — production pull-loop only; `CAPABILITIES`, `_process_job`, `_poll_loop`, `run(cfg)`. PULL_ENABLED gate. No provider/external logic.
- `pull_api.py` — `PullApiClient` thin httpx wrapper for `/api/v1/worker/{claim,jobs/{id}/input,result,fail}`.
- `convert.py` — flag-agnostic `derive_mode()` + `convert(job, cfg)` dispatch. `Mode` enum.
- `providers/` — `base.py` (Protocols), `stt.py` (local faster-whisper only), `streaming_stt.py`, `tts.py` (espeak/pyttsx3 only), `embedding.py`. Heavy ML imports made lazy (faster_whisper/sentence_transformers/pyttsx3 not installed in CI).
- `utils.py` — flat (mime + SRT/VTT formatting).

### Deleted
- External-API provider code: `_openai`/`_gemini`/`_claude` (stt.py), `_openai` (tts.py) and all fallback/selector logic.
- Env keys dropped (not migrated to config.py): `AI_STT_PROVIDER`, `AI_TTS_PROVIDER`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `CLAUDE_API_KEY`.
- Tests: `test_stt_falls_back_to_local_on_provider_error`, `test_stt_local_provider_does_not_fallback`, `test_tts_falls_back_to_local_on_provider_error` (external/fallback — deleted, not repaired).
- `taskType` override of format-derived mode + `"stt"`/`"tts"` aliases removed (flag-agnostic).

### Decisions settled
1. **Capabilities = `routing_keys: ["ai"]`, `matrix: {}`.** PHP `streamFor()` only ever emits `'ai'` for AI pairs; `embedding`/`speech_to_text_stream` are NOT PHP routing streams, so they are not advertised (they are sub-behaviours derived from the format pair inside the worker). Empty matrix is required: AI pairs exist in the registry only as virtual `*_stt`/`*_tts` keys which the drift subset-assertion skips — advertising concrete pairs would falsely fail Assertion B. Satisfies `test_capabilities_routing_key == ["ai"]` AND both routing_drift assertions (verified green against the real PHP registry).
2. **utils = flat `utils.py`** (matches card tree). Only the broken `stt.py` used the package form (`utils.mime`/`utils.subtitles`); repointed to flat. Least churn.
3. **Mode derivation keeps 4 modes (no orphaning).** convert.py derives: `audio→{txt,srt,vtt}`=STT, `audio→json`=streaming STT, `{txt,md}→audio`=TTS, `{txt,md}→json`=embedding. This keeps `streaming_stt.py` + `embedding.py` reachable (otherwise only bench would touch them = silent capability loss). All pairs unambiguous vs other workers (data worker only handles csv/json/xml/yaml/toml). text→text (LLM) intentionally falls through to ValueError (deferred to ai-worker-llm-text2text).

### Verification
- `python -c "import workers.ai.worker, workers.ai.convert, workers.ai.config"` OK in bare env (no worker env vars).
- `pytest workers/tests/test_ai_worker.py workers/tests/test_routing_drift.py -q` → 50 passed, 1 skipped (espeak-ng e2e).
- Full `pytest workers/tests/` → 226 passed, 10 skipped, 1 xfailed.
- No OpenAI/Gemini/Claude SDK/env-key references in workers/ai (only docstring mentions of the removal).
- bench/runner.py: repointed `from workers.ai.worker import _tts_espeak` → `from workers.ai.providers.tts import espeak` (in-function import; bench otherwise untouched; its pre-existing `jiwer` dep gap is unrelated).

### AI-воркер — text→text через локальную LLM (Ollama | llama.cpp)

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Добавить в AI-воркер конвертацию **text→text через локальную инструктивную LLM**
(поведение «как ChatGPT», но без внешних API). Провайдер абстрагирован: два бэкенда
на выбор через конфиг — внешний **Ollama (HTTP, default)** и встроенный **llama.cpp**.

Зависит от [[ai-worker-refactor-core]] (модульная структура, `convert.py`, `config.py`).

**Проблема:**
Воркер умеет STT/TTS/embedding, но не умеет text→text (суммаризация, переписывание,
перевод, чат-инференс). Внешние API убраны — нужен локальный LLM-путь.

**Рекомендация:**
1. `providers/llm.py` — интерфейс `LlmProvider` + две реализации:
   - **Ollama** (default): HTTP к внешнему Ollama (`OLLAMA_URL`, `OLLAMA_MODEL`).
     Модели меняются без пересборки образа.
   - **llama.cpp**: встроенный `llama-cpp-python`, веса GGUF из volume (`LLM_MODEL_PATH`).
     **Ленивый импорт** `llama_cpp` (только при `LLM_BACKEND=llamacpp`) — код-путь не зависит
     от наличия бинаря; бинарь ставится опционально (build-ARG `WITH_LLAMACPP`, см.
     [[ai-worker-docker-images]]). Если бэкенд `ollama` — llama.cpp не трогается.
   Выбор через `LLM_BACKEND=ollama|llamacpp` (default `ollama`).
2. `convert.py`: пара `(text, text)` → режим `llm`. Flag-agnostic, как остальные режимы.
3. Параметры инференса в `config.py`: `LLM_BACKEND`, `OLLAMA_URL`, `OLLAMA_MODEL`,
   `LLM_MODEL_PATH`, `LLM_MAX_TOKENS`, `LLM_TEMPERATURE`, системный промпт (опц.).
4. Веса для llama.cpp и кэш HF — через volume (см. [[ai-worker-docker-images]] про шаринг
   локальных HF/Ollama моделей).
5. Тесты: роутинг `(text,text)→llm`; LLM-провайдер с мок-бэкендом (без реальной модели);
   опц. e2e-тест с llama.cpp на маленькой GGUF, помеченный `@integration` (skip без модели).

**Влияние:**
Закрывает пункт «text→text через LLM» из требований рефактора; единая локальная
обработка без внешних зависимостей.

**Критерии приёмки:**
- `providers/llm.py` с двумя бэкендами (Ollama default + llama.cpp), выбор через `LLM_BACKEND`.
- `convert.py` маршрутизирует `(text,text)→llm`; покрыто тестом.
- Конфиг LLM в `config.py`; реальных внешних API-ключей нет.
- `pytest workers/tests` зелёный (LLM — мок; e2e llama.cpp опционально, skip без модели).

**Decisions:**
- Default-бэкенд — **Ollama** (внешний): проще старт, модели без пересборки. llama.cpp —
  альтернатива для «всё в одном контейнере».
- «Модели аналогичные ChatGPT» = локальные инструктивные модели (Llama/Qwen/Mistral),
  НЕ внешний ChatGPT API (внешние API убраны в [[ai-worker-refactor-core]]).

---

## Execution Log

**Done:**
- `workers/ai/providers/base.py` — added `LlmProvider` Protocol (`async generate(prompt) -> str`).
- `workers/ai/providers/llm.py` (new) — `OllamaProvider` (httpx POST `{OLLAMA_URL}/api/generate`,
  `stream=False`, `options.num_predict`/`temperature`, optional `system`; explicit timeout
  connect=10s/read=600s since Ollama generation far exceeds httpx's 5s default), `LlamaCppProvider`
  (lazy `from llama_cpp import Llama` inside `_generate_sync`, run via `asyncio.to_thread`,
  `create_chat_completion`), and `make_llm_provider(cfg)` factory. Both backends `.strip()` output
  identically so behaviour is backend-independent.
- `workers/ai/convert.py` — `Mode.LLM`; `LLM_INPUTS`/`LLM_OUTPUTS = {txt, md}`; routing appended
  after EMBEDDING, before the raise; dispatch reads src text, errors on empty, calls provider,
  writes result.
- `workers/ai/config.py` — fields `llm_backend` (default `ollama`), `ollama_url`
  (`http://localhost:11434`), `ollama_model` (`llama3.2`), `llm_model_path`, `llm_max_tokens`
  (1024), `llm_temperature` (0.7), `llm_system_prompt`; `_getenv_float` helper. `.validate()`
  (inside the existing `pull_enabled` gate) checks backend ∈ {ollama, llamacpp} and requires
  `LLM_MODEL_PATH` when llamacpp. No API keys anywhere.
- `workers/ai/utils.py` — added `md → text/markdown` to `OUTPUT_MIME` (md is now a valid output).
- `.env.worker-ai.example` — added LLM vars with safe placeholders (`LLM_MODEL_PATH`/system prompt empty).
- `workers/tests/test_ai_worker.py` — LLM routing (4 pairs), convert dispatch (txt→txt, md→md),
  flag-agnostic txt→txt, empty-input raise, mocked Ollama (payload+strip+timeout), factory selection,
  unknown-backend raise, optional `@integration` llama.cpp e2e (skip w/o binary+model).

**Chosen (src,tgt)→LLM pair set:** `{txt, md} × {txt, md}` = (txt,txt), (txt,md), (md,txt), (md,md).
Rationale: the text family is uniformly `{txt, md}` across the worker (`TTS_INPUTS`, embedding inputs);
none of the 4 pairs collide with earlier rules (LLM src ∉ audio, tgt ∉ {mp3,wav,ogg,json}). Derivation
stays flag-agnostic.

**Configure each backend:**
- Ollama (default): `LLM_BACKEND=ollama`, `OLLAMA_URL=http://<host>:11434`, `OLLAMA_MODEL=<model>`.
  Models swap without rebuilding the image.
- llama.cpp: `LLM_BACKEND=llamacpp`, `LLM_MODEL_PATH=/models/<weights>.gguf` (mount via volume).
  Requires `llama-cpp-python` in the image (WITH_LLAMACPP build-arg — see [[ai-worker-docker-images]]).
- Shared: `LLM_MAX_TOKENS`, `LLM_TEMPERATURE`, optional `LLM_SYSTEM_PROMPT`.

**Verification:**
- `pytest workers/tests/test_ai_worker.py -m "not e2e"` → 59 passed, 2 skipped (espeak + llama.cpp e2e).
- Lazy-import proof: `import workers.ai.{convert,config,providers.llm}` clean with llama_cpp absent;
  asserted `llama_cpp` not in `sys.modules`.
- No python lint/ruff target or config in the repo — nothing to run.

**Hand-back (out of zone — not wired yet):**
- PHP registry must route `(txt,txt)`/`(md,…)` text pairs to the `conv.ai` stream; worker only derives
  the mode — nothing routes text→text to it yet.
- `llama-cpp-python` must be installed in the AI image for the llamacpp path to actually run.

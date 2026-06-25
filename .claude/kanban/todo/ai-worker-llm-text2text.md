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

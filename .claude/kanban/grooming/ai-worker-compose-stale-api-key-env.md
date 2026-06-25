### AI-воркер — убрать устаревшие OPENAI/GEMINI/CLAUDE env из compose

**Критичность:** Low

**TAGS:**
- tech-debt
- devops

**Описание:**
После рефактора ядра ([[ai-worker-refactor-core]]) AI-воркер стал **local-only**
(STT=faster-whisper, TTS=espeak/pyttsx3, embedding=sentence-transformers, LLM=Ollama/llama.cpp;
без внешних API-ключей). Но в `docker-compose.yml` (сервис `worker-ai`, ~строки 355-357)
и в старом `docker-compose.worker-ai.yml` всё ещё проброшены `OPENAI_API_KEY`,
`GEMINI_API_KEY`, `CLAUDE_API_KEY`. Это противоречит local-only решению и вводит
в заблуждение (намекает на внешние провайдеры, которых код не читает).

**Рекомендация:**
Удалить три env-переменные из обоих compose-файлов. Свериться с `workers/ai/config.py`
(никаких *_API_KEY полей нет) и убедиться, что нигде в воркере они не читаются.
⚠ Не путать с отдельной карточкой [[add-open-ai]] (если решат добавить внешний провайдер —
это сознательное расширение, а не возврат этих env).

**Контекст:** находка из ревью [[ai-worker-docker-images]]; явно отложена решением карточки.

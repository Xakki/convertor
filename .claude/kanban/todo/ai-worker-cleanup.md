### AI-воркер — мелкая чистка (compose env + dead requirements + tts ffmpeg)

**Критичность:** Low

**TAGS:**
- tech-debt
- devops

**Описание:**
Объединённая карточка из трёх low-нитей по AI-воркеру (находки ревью
[[ai-worker-docker-images]] и [[ai-worker-refactor-core]]). Все три — механические,
без открытых вопросов.

**Acceptance criteria:**

1. **Убрать устаревшие *_API_KEY из compose.** После рефактора ядра воркер local-only
   (`workers/ai/config.py` явно: внешние ключи отсутствуют сознательно). Удалить из
   `docker-compose.yml` (сервис `worker-ai`, строки **355-357**) три env:
   `OPENAI_API_KEY`, `GEMINI_API_KEY`, `CLAUDE_API_KEY`.
   ⚠ Факт-корректировка карточки: в `docker-compose.worker-ai.yml` этих env **уже нет** —
   там править нечего. Не путать с [[add-open-ai]] (осознанное расширение, а не возврат env).

2. **Удалить мёртвый `docker/workers/requirements-ai.txt`.** После split-схемы
   (`requirements-ai-base.txt` + `requirements-ai-ml.txt`) старый плоский файл никем
   не используется (0 ссылок в repo) и содержит устаревшие deps (`boto3`, `aiohttp`,
   `redis`) — наследие до HTTP pull-API. Перед удалением — `grep -r requirements-ai.txt`,
   убедиться, что ссылок нет (живут только base/ml в ai-base/ai.cuda/ai.cpu Dockerfile).

3. **Проверять `ffmpeg.returncode` в espeak-пути TTS.** `workers/ai/providers/tts.py`
   espeak-путь (~строки 38-41) делает `await ffmpeg.wait()` без проверки returncode;
   синхронный `_pyttsx3_sync` (~строки 59-62) использует `check=True`. Привести espeak-путь
   к единому стилю: после `wait()` проверять returncode и бросать описательную ошибку
   со stderr ffmpeg.

**Files:**
- `docker-compose.yml` (355-357)
- `docker/workers/requirements-ai.txt` (удалить)
- `workers/ai/providers/tts.py` (38-41)

**Verify:** `make docker-check` зелёный; `grep -r requirements-ai.txt` пусто; pytest воркера зелёный.

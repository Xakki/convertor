### AI-воркер — продублировать HEALTHCHECK в workers

**Критичность:** Low

**TAGS:**
- devops

**Описание:**
Находка ревью [[ai-worker-docker-images]]. Уточнение по грумингу: import-based
healthcheck (`python -c "import faster_whisper"`) **уже присутствует в обоих compose-файлах**
(`docker-compose.yml:358-363`, `docker-compose.worker-ai.yml:105-110`), но в самих
Dockerfile'ах (`ai.cuda`, `ai.cpu`) HEALTHCHECK нет — вне compose образ healthcheck'а
не имеет.

**Decisions (groomed):**
- Критерий — оставить **import-based** (`import faster_whisper`), polling-self-check НЕ
  делаем (это отдельная зависимость от [[worker-pull-api-live-status-hash]]).
- Место — **продублировать** HEALTHCHECK в `ai.cuda`/`ai.cpu` Dockerfile (чтобы образ был
  самодостаточен и вне compose). Compose-блоки оставить как есть.
- `ai-base` (FROM scratch, воркер не запускает) — healthcheck НЕ нужен.
- Обязательно `python3`, не `python` (старый сломанный HEALTHCHECK падал на `python`).

**Acceptance criteria:**
- В `docker/workers/ai.cuda.Dockerfile` и `docker/workers/ai.cpu.Dockerfile` добавлен
  `HEALTHCHECK ... CMD python3 -c "import faster_whisper" || exit 1` (интервал/таймаут
  согласовать с compose-блоками).
- `ai-base.Dockerfile` не трогать.
- `make docker-check` зелёный.

**Files:**
- `docker/workers/ai.cuda.Dockerfile`
- `docker/workers/ai.cpu.Dockerfile`

**Контекст:** находка из ревью [[ai-worker-docker-images]]; вне AC той карточки.

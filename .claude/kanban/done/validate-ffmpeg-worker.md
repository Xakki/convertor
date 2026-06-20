### Ffmpeg воркер — добавить 3gp + integration 3gp→mp4 + валидация матрицы

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Ffmpeg-воркер (`workers/ffmpeg/worker.py`) уже Streams-consumer (через `workers/common/stream_consumer.py`: XREADGROUP, группа `convertor`, стрим `conv.<routing_key>`, вход из S3 `{prefix}-inputs`, результат в `{prefix}-results`) и содержит реальную логику конвертации audio/video. Перевод на Streams + S3 уже сделан в коде. Осталось: объявить `3gp`, покрыть integration-тестом 3gp→mp4 и провалидировать audio/video-матрицу.

**Проблема:**
- В `SUPPORTED` отсутствует `"3gp"` — его нет НИ на входе, НИ на выходе, хотя маппинг 3gp→mp4 уже идёт через `libx264` — формат фактически конвертируется, но не объявлен поддерживаемым.
- Матрица audio/video из матрицы форматов `ROADMAP.md` (справочные данные) не провалидирована end-to-end на реальном ffmpeg.

**Влияние:**
Без объявления 3gp и валидации — часть заявленных конвертаций молча не работает.

**Контекст (уже сделано в коде):**
- Streams-consumer + S3 I/O — уже подключены (`stream_consumer.py`); старый Redis-LISTS транспорт и `base_worker.py`/`keydb_client.py` удалены. Это done-контекст, не задача.
- `test_ffmpeg_worker.py` уже существует (run_ffmpeg замокан) — нужен ещё integration-тест на реальном ffmpeg.

**Решение:**
- Добавить `"3gp"` в `SUPPORTED` в `workers/ffmpeg/worker.py` (вход 3gp → mp4 уже маппится на `libx264`).
- Провалидировать audio/video-конвертации против матрицы форматов `ROADMAP.md` (категории «Видео» / «Аудио»).
- Покрыть **integration-тестом 3gp→mp4** (реальный ffmpeg, pytest-маркер `integration`). Фикстура готова: `workers/tests/example_files/video.3gp` (41KB, h263+aac, 3s).

**Зависимости:**
- Runtime-валидация через S3 блокируется [[finish-worker-compose-wiring]]: `worker-ffmpeg` сейчас на сети `backend` (`internal:true`, без NAT) и без S3_*-env — в коде мигрирован, но до S3 в рантайме не дотягивается.

**Критерии приёмки:**
- `"3gp"` добавлен в `SUPPORTED`; конвертация 3gp→mp4 работает на реальном ffmpeg.
- Integration-тест 3gp→mp4 (маркер `integration`) зелёный на реальном ffmpeg.
- audio/video-матрица провалидирована против `ROADMAP.md`.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Миграция Redis-LISTS → KeyDB Streams + S3 — **уже сделана в коде** (снято из scope при ре-груминге 2026-06-20).
- Unit-тесты ffmpeg покрываются отдельно в [[worker-conversion-tests]]; здесь — 3gp + integration 3gp→mp4 + валидация матрицы.

Siblings: [[validate-image-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]

**Итог (2026-06-20):**
- `3gp` объявлен **только на вход** (`SUPPORTED["3gp"] = _VIDEO_FORMATS | {mp3,wav,ogg,flac}`)
  — по `ROADMAP.md` L154 3gp есть только в input-колонке видео, в output его нет; поэтому в
  `_VIDEO_FORMATS`/`_MIME`/`CODEC_MAP` не добавлялся (3gp никогда не цель), `test_matrix_mime_coverage`
  остаётся зелёным.
- Integration-тест 3gp→mp4 на **реальном ffmpeg** (`workers/tests/test_ffmpeg_integration.py`,
  маркер `integration`) — гоняет настоящий `convert()` на фикстуре, проверяет mp4/mime/ftyp +
  полную декодируемость через `ffmpeg -f null`. Маркер зарегистрирован в новом `pytest.ini`.
- Валидация матрицы vs ROADMAP: ничего заявленного не сломано; воркер — строгий **супермножество**
  в 2 местах (audio→`wma` объявлен, ROADMAP output до `opus`; video→audio разрешает источники
  `webm/flv/wmv`+3gp, ROADMAP — только `mp4/avi/mkv/mov`). Не правил (вне scope) → follow-up
  [[reconcile-ffmpeg-matrix-roadmap]].
- `pytest workers/tests` 64 passed/1 skipped (skip — пред-существующий `test_ai_worker`),
  `pytest -m integration -v` 1 passed, `make docker-check` чисто. Ревью: APPROVE-WITH-NITS.
- Caveat: системного `ffmpeg` нет (sudo недоступен) — тест гонялся на userspace static-сборке
  (johnvansickle 7.0.2 via imageio-ffmpeg в `~/.local/bin`); без неё integration-тест skip'ается.
  В CI/prod ffmpeg должен поставлять Dockerfile воркера (см. [[finish-worker-compose-wiring]]).
  Runtime-валидация через S3 осталась заблокирована той же зависимостью.

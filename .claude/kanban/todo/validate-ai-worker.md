### AI воркер — гибридный backend (внешний default + local fallback) + миграция на Streams + STT/TTS

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
AI-воркер (`workers/ai/worker.py`) уже содержит реальную логику STT/TTS (не заглушка), но всё ещё читает задачи из Redis-LISTS, а не из KeyDB Streams. Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`), общий том `/shared-files` удалён (storage-input-to-s3, 2026-06-20). Backend AI делаем **гибридным**: внешние провайдеры — по умолчанию, локальные движки — fallback.

**Проблема:**
- Воркер на Redis-LISTS — не получает задачи в новой очередной модели (PHP пишет в KeyDB Streams). Контракт рассинхронизирован.
- **Egress-блокер local-fallback (whisper):** `ai.Dockerfile` НЕ pre-bake'ит модель Whisper — она качается из HuggingFace при первом запуске, но `worker-ai` сидит на сети `backend` с `internal: true` (без egress) → загрузка молча падает (healthcheck делает только `import faster_whisper`). Без фикса локальный fallback нерабочий.
- AI-конвертации не учитываются в квотах (QuotaService) — лимит «1 AI-конвертация в день» из `docs/plan.md` не применяется.
- Матрицы STT/TTS не провалидированы end-to-end.

**Влияние:**
Без миграции воркер не работает в проде. Без фикса egress отказоустойчивость (fallback) отсутствует. Без квот — AI-конвертации не ограничиваются и бьют по биллингу/ресурсам.

**Решение:**
- **Гибридный backend:** внешние провайдеры по умолчанию (OpenAI/Gemini/Claude + g4f через `aip.xakki.ru`, см. [[add-open-ai]]); локальные whisper/espeak — fallback.
- Починить egress модели Whisper для локального fallback: либо **pre-bake модель в `ai.Dockerfile`**, либо дать `worker-ai` контролируемый egress на сети `backend` (`internal:true`).
- Перевести ai-воркер с Redis-LISTS на KeyDB Streams (`workers/common/stream_consumer.py`), по образцу image-воркера.
- Перевести I/O на S3: вход из `-inputs`, результат в `-results` (через `workers/common/s3.py`).
- Учитывать AI-конвертации в квотах (QuotaService).
- Провалидировать STT (mp3/wav/ogg/m4a/opus → txt/srt/vtt) и TTS (txt/md → mp3/wav/ogg) против матрицы `docs/plan.md`.

**Критерии приёмки:**
- Backend AI гибридный: внешний провайдер — default, local whisper/espeak — fallback; маршрут выбора зафиксирован.
- Egress модели Whisper починен (pre-bake в образ ИЛИ контролируемый egress); локальный fallback реально работает.
- Воркер потребляет задачи из KeyDB Streams (stream-consumer подключён), Redis-LISTS больше не используется.
- I/O идёт через S3: вход из `-inputs`, результат в `-results`; `/shared-files` не используется.
- AI-конвертации учитываются в QuotaService (лимит из `docs/plan.md`).
- STT и TTS провалидированы против матрицы `docs/plan.md`.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Backend AI — гибрид: внешние провайдеры (incl. g4f через [[add-open-ai]]) по умолчанию, local whisper/espeak — fallback (решение 2026-06-20).
- Миграция Redis-LISTS → KeyDB Streams делается внутри этой же карточки (не отдельной prereq-картой).
- Unit-тесты ai-воркера (моки SDK/движков) покрываются отдельно в [[worker-conversion-tests]]; здесь — backend/Streams/S3/квоты + валидация STT/TTS.

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]]

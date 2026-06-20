### AI воркер — runtime-wiring + GPU + egress модели + AI-тесты + STT/TTS

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
AI-воркер (`workers/ai/worker.py`) уже Streams-consumer (через `workers/common/stream_consumer.py`: XREADGROUP, группа `convertor`, стрим `conv.<routing_key>`, вход из S3 `{prefix}-inputs`, результат в `{prefix}-results`) и содержит реальную логику STT/TTS. **Гибридный backend уже реализован** (Стадия 2): STT — faster-whisper local / OpenAI / Gemini / Claude с fallback-to-local; TTS — espeak-ng / pyttsx3 / OpenAI. Осталось: runtime-wiring (S3/egress), GPU, egress модели whisper, AI-тесты и валидация STT/TTS.

**Проблема:**
- **S3/egress runtime-wiring отсутствует:** в коде воркер мигрирован на Streams+S3, но `worker-ai` сидит на сети `backend` (`internal:true`, без NAT) и без S3_*-env → до S3 в рантайме не дотягивается.
- **GPU не задействован:** faster-whisper жёстко закреплён `device="cpu", compute_type="int8"` — нужна GPU-конфигурация.
- **Egress модели whisper:** при первом запуске модель качается из HuggingFace, но сеть `backend` (`internal:true`) блокирует загрузку → нужно либо **pre-bake модели в образ**, либо дать контролируемый egress.
- **Нулевое покрытие AI:** `test_ai_worker.py` сейчас `pytest.skip(allow_module_level=True)` — тестов AI нет.
- Матрицы STT/TTS не провалидированы end-to-end.

**Влияние:**
Без runtime-wiring воркер не дотягивается до S3 в проде. Без egress/pre-bake local-fallback whisper нерабочий. Без тестов AI — регрессии не ловятся.

**Контекст (уже сделано в коде):**
- Streams-consumer + S3 I/O — уже подключены (`stream_consumer.py`); старый Redis-LISTS транспорт и `base_worker.py`/`keydb_client.py` удалены. Это done-контекст, не задача.
- Гибридный backend (внешние провайдеры default + local fallback) — **уже реализован** в коде.

**Решение:**
- Починить egress модели Whisper для локального fallback: либо **pre-bake модель в `ai.Dockerfile`**, либо дать `worker-ai` контролируемый egress.
- Включить **GPU** для faster-whisper (сейчас pinned `device="cpu", compute_type="int8"`) — конфиг устройства/типа вычислений.
- **Снять `pytest.skip` и написать AI-тесты** (`test_ai_worker.py`): моки SDK/движков, выбор провайдера и fallback-to-local.
- Провалидировать STT (mp3/wav/ogg/m4a/opus → txt/srt/vtt) и TTS (txt/md → mp3/wav/ogg) против матрицы форматов `ROADMAP.md` (справочные данные).

**Зависимости:**
- S3/egress runtime-wiring блокируется [[finish-worker-compose-wiring]]: `worker-ai` на сети `backend` (`internal:true`, без NAT) и без S3_*-env — в коде мигрирован, но до S3 в рантайме не дотягивается.

**Критерии приёмки:**
- S3/egress runtime-wiring сделан (через [[finish-worker-compose-wiring]]); воркер дотягивается до S3.
- GPU задействован для faster-whisper (device/compute_type конфигурируемы, не жёсткий cpu/int8).
- Egress модели Whisper починен (pre-bake в образ ИЛИ контролируемый egress); локальный fallback реально работает.
- `test_ai_worker.py` рабочий (skip снят), AI-логика покрыта тестами.
- STT и TTS провалидированы против матрицы форматов `ROADMAP.md`.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Миграция Redis-LISTS → KeyDB Streams + S3 и **гибридный backend (Стадия 2)** — **уже сделаны в коде** (сняты из scope при ре-груминге 2026-06-20).
- Backend AI — гибрид: внешние провайдеры (incl. g4f через [[add-open-ai]]) по умолчанию, local whisper/espeak — fallback (решение 2026-06-20).
- Unit-тесты ai-воркера (моки SDK/движков) изначально планировались в [[worker-conversion-tests]]; здесь — снятие skip и написание AI-тестов + runtime-wiring/GPU/egress + валидация STT/TTS.

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]]

### AI воркер — remote pull-API transport + GPU + flag-agnostic + AI-тесты + STT/TTS

> ⚠ **Часть переосмыслена** рефактором 2026-06-25: гибридные внешние провайдеры
> (OpenAI/Gemini/Claude) убираются — воркер только локальный инференс. Pull-API/транспорт
> и flag-agnostic остаются в силе. См. [[ai-worker-refactor-core]] и соседние карточки эпика.

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
AI-воркер переводится с on-server KeyDB Streams-consumer на **remote-воркер**, который
крутится вне сервера (домашний WSL + GPU) и тянет задания по **универсальному HTTP
pull-API**. KeyDB наружу не публикуется, поэтому прямого доступа к Streams/S3 у воркера
нет — всё через API. Реальная логика STT/TTS уже есть (гибрид: faster-whisper local /
OpenAI / Gemini / Claude + fallback; TTS espeak-ng / pyttsx3 / OpenAI).

**Архитектура (решение пользователя 2026-06-23):**
- AI-воркер **не запускается на сервере**. Образ публикуется в **Harbor**, пользователь
  пуллит и запускает его дома (WSL + GPU).
- Воркер **short-poll'ит API каждые ~10 сек**, получает задание, **файлы (вход и результат)
  идут ЧЕРЕЗ API** (воркер S3 не трогает вообще).
- API — **шлюз над KeyDB stream `conv.ai`**: claim из consumer-group (lease, запись остаётся
  pending до ack), ack по `result`/`fail`, reclaim зависших по таймауту.
- Auth: статичный **bearer-токен** в конфиге воркера (пока хардкод в конфигах; позже —
  нормальная выдача). API задуман **универсальным** для всех типов воркеров.

**Контракт универсального worker pull-API** (`Authorization: Bearer ${WORKER_API_TOKEN}`):
- `POST /api/v1/worker/claim` `{type:"ai", consumer:"<id>"}` →
  - `204 No Content` — заданий нет;
  - `200 {jobId, conversionId, sourceFormat, targetFormat}` — `jobId` = ID stream-сообщения
    (нужен для ack). Внутри: `XREADGROUP` group `convertor` consumer `<id>` из `conv.<type>`.
- `GET /api/v1/worker/jobs/{jobId}/input` → `200` бинарь входного файла (API читает из S3 inputs).
- `POST /api/v1/worker/jobs/{jobId}/result` (multipart: файл + поля) → API кладёт результат в
  S3 results, отмечает conversion completed, `XACK` записи стрима.
- `POST /api/v1/worker/jobs/{jobId}/fail` `{error}` → API отмечает failed, **возврат квоты**,
  ack/reclaim записи.
- Зависшие задания: `XAUTOCLAIM` возвращает pending старше N мин в доступные.

**Задачи (scope):**
1. **PHP: универсальный worker pull-API** — эндпоинты claim/input/result/fail, bearer-auth
   (токен из конфига), шлюз над Streams (XREADGROUP/XACK/XAUTOCLAIM), файлы через API
   (input из S3 → стрим воркеру; result от воркера → S3 results), статус conversion + возврат
   квоты при fail. OpenAPI-аннотации — по образцу [[api-openapi-swagger]] (или отметить как
   follow-up).
2. **Python: переписать AI-воркер в HTTP poll-client** — убрать `StreamConsumerBase`/прямой
   S3; цикл poll(10s) → claim → download input(API) → convert → upload result(API)/fail(API).
   Конфиг: `API_BASE_URL`, `WORKER_API_TOKEN`, `WORKER_TYPE=ai`, `WHISPER_*`, GPU.
3. **flag-agnostic** — убрать чтение `job["subType"]` (`worker.py:367`), режим STT/TTS выводить
   ТОЛЬКО из пары форматов (audio→{txt,srt,vtt}=STT; {txt,md}→audio=TTS), бросать ошибку на
   невыводимой паре. Бэк-`subType` cleanup — отдельная карточка [[backend-subtype-cleanup]].
4. **GPU** — `WHISPER_DEVICE` / `WHISPER_COMPUTE_TYPE` через env (сейчас pinned `cpu`/`int8`).
   **Дефолт оставить `cpu`/`int8`** (не регрессить); дома пользователь ставит `cuda`.
5. **Compose / Harbor** — убрать `worker-ai` из server-compose (или оставить `profiles:[ai]`
   только для локалки); дать standalone-конфиг/Makefile-таргет для домашнего запуска + пуш
   образа в Harbor. Воркеру больше не нужны сети `backend`/`keydb` и S3-env.
6. **Тесты** — снять `pytest.skip` в `test_ai_worker.py`; PHP functional-тесты worker-API
   (claim/input/result/fail, auth, stream-шлюз); Python тесты poll-клиента (мок API),
   flag-agnostic вывод форматов, выбор провайдера + fallback (моки SDK/движков). **TTS
   валидируем реально e2e** (espeak/pyttsx3 локальны); **STT — моки + роутинг** (реальный
   whisper/GPU в этом окружении не проверить).

**Критерии приёмки:**
- Универсальный worker pull-API работает: claim из `conv.ai`, файлы через API, ack/fail,
  возврат квоты при fail; bearer-auth.
- AI-воркер — HTTP poll-client (10s), без прямого KeyDB/S3; flag-agnostic (не читает `subType`,
  STT/TTS из форматов — покрыто тестом).
- GPU: `device`/`compute_type` конфигурируемы (дефолт `cpu`/`int8`).
- TTS провалидирован реально e2e; STT покрыт моками + роутингом форматов (матрица `ROADMAP.md`).
- Образ AI-воркера собирается и предназначен для Harbor; server-compose не запускает AI на сервере.
- `pytest workers/tests` зелёный; PHP-тесты зелёные; `composer test:phpstan` зелёный; `make docker-check` проходит.

**Известные gaps (не блокеры, проверяет пользователь дома):**
- Реальный STT/GPU-прогон — только на домашнем WSL+GPU (тут не верифицируется).
- `make docker-check` = `config -q`, **сборку образа не проверяет** — реальная сборка/пуш в
  Harbor верифицируется отдельно при запуске дома.
- Pre-bake модели Whisper в образ **деприоритизирован**: домашний хост имеет egress + кэш-volume,
  модель тянется в рантайме (старая забота про `internal:true`-сеть снята — воркер вне сервера).

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Гибридный backend (внешние провайдеры default + local fallback) — **уже в коде**.
- **Смена транспорта (2026-06-23, решение пользователя):** AI-воркер не на сервере, тянет по
  универсальному HTTP pull-API (poll 10s), файлы через API, источник заданий — KeyDB stream
  `conv.ai` (API-шлюз). KeyDB наружу не публикуется; образы — в Harbor. Зафиксировано в `CLAUDE.md`.
- Это пересекается с [[distributed-workers]] (там транспорт — Redis Streams TLS SNI): pull-API —
  альтернативный транспорт для off-server воркеров; синхронизировать при груминге distributed-workers.
- **flag-agnostic** (решение 2026-06-21): воркеры не читают флаги; режим — из форматов; stream
  выбирает бэк. Бэк-cleanup `subType` → [[backend-subtype-cleanup]].

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[distributed-workers]]

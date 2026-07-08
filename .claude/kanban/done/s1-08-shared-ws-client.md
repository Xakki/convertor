### S1-08 — Общий WS-клиент воркера (`workers/common/ws_client.py`)

**Критичность:** High

**TAGS:**
- transport
- worker
- refactor

**Описание:**
Переиспользуемая база WS-клиента для ВСЕХ воркеров — точка-разветвление, гейтит миграцию воркеров (§3/§4/§6.6 spec). Заменяет прежний транспорт (poll-цикл off-server / прямое чтение Stream+S3 on-server) постоянным WS-соединением. Меняется только *транспорт вокруг* обработки задачи; логика одной задачи переиспользуется через чёткий seam.

Клиент реализует полный жизненный цикл: connect + auth (bearer `WORKER_API_TOKEN` в WS upgrade) → `ready{workerId, workerType, slots, version, cpu, mem, load}` → приём `job` → скачивание входа (`GET /jobs/{id}/input` через Symfony API, НЕ прямой S3) → вызов обработки → возврат completion (inline≤256KB по WS / large → `POST /jobs/{id}/result` + `{type:"result", resultKey}` / `{type:"fail", error, permanent?}`) → эмиссия `progress` ~1/сек пока задача в работе → `ping` + детект N пропущенных `pong` → reconnect с экспоненциальным backoff под тем же `workerId`.

**Seam:** конкретный воркер поставляет `handle_job(job) -> ResultSignal` (или эквивалент), где `ResultSignal` несёт inline-байты / resultKey / fail(+permanent). База не знает про форматы — воркер flag-agnostic, использует только `sourceFormat`/`targetFormat`. `workerId`/`workerType`/`version`/пороги — из конфига (по паттерну `load_config`, `WORKER_ID`, `GATEWAY_WS_URL`, `WS_RESULT_INLINE_MAX`, `WS_PING_INTERVAL_S`, `WS_PROGRESS_INTERVAL_S`, `WS_LIVENESS_MISSED_PINGS`, `WS_RECONNECT_BACKOFF_*`).

Клиент **не держит S3-креды** и **не подключается к KeyDB**.

**Файлы:**
- Создать: `workers/common/ws_client.py` (connect/auth/ready/job/completion/ping/pong/progress/reconnect+backoff; seam `handle_job`/`ResultSignal`).
- Изменить: `workers/common/__init__.py` (экспорт).
- Изменить: `workers/tests/` — тесты клиента против фейкового gateway.

**Критерии приёмки:**
- Клиент: connect → auth → `ready` (с `version`/`cpu`/`mem`/`load`) → приём `job` → `handle_job` → completion по правильной ветке (inline / large-resultKey / fail).
- Вход берётся ТОЛЬКО через `GET /jobs/{id}/input`; в клиенте НЕТ импорта/использования S3 и KeyDB (grep-ассерт).
- inline при ≤`WS_RESULT_INLINE_MAX`, иначе large-ветка (`POST /jobs/{id}/result` + `resultKey`).
- `progress` эмитится ~1/сек ТОЛЬКО пока задача в работе.
- `ping` периодически; N пропущенных `pong` → reconnect тем же `workerId` + backoff.
- `workerId` стабилен, без PID.
- `pytest workers/tests` (ws_client) зелёный.

**Зависит от:** `[[s1-03-ws-server-dispatch]]`, `[[s1-04-result-relay-ack]]`, `[[s1-05-ping-liveness]]`, `[[s1-06-reclaim-poison-dlq]]`, `[[s1-07-progress-conv-status]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Итог реализации:** `workers/common/ws_client.py` (seam `handle_job(job, progress) -> ResultSignal`,
`WsClientConfig.from_env`, `ProgressReporter`), ленивый экспорт из `workers/common/__init__.py`,
`websockets>=13.1` в requirements. Транспорт зеркалит `ws_server.py`; вход только через
`GET /jobs/{id}/input`, inline-vs-large по сырому размеру, reconnect тем же `workerId` + backoff,
liveness по N пропущенных `pong`. Инвариант «нет S3/KeyDB» — grep-тест.

**Ревью (3 finder-угла + синтез):** исправлено — (1) reconnect-шторм при 1008-reject
(`_ready_ok` теперь по первому входящему фрейму + `validate()` до цикла), (2) чёрная дыра
задачи при сбое `mkdir` (setup внутри `try`), (3) `stop()` рвёт idle-соединение,
(4) missing output → permanent fail, (5) done-callback на job-task, (6) правка докстринга,
(7) env-плейсхолдеры `WORKER_ID`/`GATEWAY_WS_URL`/`WS_PROGRESS_INTERVAL_S`/`WS_SLOTS`/`WORK_DIR`.
Отложено в grooming `[[ws-inline-max-shared-threshold]]`: расхождение порога inline воркер↔gateway,
seq-id ping/pong, TOCTOU stat/read. Тесты: **79 passed** (`make test-gateway`).
Коммиты: `0afc43e`, `7cd7102`, `14d2646`.

**Status:** ready

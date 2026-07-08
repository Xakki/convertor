### S1-10 — Рефактор StreamConsumerBase: унификация persist + ретайр conv.result

**Критичность:** High

**TAGS:**
- worker
- transport
- refactor

**Описание:**
Подготовить on-server-воркеров к WS: разделить `workers/common/stream_consumer.py` `StreamConsumerBase` на **переиспользуемую логику одной задачи** (`process_job` / `_do_convert`, transport-agnostic) и **транспортный слой** (который уходит в общий WS-клиент). On-server-воркеры больше не читают `conv.<type>` напрямую, не ходят в S3 напрямую и не делают self-`XACK`.

**Полная унификация (Option A, §3/§5):**
- Убрать worker-write в отдельный стрим `conv.result` — on-server persist идёт тем же унифицированным путём: малый → inline по WS → gateway relay; большой → `POST /jobs/{id}/result` (через общий клиент). Оба сходятся в Symfony `ConversionResultPersister`.
- Убрать worker-writable `conv:status` (его теперь пишет gateway, `[[s1-07-progress-conv-status]]`).
- **Ретайр `QueueResultConsumerCommand`** (`app-symfony/src/Command/QueueResultConsumerCommand.php`) — consumer стрима `conv.result` больше не нужен.

`process_job` остаётся transport-agnostic: принимает разобранную задачу, вызывает конкретный `convert()` воркера, возвращает `ResultSignal` (inline / resultKey / fail+permanent) — совместимый с seam `handle_job` из `[[s1-08-shared-ws-client]]`. `PermanentError` → `fail{permanent:true}` (DLQ-решение теперь у gateway, `[[s1-06-reclaim-poison-dlq]]`).

**Файлы:**
- Изменить: `workers/common/stream_consumer.py` (выделить transport-agnostic `process_job`/`_do_convert`; убрать прямое чтение `conv.<type>`, self-XACK, write `conv.result`/`conv:status`; сохранить валидацию матрицы + `PermanentError`).
- Удалить/ретайр: `app-symfony/src/Command/QueueResultConsumerCommand.php` (+ его тест `app-symfony/tests/Unit/Command/QueueResultConsumerCommandTest.php`). Удаляется вместе с DLQ-константой `conv.result.dead` (`STREAM_DLQ`) — отдельного DLQ-стрима результата не остаётся (закрывает [[reconcile-conv-result-dead-legacy]]).
- Удалить: программу `[program:result-consumer]` в `docker/php/supervisor.app.ini` (~строки 11-28, `autostart=true` → иначе старт cron-контейнера упадёт на удалённой команде).
- Изменить: `app-symfony/config/packages/messenger.yaml` / DI при необходимости (убрать consumer `conv.result`; там комментарий про raw-stream `conv.result`).
- Изменить: доки `docs/queue-contract.md`, `docs/queue-streams.md` — убрать `conv.result` и его consumer.
- Изменить: `workers/tests/` — тесты `process_job` transport-agnostic.

**Критерии приёмки:**
- `process_job(job)` вызывает `convert()` и возвращает `ResultSignal` без обращений к KeyDB/S3/XACK (grep-ассерт).
- Grep: НЕТ worker-write в `conv.result`; НЕТ worker-writable `conv:status`; НЕТ self-`XACK` в `stream_consumer.py`.
- `QueueResultConsumerCommand` удалён; ссылок на него/`conv.result` consumer не осталось. Grep: нет `conv.result`, `conv.result.dead`, `result-consumer` (supervisor), `app:queue:result-consumer` во всём репо (код + supervisor + доки).
- `PermanentError` пробрасывается как `fail{permanent:true}` (не self-DLQ).
- Валидация форматов (матрица) сохранена.
- `pytest workers/tests` зелёный; `make phpstan` / `make cs` зелёные.

**Зависит от:** `[[s1-08-shared-ws-client]]`, `[[s1-04-result-relay-ack]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready

**Итог (2026-07-07):** реализовано в `27932bf` (поверх `c00b93d`) на `task/s1-ws-transport`. `StreamConsumerBase` разделён: transport-agnostic `process_job(job, progress) → ResultSignal` (нет KeyDB/S3/XACK), on-server воркеры (data/ffmpeg/image/libreoffice) → WS-клиенты через общий `WsClient`. Ретайрнуты `QueueResultConsumerCommand` (+тест), supervisor `[program:result-consumer]`, стрим `conv.result`/`conv.result.dead`, worker-write `conv:status`. `PermanentError` → `ValueError→fail{permanent:true}`. Гейты зелёные: phpstan OK, cs OK, pytest 95+14+31+29. Ревью — APPROVE-WITH-NITS (ниты N1/N2/N3+SF-2 применены); SF-1 (docker-compose WS-wiring + ffmpeg two-routing-key) вынесен в `[[ws-onserver-compose-wiring]]`.

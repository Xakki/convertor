### S1-03 — WS-сервер + кредитный dispatch

**Критичность:** High

**TAGS:**
- transport
- gateway
- protocol

**Описание:**
Поднять WebSocket-сервер gateway и кредитный цикл диспетчеризации (§4 spec). Протокол — pull со стороны воркера на кредитах: воркер объявляет ёмкость (`ready`), gateway читает одну запись Stream на каждый свободный кредит и проталкивает её фреймом `job`. Готовые воркеры **вытягивают** работу → естественная балансировка.

**Handshake:** при подключении — аутентификация (граница a, §7: bearer `WORKER_API_TOKEN` в WS upgrade; невалидный/отсутствующий → close `1008` до обработки `ready`). Фрейм `ready{workerId, workerType, slots, version, cpu, mem, load}`. `workerType` из `ALLOWED_TYPES` (`ai|document|image|audio|video|data`) → определяет, какой `conv.<type>` читать (одно соединение = один тип = один stream); неизвестный тип → reject/close.

**Стабильный consumer (§6.1):** имя consumer'а KeyDB = handshake `workerId` дословно, **без PID**. Переподключающийся воркер переиспользует то же имя. При (пере)подключении gateway сначала возобновляет собственный PEL этого `workerId`: `XREADGROUP GROUP convertor <workerId> ... STREAMS conv.<type> 0` (id `0` = уже-pending записи этого consumer'а) → переотправляет их, затем читает новые с `>`.

**Кредитный цикл (на соединение):** по `ready` держать `slots` кредитов (в S1 = 1); на каждый свободный кредит — `XREADGROUP ... COUNT 1 BLOCK <ms> ... >`; при записи → разобрать → записать мету `worker:job:{jobId}` → протолкнуть `job{jobId, conversionId, sourceFormat, targetFormat, inputKey, inputBucket}` (+ `model`/`subType` МОГУТ ехать для format-логики; воркеры flag-agnostic) → пометить кредит busy (запись pending, не acked). Освобождение кредита и XACK — в `[[s1-04-result-relay-ack]]`.

**⚠ Порядок из s1-02-ревью (nit #1):** `keydb.read_new()` из s1-02 — **блокирующий** (`BLOCK <ms>`), в отличие от неблокирующего PHP `readNew`. Значит на каждом свободном кредите dispatch ОБЯЗАН звать `reclaim_stale()` (неблокирующий `XAUTOCLAIM`) **ПЕРЕД** `read_new()`, иначе блокирующее чтение новых записей будет голодать stale-reclaim и pending-записи умершего consumer'а не переедут. Порядок: `reclaim_stale` → если None → `read_new`. (nit #3 — вызов `write_job_meta` на call-site диспетча — уже покрыт выше и в AC.)

Поля `cpu`/`mem`/`load`/`version` в S1 только принимаются и логируются — НЕ потребляются (потребление = S3).

**Файлы:**
- Создать: `workers/gateway/ws_server.py` (WS-сервер, handshake, auth-граница, кредитный цикл, per-connection state).
- Изменить: `workers/gateway/__main__.py` (запуск WS-сервера + KeyDB-reader вместе).
- Изменить: `workers/gateway/config.py` (`WS_BLOCK_MS`, порт, `WORKER_API_TOKEN`).
- Изменить: `workers/tests/` — unit gateway (mock KeyDB + фейковый WS-воркер).

**Критерии приёмки:**
- Auth: отсутствующий/невалидный bearer → close `1008` до обработки любого `ready`.
- `ready` с `workerType:"image"` → gateway читает ТОЛЬКО `conv.image`; неизвестный тип → reject/close.
- Кредит → `XREADGROUP COUNT 1` → фрейм `job` протолкнут воркеру; запись остаётся pending (не acked).
- Consumer = `workerId` дословно (без PID); при reconnect того же `workerId` — `XREADGROUP ... 0` возобновляет его PEL и переотправляет pending (§6.6 путь «a»).
- Мета `worker:job:{jobId}` записана при выдаче `job`.
- `make test-gateway` зелёный.

**Зависит от:** `[[s1-02-gateway-keydb-reader]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready (ревью APPROVE WITH NITS, все 10 пунктов прошли; nit#1 constant-time bearer-compare `hmac.compare_digest` закрыт `7ae9e41`; `make test-gateway` 11 passed на реальном KeyDB + `docker-check` зелёные; nits #2/#3 + carry-forwards перенесены в карту s1-04). Ждёт финального ready→done пользователя.

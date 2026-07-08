### S1-02 — WS-Gateway: скелет сервиса + KeyDB-reader

**Критичность:** High

**TAGS:**
- transport
- gateway
- worker

**Описание:**
Создать скелет нового асинхронного Python-сервиса **WS-Gateway** и слой доступа к KeyDB. Gateway становится **единственным** читателем всех job-стримов `conv.ai/document/image/audio/video/data` и владельцем `XREADGROUP`/`XACK`/`XAUTOCLAIM` (группа `convertor`, KeyDB db2). На этом шаге — фундамент: конфиг-прокладка, KeyDB-клиент, чтение записи через единый декодер (`workers/common/envelope.py` из `[[s1-01-clean-wire-contract]]`), запись/чтение/удаление меты задачи `worker:job:{jobId}` (TTL 24 h, идентичный контракт полей, что раньше писал `WorkerStreamGateway::claim()`), Dockerfile и таргет тестов на реальном KeyDB. WS-сервер и dispatch — в `[[s1-03-ws-server-dispatch]]`.

Конфиг следует существующему паттерну: все `os.getenv` централизованы в `load_config()` (по образцу `workers/ai/config.py`), «пусто в трекаемом / реальное в `.env.local`».

**Файлы:**
- Создать: `workers/gateway/__init__.py`, `workers/gateway/__main__.py` (entrypoint), `workers/gateway/config.py` (`load_config()`), `workers/gateway/keydb.py` (XREADGROUP/XACK/XAUTOCLAIM, db2, `worker:job:{jobId}` read/write/DEL).
- Создать: `docker/workers/gateway.Dockerfile`.
- Изменить: `workers/Makefile` (или корневой `Makefile`) — таргет `make test-gateway`.
- Создать: `workers/tests/` — тесты gateway KeyDB-слоя.

**Критерии приёмки:**
- `keydb.py`: `XREADGROUP GROUP convertor <consumer> COUNT 1 BLOCK <ms> STREAMS conv.<type> >` возвращает запись; декодировка через `envelope.parse` (одна `json.loads`).
- `worker:job:{jobId}` пишется при выдаче с полями `inputBucket`/`inputKey`/`conversionId`/`stream`/`targetFormat`, TTL 24 h; `DEL` при ack.
- `XAUTOCLAIM` и `XACK` вызываются под переданным `workerId` (consumer per-worker, не глобальный).
- `config.py` читает `WS_BLOCK_MS`, KeyDB-креды и т.п. через `load_config()`; секреты пустые в трекаемом env.
- `make test-gateway` зелёный на **реальном** KeyDB (не мок): seed записи → read → mark meta → ack → PEL пуст.
- `docker/workers/gateway.Dockerfile` собирается; образ пригоден для Harbor.

**Зависит от:** `[[s1-01-clean-wire-contract]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready (ревью APPROVE WITH NITS; nit #2 XPENDING-consumer assertion + nit #4 косметика закрыты `984ccde`; `make test-gateway` 5 passed на реальном KeyDB, `build-gateway` + `docker-check` зелёные; nits #1/#3 координации перенесены в карту s1-03). Ждёт финального ready→done пользователя.

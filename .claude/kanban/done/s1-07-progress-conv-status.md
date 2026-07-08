### S1-07 — Progress-протокол + gateway-owned conv:status

**Критичность:** Medium

**TAGS:**
- transport
- gateway
- protocol

**Описание:**
Восстановить живое состояние «processing»/прогресс, потерянное при полной унификации результата (Option A убрала worker-writable `conv:status`). Владельцем живого статуса становится **gateway** (§4/§5/D5 spec). Сворачивает `[[worker-pull-api-live-status-hash]]`.

**Progress-фрейм (§4):** `{type:"progress", jobId, percent:<0..100>, stage?}`. Воркер шлёт ~**раз в секунду** (`WS_PROGRESS_INTERVAL_S`, дефолт `1`) для объёмных/долгих задач, **в дополнение** к `ping` (разные фреймы). Эмитится ТОЛЬКО между `job` и терминальным `result`/`fail`; вне задачи (idle) — не шлётся. `percent`/`stage` — best-effort индикатор для UI; потерянный `progress` не влияет на надёжность.

**conv:status у gateway (D5):**
- При dispatch (проталкивание `job`): `HSET conv:status:{conversionId} state=processing` (TTL 24 h).
- На каждый `progress`: `HSET conv:status:{conversionId} state=processing percent=<n> stage=<...>`.
- При терминале/ack: `DEL conv:status:{conversionId}` — дальше истина в терминальной строке БД.

**Читатель без изменений:** `App\Service\Queue\ConversionStatusReader` и `ConversionManager::getStatus()` — БЕЗ ИЗМЕНЕНИЙ: читают тот же `conv:status:{conversionId}` (`hGetAll`), при очищенном/протухшем падают на строку MariaDB (уже так устроено). Меняется только **писатель**: теперь gateway, а не воркер. Поля `percent`/`stage` — дополнительные в хеше (для будущего UI); `getStatus()` в S1 их не сюрфейсит.

> Не путать два ключа: `worker:job:{jobId}` (мета для input/result-эндпоинтов) vs `conv:status:{conversionId}` (живой статус). Разное пространство id, оба чистятся при ack.

**Файлы:**
- Изменить: `workers/gateway/ws_server.py` (обработка `progress` → `HSET conv:status`; write-lifecycle dispatch/progress/terminal).
- Изменить: `workers/gateway/keydb.py` (`HSET`/`DEL conv:status`).
- Изменить: `workers/gateway/config.py` (при необходимости).
- Изменить: `workers/tests/` — progress + conv:status lifecycle.

Клиентская отправка `progress` (тик, только пока задача в работе) — в `[[s1-08-shared-ws-client]]`; здесь серверная запись `conv:status` + контракт.

**Критерии приёмки:**
- Воркер шлёт `progress{percent,stage}` пока задача в работе → gateway обновляет `conv:status` (`percent`/`stage`); вне задачи `progress` НЕ эмитится (ассерт).
- conv:status write-lifecycle: dispatch → `HSET state=processing`; на `progress` → `percent`/`stage`; на терминале/ack → `DEL`.
- `ConversionStatusReader.read()` видит эти значения; после ack читатель падает на строку БД.
- `ConversionStatusReader` / `getStatus()` диффом не тронуты (читатель неизменен).
- Воркеры KeyDB не трогают (нет worker-writable conv:status).
- `make test-gateway` зелёный.

**Зависит от:** `[[s1-03-ws-server-dispatch]]`, `[[s1-04-result-relay-ack]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Ревью (2026-07-05):** APPROVE-WITH-NITS, 0 must-fix. Унификация подтверждена — единый
писатель `conv:status` через `keydb.set_status_processing` (dispatch `ws_server.py:294,327`
+ reclaim `reclaim.py:90`), progress → `update_status_progress`, терминал → `clear_status`;
ключ по `conversionId` (не jobId); `app-symfony`-reader байт-в-байт не тронут. Нит ревью
(clear_status на `add_to_dlq`-fail = нарушение AC «only true terminals DEL») **исправлен**
(`dbaa16d`): DLQ-write-fail → запись остаётся полностью pending, `conv:status` сохранён для
reclaim. `make test-gateway` → **56 passed** (было 33). Коммиты: `6667597` + `dbaa16d`.

**Status:** ready — ревью APPROVE, нит исправлен, `make test-gateway` 56 passed.
Ждёт финального ready→done пользователя.

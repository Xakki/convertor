### S1-05 — Ping/pong liveness + переподключение воркера

**Критичность:** Medium

**TAGS:**
- transport
- protocol
- reliability

**Описание:**
Реализовать быструю (секунды) сторону модели liveness (§4/§6.6 spec). Воркер периодически шлёт `ping{cpu,mem,load}` (период `WS_PING_INTERVAL_S`), gateway отвечает `pong`. Если `pong` не пришёл в течение liveness-окна по критерию **«N пропущенных ping'ов»** (`WS_LIVENESS_MISSED_PINGS`, а НЕ единичный жёсткий дедлайн — WAN-скачки латентности удалённого AI-воркера дали бы ложный reconnect) → воркер **сам переподключается** под тем же `workerId`, с **экспоненциальным backoff** (`WS_RECONNECT_BACKOFF_*`), чтобы не устроить reconnect-шторм. То же переподключение — при простом обрыве WS.

По факту reconnect gateway (единственный читатель KeyDB) возобновляет PEL этого `workerId`: `XREADGROUP GROUP convertor <workerId> ... STREAMS conv.<type> 0` → переотправляет уже-pending записи (путь «a», §6.6). `ping` — liveness+телеметрия, отдельный фрейм от `progress`.

**Запрещённая ловушка (§6.3/§6.6):** НЕ добавлять reclaim по WS-дисконнекту. Transient-обрыв — норма; воркер переподключается под тем же `workerId` и возобновляет PEL. Reclaim остаётся ТОЛЬКО idle-timeout (`[[s1-06-reclaim-poison-dlq]]`). Ordering: окно reconnect (секунды) ≪ per-type idle-порог (минуты).

Поля `cpu`/`mem`/`load` в `ping` в S1 только транспортируются/логируются — не потребляются.

**Файлы:**
- Изменить: `workers/gateway/ws_server.py` (обработка `ping` → `pong`; resume PEL по reconnect того же `workerId`).
- Изменить: `workers/gateway/config.py` (при необходимости — параметры).
- Изменить: `workers/tests/` — тест ping/pong + reconnect-resume без reclaim.

Клиентская сторона (отправка `ping`, детект N пропущенных, backoff-reconnect) реализуется в общем клиенте `[[s1-08-shared-ws-client]]`; здесь — серверная обработка + контракт.

**Критерии приёмки:**
- Воркер шлёт `ping` → gateway отвечает `pong`.
- Фейковый воркер с pending-записью роняет WS и быстро переподключается под тем же `workerId` до истечения idle-порога → `XREADGROUP ... 0` возобновляет его PEL, reclaim НЕ срабатывает, дубликата нет (§6.6 путь «a»).
- Критерий reconnect = «N пропущенных ping'ов» (`WS_LIVENESS_MISSED_PINGS`), не единичный дедлайн; значение tunable.
- Grep/ассерт: НЕТ reclaim по событию WS-дисконнекта.
- `make test-gateway` зелёный.

**Зависит от:** `[[s1-03-ws-server-dispatch]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready (ревью **APPROVE**, actionable-нитов нет; `ccd1175`; ping ортогонален кредит-циклу — `_handle_ping` без доступа к `credits`; единственный `reclaim_stale` в `_dispatch`, source-guard ловит reclaim-on-disconnect module-wide; окно reconnect 60s ≪ idle 300s; `test-gateway` 24 passed + docker-check зелёные; carry-forwards → s1-08 читает knobs из Config + опц. ping-flood rate-limit). Ждёт финального ready→done пользователя.

### S1-06 — Idle-reclaim → re-dispatch + poison-job DLQ

**Критичность:** Medium

**TAGS:**
- transport
- gateway
- reliability

**Описание:**
Реализовать backstop-надёжность gateway (§6.3 spec) + защиту от poison-job. Сворачивает `[[worker-pull-api-poison-job-dlq]]` и переносит `PermanentError` fast-DLQ из `[[align-document-stream-matrix-dlq]]` в WS-протокол.

**Reclaim (медленная сторона liveness, минуты):**
- Gateway крутит периодический async reclaim-цикл (`RECLAIM_INTERVAL_S`, дефолт `60`).
- **Триггер — ТОЛЬКО idle-timeout** (запись провисела в PEL дольше idle-порога своего типа). Никакого reclaim по WS-дисконнекту.
- **Idle-порог — на каждый `conv.<type>`** (config-map `RECLAIM_IDLE_MS_<TYPE>`, НЕ единое значение): document `300000`, image `120000`, audio `300000`, video `600000`, data `180000`, ai `300000` (черновые, регулируются позже). Порог типа ОБЯЗАН превышать макс. время обработки задачи этого типа, иначе медленная-но-живая задача переклеймится и обработается дважды.
- На каждый тип: `XAUTOCLAIM conv.<type> convertor <reclaimConsumer> <minIdle[type]> 0-0 COUNT <n>`.
- **Критично:** переклеймленная запись НЕ паркуется в PEL reclaimer'а — она немедленно входит в ТОТ ЖЕ путь диспетчеризации: разобрать → (пере)записать `worker:job:{jobId}` + `conv:status`=processing → в кредитный dispatch → протолкнуть `job` следующему готовому воркеру под ЕГО `workerId`. Reclaim-цикл — только триггер.

**Poison-job DLQ:**
- Счётчик попыток = **XPENDING times_delivered** (без кастомного `attempts`-поля).
- Триггер DLQ = **на `fail`**: при `fail`, если `times_delivered > N` → DLQ + ack; иначе оставить unacked для ретрая. `{type:"fail", permanent:true}` → **немедленный** DLQ, без ретрая (переносит `PermanentError` fast-DLQ: воркер поднимает `permanent:true` на детерминированной неретраябельной ошибке вместо прежнего `except PermanentError`).
- DLQ-стрим = **`conv.dead`** (каноничный); согласовать старое имя `conv.result.dead` из `QueueResultConsumerCommand` к `conv.dead`. Реальная причина (`str(error)`) сохраняется в DLQ-записи, не хардкод «max_retries exceeded».
- N = **3** (совпадает с Python `_MAX_RETRIES`).
- KNOWN GAP (записать, не чинить здесь): silent-crash poison-job, который никогда не шлёт `fail`, ловится ТОЛЬКО idle-reclaim (крутится до idle/manual). Claim-side guard — follow-up при необходимости.

**Файлы:**
- Изменить: `workers/gateway/reclaim.py` (создать) — async reclaim-цикл, per-type `XAUTOCLAIM`, re-dispatch через общий путь.
- Изменить: `workers/gateway/ws_server.py` (обработка `fail{permanent}` → DLQ-решение по `times_delivered`).
- Изменить: `workers/gateway/keydb.py` (`XPENDING` times_delivered, `XADD conv.dead`).
- Изменить: `workers/gateway/config.py` (`RECLAIM_IDLE_MS_<TYPE>` map, `RECLAIM_INTERVAL_S`, N).
- Изменить: `workers/tests/` — reclaim-by-idle, poison-DLQ, canonical `conv.dead`.

**Критерии приёмки:**
- Seed pending-записи, прокрутить idle за per-type порог → reclaim-цикл передиспетчеризует её через обычный путь `job` (не запаркована); ассерт: триггер = пересечение idle-порога, НЕ обрыв WS.
- Reclaim НЕ срабатывает, пока idle < порога типа.
- `fail` при `times_delivered > 3` → запись в `conv.dead` с реальной причиной + ack; при `<= 3` → оставлена unacked.
- `fail{permanent:true}` → немедленный `conv.dead` + ack, без ретрая.
- Grep: DLQ-стрим везде `conv.dead` (нет `conv.result.dead`).
- `make test-gateway` зелёный.

**Перенос из ревью s1-04 (учесть при reclaim/redelivery):**
- **[cross-midnight orphan]** `ResultKeyBuilder` строит ключ результата по текущей дате (`results/{Y}/{m-d}/{id}.{ext}`). При re-dispatch задачи через полночь (idle-reclaim передиспетчеризует, воркер снова заливает результат) ключ будет с НОВОЙ датой, а `persist()` — no-op (терминальный guard по `conversionId`). Итог: осиротевший S3-объект под новой датой, который DB-keyed 24h-cleanup (метёт по строке БД со старым ключом) не подберёт. «Детерминированность ключа» — только within-day. Редко + предсуществующая логика; при реализации reclaim решить: либо ключ без даты (по `conversionId`), либо пометка для sweep. Не чинить вслепую — согласовать.

**Зависит от:** `[[s1-03-ws-server-dispatch]]`, `[[s1-04-result-relay-ack]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Реализация (ветка `task/s1-ws-transport`):**
- `775cafd` — idle-reclaim loop (`reclaim.py`, per-type idle-map, XAUTOCLAIM→handoff-queue→обычный `_push_job`, триггер только idle) + poison-DLQ (`XPENDING times_delivered`, `>3`→`conv.dead`+XACK, `permanent:true`→мгновенный DLQ, реальная причина).
- `8b7b08d` — polish: интеграционный тест «ack после reclaim чистит group-PEL» (#4) + косметика (#2 комментарий про bound очереди, #3 лог, #5 create_task после ping, #6 docstring).

**Ревью — APPROVE-WITH-NITS, must-fix НЕТ.** Верифицированы 3 крит-инварианта: per-type idle-порог, единственный `reclaim_stale`-сайт, group-scoped XACK чистит `gw-reclaim` PEL. DLQ-семантика (граница `>3`=N, реальная причина, permanent fast-path, нет двойного ретрая) — корректна. Тесты реальные на живом KeyDB. `make test-gateway`: **33 passed** (было 24).

**Follow-up (grooming, не блокеры):**
- [[dlq-xadd-xack-nonatomic]] — #1: `XADD conv.dead`+`XACK` не атомарны → возможен дубль DLQ (Lua/идемпотентность).
- [[reconcile-conv-result-dead-legacy]] — PHP `conv.result.dead` = DLQ легаси-стрима `conv.result`; согласовать ретайр command'а (AC-греп `conv.dead` относится к gateway — там чисто).
- **[cross-midnight orphan]** (перенос из s1-04, см. выше) — PHP-зона `ResultKeyBuilder`, отдельное согласование.

**Status:** ready — ревью APPROVE-WITH-NITS (0 must-fix), `make test-gateway` 33 passed. Ждёт финального ready→done пользователя.

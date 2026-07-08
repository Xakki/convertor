### Worker pull-API: нет DLQ и delivery-count для poison-jobs

**Superseded by S1:** поглощена эпиком [[s1-ws-worker-transport]] → [[s1-06-reclaim-poison-dlq]] (poison-DLQ через `fail{permanent:true}` → gateway `conv.dead`). Отдельно НЕ брать.

**Тег:** tech-debt · worker-api · reliability

**Описание:**
`WorkerStreamGateway::reclaimStale()` возвращает зависшую запись (idle > 5 мин) через
XAUTOCLAIM, но **не считает количество попыток доставки и не отправляет в DLQ**.

Это означает: если задача сломана (воркер падает при её обработке) — она будет
рекламироваться снова и снова каждые 5 минут и блокировать очередь перед новыми заданиями.

On-server `QueueResultConsumerCommand` пишет в `conv.result.dead` (DLQ) через `xAdd` после
нескольких неудачных попыток. Pull-API аналога не имеет.

**Решение (вариант):**
1. В `worker:job:{jobId}` meta добавить поле `attempts` (инкрементить при каждом claim/reclaim).
2. При `attempts >= N` (напр. 3): XACK запись, вызвать `persister->persist([..., 'state'=>'failed'])`.
3. Или: использовать встроенный Redis счётчик `XPENDING` для подсчёта.

**Приоритет:** Medium (блокирует производство при наличии poison-messages).

**Контекст:** validate-ai-worker → WorkerStreamGateway (2026-06-23). Аналог есть в
`QueueResultConsumerCommand::processDeadEntry()`.

**Decisions:**
- Attempt count = **XPENDING times_delivered** (Q3.1) — drop the custom `attempts` meta field.
- DLQ trigger = **on `/fail`** (Q3.2): on a reported fail, if times_delivered > N → DLQ+ack; else leave unacked for retry. `{"permanent":true}` on the /fail body → immediate DLQ, no retry.
- DLQ stream = **`conv.dead`** (canonical); reconcile the existing `conv.result.dead` name used by QueueResultConsumerCommand to `conv.dead` too.
- N = **3** (matches Python `_MAX_RETRIES`).
- KNOWN GAP (record, do not fix here): a silent-crash poison job that never calls `/fail` is not caught by the /fail-side check — it is re-handed by reclaimStale() until idle/manual. Follow-up card later for a claim-side guard if it bites.
- Card stale fix: card refs nonexistent `processDeadEntry()` → actual method is `sendToDlq()`.

**Status:** superseded (S1)

### Worker pull-API: нет DLQ и delivery-count для poison-jobs

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

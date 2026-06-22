### Worker pull-API: не пишет live-статус hash в Redis

**Тег:** tech-debt · worker-api

**Описание:**
`WorkerController` (result/fail) обновляет статус Conversion только в MariaDB через
`ConversionResultPersister`, но **не пишет в Redis hash `conv:status:{id}`** (TTL 24 h).

Поэтому `/api/v1/conversions/{id}/status` пока работает через DB-fallback (pending →
completed без промежуточного «processing»). On-server воркеры пишут hash в Redis
напрямую через `QueueResultConsumerCommand`. Pull-API-воркеры — нет.

**Последствие:** нет live-прогресса для off-server воркеров (только pending/completed/failed,
без «processing»).

**Решение (вариант):** В `WorkerController::claim()` писать `conv:status:{id}` = processing
(как on-server consumer делает при взятии задачи). В result/fail — писать completed/failed.
Аналогично ConversionStatusReader#write() (если метод появится) или прямо через Redis.

**Приоритет:** Low (не блокирует MVP, статус виден через DB после завершения).

**Контекст:** validate-ai-worker → task WorkerController (2026-06-23).

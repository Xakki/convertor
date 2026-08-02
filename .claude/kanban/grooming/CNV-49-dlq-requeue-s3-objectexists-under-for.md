### DLQ requeue: S3 objectExists под FOR UPDATE lock держит строку слишком долго

**Criticality:** Nit
**Epic:** [[CNV-48]]
**Discovery:** review CNV-11 (реализация `SELECT ... FOR UPDATE` в `requeue()`)

**TAGS:**
- dlq
- concurrency

**Description:**
По итогам review CNV-11: `DlqController::requeue()` теперь держит
`SELECT ... FOR UPDATE` на строке `Conversion`, пока внутри той же транзакции
вызывается S3 `objectExists` (сетевой I/O). При задержках S3 это продлевает
удержание InnoDB row lock и может блокировать параллельные admin-операции над
той же или связанными строками.

**Problem:**
Сетевой вызов S3 внутри транзакции с row lock — антипаттерн: lock time
привязан к latency S3, а не к локальной бизнес-логике.

**Impact:**
Низкий — admin-only requeue, редкий сценарий; проявляется при высокой latency
S3 или нескольких одновременных requeue по смежным конверсиям.

**Recommendation:**
Вынести проверку существования объекта S3 за пределы locked-транзакции
(до `FOR UPDATE` или после `commit`) либо кэшировать результат до захвата lock.

**Acceptance Criteria:**
- S3 `objectExists` не выполняется внутри транзакции с `SELECT ... FOR UPDATE`.
- Поведение requeue при отсутствии объекта в S3 сохраняется (ошибка/отказ).
- Тесты/QA green: `make phpstan`, `make cs-check`, релевантные PHPUnit для DLQ.

**Open questions:**
- Проверка S3 до lock (fail-fast без lock) vs после release (lock только для charge/attempt) — что предпочтительнее?
- Нужна ли политика timeout/retry для S3 до входа в locked-секцию?

**Decisions:**

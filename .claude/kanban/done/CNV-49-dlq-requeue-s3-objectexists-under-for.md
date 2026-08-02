### DLQ requeue: S3 objectExists под FOR UPDATE lock держит строку слишком долго

**Criticality:** Nit
**Epic:** [[CNV-52]]
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
(до `FOR UPDATE` — fail-fast) либо после `commit`.

**Acceptance Criteria:**
- S3 `objectExists` не выполняется внутри транзакции с `SELECT ... FOR UPDATE`.
- Поведение requeue при отсутствии объекта в S3 сохраняется (ошибка/отказ).
- Тесты/QA green: `make phpstan`, `make cs-check`, релевантные PHPUnit для DLQ.

**Decisions:**
- (2026-08-02) Вариант A (@user): S3 `objectExists` **до** `FOR UPDATE`
  (fail-fast без удержания row lock); новую timeout/retry-политику не вводить.

**Status:** ready

**Execution Log:**
- (2026-08-02) S3 `objectExists` вынесен до `wrapInTransaction`/`FOR UPDATE`:
  plain `find` → fail-fast 404/`not_failed`/`input_gone` → locked txn только
  status+charge+attempt (`DlqController::requeue`).
- QA: `make phpstan` OK; `make cs-check` OK; PHPUnit
  `DlqControllerTest` + `ConversionForUpdateRepositoryTest` 10/10 OK.
- moved progress→test→ready.
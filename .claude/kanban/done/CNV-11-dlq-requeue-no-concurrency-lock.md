### DLQ-requeue: нет оптимистичной/пессимистичной блокировки при параллельном requeue

**Criticality:** Nit
**Epic:** [[CNV-48]]

**TAGS:**
- tech-debt
- dlq
- concurrency

**Description:**
`Conversion` не имеет `#[ORM\Version]`, а `DlqController::requeue()` читает строку
через `find()` + проверку статуса без `SELECT ... FOR UPDATE`
(`app-symfony/src/Controller/Admin/Api/DlqController.php`). Два одновременных
admin-запроса requeue одной и той же `Failed`-конверсии оба прочитают
`attempt=N`, оба переведут в `Pending`, оба независимо вызовут `charge()` —
квота гарантированно спишется на +2 вместо +1 (два раздельных SQL `UPDATE ...
+1`). Итоговое значение `attempt` после двух конкурентных `incrementAttempt()`
зависит от реализации (in-memory `++` перед одним `flush()` vs. атомарный SQL
`+1`) — не проверялось отдельно. В любом случае будет запущено два
дублирующих джоба на воркерах, и если один из них упадёт пока другой успешно
завершится — результат проигравшего будет молча отброшен (staleness-guard так
и задуман, но тут отбрасывается легитимный дубль, а не устаревшая попытка).

**Problem:**
Гонка возможна только между двумя одновременными операторскими вызовами
requeue по одному и тому же ID — редкий, admin-only сценарий, не связан с
worker/DLQ-транспортом напрямую.

**Impact:** низкий — требует двух почти одновременных ручных requeue одной
конверсии одним/двумя операторами; не задействуется штатным DLQ/at-least-once
трафиком.

**Recommendation:**
`SELECT ... FOR UPDATE` в `DlqController::requeue()` перед проверкой статуса
(не `#[ORM\Version]` на `Conversion`).

**Acceptance Criteria:**
- `requeue()` берёт строку `Conversion` через `SELECT ... FOR UPDATE` (в той же
  транзакции, что проверка статуса / charge / incrementAttempt).
- Параллельный второй requeue той же Failed-конверсии не списывает квоту
  дважды и не ставит второй джоб (второй получает конфликт/already-requeued).
- `#[ORM\Version]` на `Conversion` **не** добавлять.
- Тесты на конкурентный requeue / блокировку; QA зелёные.

**Decisions:**
- (2026-08-01) Только `FOR UPDATE` в `requeue()`; без ORM Version на
  `Conversion`.

**Контекст:** найдено round-2 адверсариальным ревью attempt-marker фикса
(ветка `task/conv-dead-no-consumer`, коммиты `184250f`/`8a63ea3`/`c1f6c2f`),
2026-07-18. Не введено этим фиксом (пред-существующее), но всплыло при
трассировке requeue-пути.

**Status:** ready

**Execution Log (2026-08-02):**
- moved todo→progress; work started on branch `epic/CNV-48` — `SELECT ... FOR UPDATE` lock in `DlqController::requeue()`
- (2026-08-02) FOR UPDATE via ConversionRepository::findOneByIdForUpdate inside wrapInTransaction with status/S3/charge/incrementAttempt; second requeue → 409 not_failed; no ORM Version
- QA: phpunit DlqControllerTest + ConversionForUpdateRepositoryTest OK (10/10); make phpstan OK; make cs-check OK
- commit 7805b38 api: lock DLQ requeue with FOR UPDATE

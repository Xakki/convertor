### У DLQ-стрима `conv.dead` нет потребителя → строка Conversion навсегда `pending`

**Criticality:** High

**TAGS:**
- bug-fix
- reliability
- transport
- gateway

**Description:**
DLQ-стрим `conv.dead` **никто не читает**. Когда задача исчерпывает ретраи,
gateway делает `XADD conv.dead` + `XACK` со стрима-источника — и на этом всё:
запись оседает в `conv.dead` write-only (это же зафиксировано в
[[dlq-xadd-xack-nonatomic]] — «потребителя conv.dead сегодня нет»).

**Problem:**
Строка `Conversion` в БД остаётся `status=pending` **навсегда**: её никто не
переводит в `failed` и не переобрабатывает. Именно поэтому упавшая задача
выглядит для пользователя как «зависла на pending».

**Impact:**
User-visible: провалившиеся задачи бесконечно маскируются под «в процессе».
Пользователь не получает ни ошибки, ни финального статуса; оператор не видит
факт провала без ручного чтения `conv.dead`. Квоты/лимиты могут считаться
некорректно (задача не финализирована).

**Recommendation:**
Ввести потребителя `conv.dead` (consumer-группа) ИЛИ периодический
reconciler, который:
- переводит соответствующую строку `Conversion` в `failed` с причиной из
  DLQ-payload (реальная причина, не обобщённая);
- опционально поддерживает operator-requeue (ручной перезапуск из DLQ).
Согласовать с [[dlq-xadd-xack-nonatomic]] (атомарность XADD+XACK) и
[[s1-06-reclaim-poison-dlq]] (что именно кладётся в `conv.dead`).

**Acceptance Criteria:**
- После попадания задачи в `conv.dead` её строка `Conversion` детерминированно
  становится `failed` с осмысленной причиной (из DLQ-payload).
- Ни одна провалившаяся задача не остаётся `pending` неопределённо долго.
- `conv.dead` перестаёт быть write-only (есть читатель ИЛИ reconciler).
- Tests/QA green: `make test`, `make phpstan`, `make cs-check`.

**Decisions:**
Финализацию делает НОВЫЙ консьюмер `conv.dead` в gateway (Python, XREADGROUP) —
согласовано с принципом "единственный читатель KeyDB Streams = gateway";
gateway читает DLQ и шлёт Symfony через internal relay команду finalize-failed
(проставить `Conversion.status=failed` + причину из DLQ-payload). Объём первой
итерации: пометка failed И operator-requeue из админки (`/api/v1/admin/*`) —
оператор может вручную перезапустить DLQ-задачу.

**Контекст:** инцидент «#6 stuck» / разбор DLQ (2026-07-12). Смежные:
[[dlq-xadd-xack-nonatomic]], [[s1-06-reclaim-poison-dlq]].

**Зависимость от hardening-06-processing-ms (2026-07-17):** failure-путь уже
проводит `processingMs` (`ResultSignal.failed → WS fail frame → RelayClient.post_fail
→ InternalWorkerController::fail`), но он dormant, пока нет этого консьюмера. При
реализации: `ws_server._handle_fail`/`add_to_dlq` сейчас РОНЯЮТ `processingMs` из
fail-frame перед записью в DLQ — расширить DLQ-payload этим полем и протянуть его
до финализации `Conversion.failed`, иначе тайминг фейла так и не запишется.

**Status:** grooming.

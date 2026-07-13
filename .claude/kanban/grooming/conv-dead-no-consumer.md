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

**Open questions:** *(grooming)*
- Потребитель `conv.dead` в gateway (Python, XREADGROUP) vs Symfony-side
  reconciler (Scheduler/cron, сверка pending-строк с DLQ) — где логичнее финализация?
- Нужен ли operator-requeue в первой итерации или только пометка `failed`.
- Формат/полнота DLQ-payload: достаточно ли в нём `conversionId` + причины для
  финализации без обращения к стриму-источнику.

**Контекст:** инцидент «#6 stuck» / разбор DLQ (2026-07-12). Смежные:
[[dlq-xadd-xack-nonatomic]], [[s1-06-reclaim-poison-dlq]].

**Status:** grooming.

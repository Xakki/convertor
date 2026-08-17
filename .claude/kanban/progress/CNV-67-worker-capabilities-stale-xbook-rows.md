### Ручной make-таргет для GC worker_capabilities + разово почистить 6 строк xbook

**Criticality:** Low

**TAGS:**
- tech-debt
- workers
- registry
- cleanup

**Description:**
В `worker_capabilities` 6 строк хоста `xbook` находятся в статусе
`disconnected` с 2026-07-30 и не убираются. Автоочистка на самом деле УЖЕ
существует: `WorkerCapabilityGcService::run()`
(`app-symfony/src/Service/Worker/WorkerCapabilityGcService.php:88-94`)
удаляет строки с `last_seen` старше порога, TTL берётся из
`WORKER_CAPABILITY_GC_TTL_HOURS` (`services.yaml:53`, сейчас `168` часов =
7 суток), запускается ежечасно планировщиком
(`app-symfony/src/Schedule.php:40`,
`RecurringMessage::every('1 hour', WorkerCapabilityGcMessage)`).
`WorkerLivenessReconciler` (`:76-89`) помечает молчащие инстансы
`disconnected`, но сознательно не трогает `lastSeen` — поэтому 6 строк
`xbook`, «замолчавших» 2026-07-30, просто ещё не пересекли 7-дневный TTL, а
не «чистка не реализована».

**Problem:**
Нет способа запустить GC вручную с явным TTL — только ждать часовой тик
планировщика с фиксированным (env) TTL. Это неудобно для разовой чистки
известного мусора без изменения общего TTL продакшена.

**Impact:**
Низкий — мусорные строки не мешают работе, только засоряют
`worker_capabilities` и админку до истечения TTL.

**Recommendation:**
Автоматическая ежечасная очистка с TTL=168ч оставлена как есть (решение
пользователя — менять её не нужно). Добавить ТОЛЬКО ручной make-таргет,
запускающий `WorkerCapabilityGcService` синхронно с переопределяемым TTL
(например, `TTL_HOURS=`, по умолчанию — текущее значение
`WORKER_CAPABILITY_GC_TTL_HOURS`), и разово прогнать его с TTL=3 суток
(`TTL_HOURS=72`), чтобы удалить 6 строк `xbook` (молчат с 2026-07-30, к
моменту запуска — больше 3 суток).

**Acceptance Criteria:**
- Существует make-таргет (например, `make worker-capability-gc`),
  запускающий очистку `WorkerCapabilityGcService` синхронно (не через
  ожидание часового тика планировщика), с переопределяемым `TTL_HOURS=`
  (по умолчанию — значение env `WORKER_CAPABILITY_GC_TTL_HOURS`).
- `##`-описание таргета в Makefile — терсе, по правилу проекта.
- После `make worker-capability-gc TTL_HOURS=72` все 6 строк `xbook`
  (`disconnected` с 2026-07-30) удалены из `worker_capabilities`.
- Живые/прочие воркеры в `worker_capabilities` не затронуты (count и
  статусы совпадают с состоянием до запуска, за вычетом удалённых строк
  `xbook`).
- Часовой автоматический GC (TTL=168ч, `WorkerCapabilityGcMessage`) не
  изменён.

**Decisions:**
- 2026-08-04: автоочистка уже существует и остаётся как есть (168ч,
  ежечасно) — менять её не требуется. Добавляем только ручной таргет для
  разового/точечного запуска с явным TTL и используем его для чистки 6
  строк `xbook`. Прежняя формулировка карты («чистки не существует, нужно
  продумать TTL») была основана на неверной предпосылке — исправлено.

**Контекст:** найдено в ходе диагностического прогона 2026-08-04.

**Status:** progress

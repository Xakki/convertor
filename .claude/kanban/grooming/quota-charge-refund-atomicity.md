### Квота: неатомарность charge/refund (race при масштабировании)

**Критичность:** Low

**TAGS:**
- bug
- tech-debt

**Описание:**
Найдено при ревью `backend-hardening-bugs` (2026-06-22). `QuotaService::charge()` и `refund()` — read-then-write по счётчику без оптимистичного лока. Сейчас не воспроизводится (result-consumer запущен в single-instance: `docker/php/supervisor.app.ini`, без `numprocs`), но при масштабировании консьюмера два процесса могут перетереть запись (last-flush-win). `clamp-at-0` защищает от ухода в минус, но не от неверного учёта.

**Проблема:**
- При >1 экземпляре result-consumer (или гонке web-charge vs near-instant worker-refund) возможен некорректный учёт квоты.

**Решение (черновик):**
- `@Version` (optimistic lock) на `Conversion` и/или `User`, либо атомарный decrement на уровне БД, либо счётчик в KeyDB с атомарными операциями.

**Open questions:**
- Нужно ли это вообще до фактического масштабирования консьюмера? (возможно, отложить до решения о scale-out)
- Где держать счётчик квоты — Doctrine (`@Version`) или KeyDB (atomic INCR/DECR)?

**Decisions:**
- (заполнить при грумминге)

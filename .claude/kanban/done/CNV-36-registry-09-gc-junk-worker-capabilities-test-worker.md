### GC junk worker_capabilities (test:worker)

**Criticality:** Medium

**TAGS:**
- tech-debt
- registry
- matrix

**Epic:** [[CNV-47]] — подзадача 11.

**Description:**
В `worker_capabilities` осталась мусорная строка `test:worker` (и при необходимости
прочий junk), которая попадает в матрицу форматов наравне с реальными воркерами.
Вынесено из grooming `conversion-matrix-no-stale-filter` (решение 2026-08-01:
soft-filter матрицы не вводим — registry-06 остаётся; seeds `__seed__` оставляем;
чистим только junk `test:worker`).

**Problem:**
Строка `test:worker` рекламирует пары в `/formats` и на admin workers page, хотя
это не живой воркер.

**Impact:**
Ложные пары в матрице / шум на admin workers page до срабатывания long-TTL GC.

**Recommendation:**
Удалить (или явно GC) junk-строку `test:worker` из `worker_capabilities`; seeds
`__seed__` не трогать. Свериться с `WorkerCapabilityGcService` / admin workers
page (`registry-07`).

**Acceptance Criteria:**
- Строки `worker_id=test:worker` (и аналогичный явный junk) удалены из БД /
  не появляются после деплоя/миграции/GC-прохода.
- Seeds `__seed__` сохранены (baseline registry-03).
- Матрица `/formats` и admin workers page больше не показывают `test:worker`.
- Тесты/QA зелёные по проектным cmd (`make phpstan` / релевантные PHPUnit).

**Decisions:**
- (из `conversion-matrix-no-stale-filter`, 2026-08-01) Q1=A: без soft-filter
  матрицы, registry-06 подтверждён. Q2=A: чистить junk `test:worker`, seeds
  оставить. Scope этой карточки — только GC/cleanup junk.

**Status:** ready — awaiting user approval

**Execution Log (2026-08-01):**
- moved todo→progress; branch `epic/CNV-47`
- Decision: one-shot Doctrine data migration DELETE `instance_id='test:worker'` + GC always-purge of known junk instance IDs (not matrix soft-filter); keep `__seed__`
- implementer: composer-2.5-fast — migration `Version20260801120000`, GC `JUNK_INSTANCE_IDS`, functional test
- commit `74bf942` (`db: purge junk test:worker from worker_capabilities`, Agent: backend; Co-authored-by stripped)
- QA: WorkerCapabilityGcServiceTest 7/7 pass; `make phpstan` OK
- AC met: junk purged via migrate+GC; seeds untouched; no matrix soft-filter
- moved progress→test→ready

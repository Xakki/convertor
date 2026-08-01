### Матрица форматов строится по всем строкам worker_capabilities без фильтра свежести

**Criticality:** Medium

**TAGS:**
- tech-debt
- registry
- matrix

**Description:**
`app-symfony/src/Service/Conversion/ConversionRegistry.php::buildRoutingPairs()` читает ВСЕ строки
через `WorkerCapabilityRepository::findAllCapabilities()` (`app-symfony/src/Repository/WorkerCapabilityRepository.php:92-95`,
где `findAllCapabilities()` — буквально `return $this->findAll();`, без единого условия
`WHERE`), и передаёт их все в `buildMatrixFromCapabilities()` — без фильтра по `status`/`lastSeen`.

Это не просмотр — решение задокументировано явно и намеренно в комментарии над
`buildMatrixFromCapabilities()` (`ConversionRegistry.php:387-396`):

```
* registry-06: {@see WorkerCapability::getStatus()} (liveness alive/
* disconnected/unknown) is DELIBERATELY never read here. Liveness is a
* monitoring signal, not a routing input (epic Decisions: "Eviction =
* long-TTL GC, NOT short liveness gating") — a `disconnected` instance
* keeps serving its declared pairs until GC actually removes its row
* (see {@see \App\Service\Worker\WorkerCapabilityGcService}). Do NOT add
* a `$cap->getStatus() === Disconnected → skip` filter to this loop; that
* is exactly the regression `[[registry-06-liveness-push]]` warns future
* changes against, and it is covered by a dedicated test
* (`ConversionRegistryLivenessStatusTest`).
```

**Problem:**
Наблюдалось 2026-07-23: живая матрица собиралась из строк remote-хостов + 2 «зависших» local-строк
(`lastSeen` старше 3ч+) + 6 строк `__seed__` (status=unknown) + 1 лишней строки `test:worker`.
Устаревшие строки продолжают рекламировать форматы, которые ни один живой воркер, возможно, уже не
обслуживает — как и задокументировано и намеренно допущено дизайном (`registry-06`), но
практический эффект такой же: пары могут указывать на пары, которые никто реально не обрабатывает
до срабатывания long-TTL GC.

Отдельно от дизайн-решения про liveness — в матрицу также попадает откровенный мусор в
`worker_capabilities`: строка `test:worker` и seed-строки `__seed__`, которые видны на admin
workers page наравне с реальными воркерами.

**Recommendation:**
Политику registry-06 не пересматривать. Cleanup junk `test:worker` — в
`[[CNV-36-registry-09-gc-junk-worker-capabilities-test-worker]]`. Опционально — короткий
docs/comment note, что soft-filter матрицы сознательно отвергнут.

**Эпик:** `[[registry-00-self-registration]]`, `[[registry-06-liveness-push]]`.

**Acceptance Criteria:**
- Decision зафиксирован: soft-filter матрицы не вводить; registry-06
  подтверждён; seeds остаются.
- Cleanup junk `test:worker` вынесен в `registry-09` (не scope этой карточки).
- Опционально: docs/comment note в коде/доке, что soft-filter отвергнут
  (не обязательный код-фикс матрицы).

**Decisions:**
- (2026-08-01) Q1=A: без soft-filter для `/formats`; оставить registry-06
  (все строки в матрице; liveness ≠ routing).
- (2026-08-01) Q2=A: чистить junk `test:worker`; seeds `__seed__` оставить.
  Реализация cleanup — follow-up `[[CNV-36-registry-09-gc-junk-worker-capabilities-test-worker]]`.

**Status:** todo / ready

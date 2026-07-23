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

**Open questions (для grooming, НЕ для немедленного фикса — решение registry-06 уже осознанно
принято):**
- Нужно ли пересмотреть политику «long-TTL GC, не liveness-gating» — например, ввести отдельный
  более короткий soft-фильтр именно для построения `/formats` (не трогая сам GC/eviction), или
  оставить как есть и полагаться исключительно на GC?
- Каким должен быть fallback, когда для категории не осталось ни одного живого воркера (сейчас —
  статический seed-бейзлайн)?
- Нужно ли чистить `worker_capabilities` от строки `test:worker` и/или скрывать `__seed__` строки
  с admin workers page (или явно их там маркировать как «нелив» baseline, а не как воркер)?

**Recommendation:** обсудить с владельцем `[[registry-06-liveness-push]]` — фиксить ли
`test:worker`/`__seed__` мусор в реестре отдельно от вопроса о liveness-фильтрации матрицы.

**Эпик:** `[[registry-00-self-registration]]`, `[[registry-06-liveness-push]]`.

**Status:** grooming.

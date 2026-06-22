### API: /formats рекламирует archive, /convert отвечает 422

**Критичность:** Low

**TAGS:**
- bug
- ux

**Описание:**
Найдено при ревью `backend-hardening-bugs` (2026-06-22). `workerCapabilities` содержит archive-запись (~line 206) → archive-пары проходят `isSupported` и рекламируются в `/formats`, но `/convert` теперь отклоняет их 422 (archive-воркера нет до Стадии 7). Рассинхрон: UI/клиент видит формат как поддерживаемый, а конверсия падает 422.

**Проблема:**
- Несогласованность между списком поддерживаемых форматов (`/formats`) и фактически принимаемыми (`/convert`) для archive.

**Решение (черновик):**
- Убрать archive из `workerCapabilities`/`/formats` до появления archive-воркера (Стадия 7), ИЛИ помечать его как «coming soon» и не отдавать в списке доступных.

**Open questions:**
- Скрыть archive полностью или показывать disabled/«скоро»?
- Где источник истины для «доступных» форматов — `workerCapabilities` или отдельный флаг готовности воркера?

**Decisions (2026-06-22):**
- Archive полностью убран из `workerCapabilities()` в `ConversionRegistry.php` (задача stream-subscription-distribution).
- Побочный эффект: archive-форматы (zip→tar.gz и т.п.) теперь возвращают HTTP 400 («Unsupported conversion»)
  вместо 422. Это корректно — пары не в реестре, контроллер ловит InvalidArgumentException.
- Мёртвый archive-guard в `ConversionManager.php` удалён; неиспользуемый import UnprocessableEntityHttpException убран.
- **Drift test `test_all_routing_keys_have_worker` теперь PASSED** — `archive` больше не в routingKeys.
- Stage-7 archive WORKER (zip/tar/gz/bz2/7z converter) — по-прежнему future work, не выполнено.

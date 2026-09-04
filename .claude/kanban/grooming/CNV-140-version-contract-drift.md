### Обновить устаревшие version-contract ожидания drift-тестов

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
Синхронизировать drift-тесты worker release/config contract с текущей версией
релиза после повышения baseline `APP_VER` до `0.2.0`.

**Problem:**
`make TEST=1 test-drift` падает на двух существующих ожиданиях в
`workers/tests/test_worker_api_ops_config.py`: тесты всё ещё требуют
`APP_VER=0.1.2` и CUDA image tags для `0.1.2`, тогда как tracked `.env` уже
задаёт `APP_VER=0.2.0`. Это независимый от CNV-124 test-maintenance debt.

**Impact:**
Полный drift gate остаётся красным даже при согласованном текущем release
baseline, поэтому результат CNV-124 и последующих изменений смешивается с
устаревшим version-contract шумом.

**Recommendation:**
Обновить только stale version assertions и соответствующие test fixtures до
текущего canonical `APP_VER=0.2.0`, сохранив проверки tag shape, CUDA image
selection и запрет старого формата. Не менять production release workflow или
переименовывать независимые drift-тесты.

**Acceptance Criteria:**
- Drift-тесты больше не требуют `APP_VER=0.1.2` или CUDA tags `0.1.2`, если
  canonical tracked baseline равен `0.2.0`.
- Сохраняются проверки `latest`, CUDA image naming и compose image selection.
- `make TEST=1 test-drift` проходит, включая оба ранее красных
  version-contract теста.
- Профильный Make/test contract и kanban-lint проходят; CNV-124 scope не
  расширяется.

**Open questions:**
- Перед реализацией подтвердить, что `0.2.0` остаётся canonical release
  baseline, а не временным локальным override.

**Decisions:**
- 2026-09-05: side-file as a narrowly scoped grooming card; no CNV-124
  production or test repair is included in this card.

**Execution Log:**
- 2026-09-05 — CNV-124 handoff evidence recorded two pre-existing
  version-contract failures in `make TEST=1 test-drift`; tracked `.env` is at
  `APP_VER=0.2.0`, while tests still assert `0.1.2` and matching CUDA tags.

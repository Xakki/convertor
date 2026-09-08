### Обновить устаревшие version-contract ожидания drift-тестов

**Status:** done

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
- Изменяются только два stale worker version-contract assertions/fixtures с
  `0.1.2` на `0.2.0`; source/runtime/config/deploy scope не расширяется.
- Сохраняются проверки tag shape, `latest`, CUDA image naming и compose image
  selection; `worker-ai:cuda` остаётся local-only.
- `make TEST=1 test-drift` проходит, включая оба ранее красных
  version-contract теста.
- Профильный Make/test contract и kanban-lint проходят; CNV-124 scope не
  расширяется.

**Decisions:**
- 2026-09-05: side-file as a narrowly scoped grooming card; no CNV-124
  production or test repair is included in this card.
- 2026-09-06: tracked `APP_VER=0.2.0` is the canonical release baseline. The
  implementation updates only the two stale worker version-contract
  assertions/fixtures from `0.1.2` to `0.2.0`, preserving tag shape, `latest`,
  CUDA local-only semantics, and compose image selection. No release workflow
  or environment change is authorized.

**Execution Log:**
- 2026-09-05 — CNV-124 handoff evidence recorded two pre-existing
  version-contract failures in `make TEST=1 test-drift`; tracked `.env` is at
  `APP_VER=0.2.0`, while tests still assert `0.1.2` and matching CUDA tags.
- 2026-09-06 — Canonically moved `todo → progress` after approval `f93355f`.
  Updated only the stale worker version-contract assertions/fixtures in
  `workers/tests/test_worker_api_ops_config.py` from `0.1.2` to canonical
  `0.2.0`; preserved tag shape, `latest`, CUDA local-only naming, and Compose
  selection. Exact two tests passed; `make TEST=1 test-drift` passed (49 tests).
  No production, environment, release-workflow, Compose, Dockerfile, or image
  policy files changed.
- 2026-09-06 — Full gate `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-epic006-root-probe
  make test` passed; `make build` passed for all configured images. Config check,
  targeted/full Kanban lint (0 errors, 0 warnings), and working/staged
  `git diff --check` passed. The contemporaneous note that CNV-140 remained in
  `progress` pending review is historical and superseded; CNV-141 was not
  modified or moved at that time, and no merge, push, release, or deploy was
  done.
- 2026-09-06 — APPROVE accepted on `epic/EPIC-006` at `65676c2`: exact version-drift tests passed; `make TEST=1 test-drift` passed (49 tests); full `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-epic006-root-probe make test` and `make build` passed. Evidence is sanitized; no credentials, tokens, raw request data, or generated artifacts are recorded. The contemporaneous `progress → ready`-only authorization is historical and superseded by the later explicit completion authorization; CNV-141 and the parent were untouched then, with no merge, push, release, or deploy action.
- 2026-09-08 — Пользователь явно разрешил завершить все текущие карточки из `ready/`; это историческое разрешение оформило CNV-140 как `done` единственным переходом `ready → done`.
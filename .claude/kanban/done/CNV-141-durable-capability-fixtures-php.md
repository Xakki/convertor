### Добавить durable capability fixtures для PHP conversion tests

**Status:** done

**Criticality:** Blocking

**TAGS:**
- bug-fix
- tech-debt

**Description:**
Сделать test-only durable fixture/helper для `worker_capabilities`, чтобы
контроллерские и E2E-тесты обычной очереди явно поднимали нужные capability для
`document`, `image` и `video` и после теста удаляли только свои строки.

**Problem:**
После intentional admission gate `ConversionManager` корректно возвращает 503
`worker_unavailable`, если в `worker_capabilities` нет подходящего типа. Чистый
`make test` не запускает conversion workers/profiles и не создаёт durable
`worker_capabilities`; поэтому 11 старых controller/E2E-тестов, ожидающих
mocked conversion flow, падают на 503 до своих intended assertions. Текущий
локальный фикс только для AI auth/quota-тестов не покрывает общий normal-queue
fixture stack.

**Impact:**
Release gate остаётся красным на тестовой инфраструктуре, а тесты не доказывают
свои HTTP/auth/quota/queue-контракты. Возврат seed-строк или запуск реальных
workers скроет несамодостаточность тестов и ослабит проверку admission gate.

**Recommendation:**
Добавить общий test-only helper/factory, который через repository/Entity fixture
создаёт durable capability с уникальным test-owned `instance_id` для
`document`, `image` или `video`, возвращает созданные идентификаторы для
assertions и в teardown удаляет только свои строки. Подключить helper к
затронутым controller/E2E-тестам, сохранив текущие assertions и порядок
availability-before-auth/quota. Не менять production admission, catalog,
worker registration или compose profiles.

**Acceptance Criteria:**
- На чистом тестовом стеке красная репродукция
  `make TEST=1 test-php` фиксирует исходный blocker: 11 затронутых
  controller/E2E-тестов получают HTTP 503 с `worker_unavailable` вместо своих
  intended assertions.
- Test-only durable helper создаёт capability для каждого требуемого
  `document`, `image` и `video` normal-queue пути без seed rows и без запуска
  реального worker/profile.
- Затронутые тесты проходят после подключения helper и сохраняют проверки
  успешного/mock conversion flow, HTTP/auth/quota/error-контрактов и
  availability-before-auth/quota порядка.
- Каждый тест очищает только созданные им уникальные capability rows; повторный
  прогон не оставляет durable test state и не удаляет чужие/production rows.
- Process/test-level contract фиксирует уникальный `instance_id` для каждого
  запуска теста (с test-owned prefix/nonce), сохраняет точный набор выданных
  значений и удаляет только строки с этими exact `instance_id` (не по одной
  категории и не по общему префиксу). После завершения test suite выполняется
  assertion, что по этому test-owned prefix не осталось ни одной строки.
- `ConversionManagerWorkerAvailabilityFunctionalTest::testNoWorkerRowAgainstRealEmptyTableRejectsWithWorkerUnavailable`
  остаётся fixture-free service-level no-row regression: он проверяет отказ
  `ConversionManager` на реальной пустой таблице и сохраняет RED/503 proof;
  это отдельное доказательство service/admission-поведения, а не доказательство
  HTTP mapping, который проверяется затронутыми controller/E2E-тестами.
- Профильные selectors запускаются через `make TEST=1 test-php
  FILTER='<approved selector regex>'` для всех 11 перечисленных тестов;
  полный integration/release gate — точная команда
  `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-epic006-root-probe make test`, затем
  `make build`. Также проходят `kanban-lint` и `git diff --check`.
- Production code, admission gate, worker registration и seed migrations не
  изменяются.

**Dependencies:**
- CNV-71-03 — delivered production `worker_unavailable` admission contract.
- CNV-60 — delivered precedent for unique test-owned `WorkerCapability` setup
  and cleanup; this card generalizes it beyond AI auth/quota tests.
- EPIC-006 release gate — remains blocked until the fixture repair is delivered
  and the full gate is rerun.

**Non-goals:**
- Не возвращать seed rows (`instance_id='__seed__'`) и не менять их migrations.
- Не поднимать, подключать или проверять реальные conversion workers/profiles.
- Не менять production routing/admission, worker health/liveness, catalog,
  queue transport или conversion behavior.
- Не переписывать unrelated worker-health, registry-GC или host pytest issues.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- 2026-09-06: Side-filed as a test-infrastructure grooming card from the
  EPIC-006 read-only investigation; production admission is intentional and
  must remain unchanged.
- 2026-09-06: Scope is one specialized test-only `WorkerCapabilityFixture` in
  `app-symfony/tests/Support`, shared by the affected controller/E2E tests for
  normal queue types `document`, `image`, `video`. It creates only the exact
  capability rows requested by a test, each with a unique test-owned
  `instance_id`, and teardown deletes only those exact fixture-owned rows; it
  never touches `__seed__` or pre-existing/production rows. No real worker or
  profile is started.
- 2026-09-06: The approved 11-selector matrix and ownership boundary is:
  `ConversionQuotaEnforcementTest::testRegisteredUserOverDailyQuotaUsesPrepaidBalance`
  (`document`, `txt→md`),
  `ConversionQuotaEnforcementTest::testFreeUserVideoConversionReturns429InsufficientBalance`
  (`video`, `mp4→mkv`),
  `ConversionQuotaEnforcementTest::testGuestOverLightQuotaReturns429WithDailyMessage`
  (`document`, `txt→md`),
  `ConversionQuotaEnforcementTest::testGuestVideoStillReturns403AuthRequiredNot429`
  (`video`, `mp4→mkv`),
  `ConversionRetryDeleteControllerTest::testRetryCreatesNewConversion`
  (`image`, `jpg→png`),
  `ConversionTextInputControllerTest::testTextOnlySubmitReachesManagerMaterializedAndDispatchesToDocumentStream`
  (`document`, `md→html`),
  `ConversionTextInputControllerTest::testFileOnlySubmitStillWorksNoRegression`
  (`image`, `jpg→png`),
  `ConversionTextInputControllerTest::testFileInputPassesValidatedImageOptionsToWorkerMessage`
  (`image`, `jpg→webp`),
  `GuestAuthenticationTest::testGuestVideoConversionReturns403AuthRequired`
  (`video`, `mp4→mkv`),
  `GuestConvertCookieE2eTest::testNoCookieConvertUsesAnonymousIpOwnerWithoutCookie`
  (`image`, `jpg→txt`), and
  `GuestConvertCookieE2eTest::testValidGuestCookieReusesOwnerWithoutResetCookie`
  (`image`, `jpg→txt`). These tests own only their fixture rows and retain the
  existing no-fixture regression proving `503 worker_unavailable`; all other
  selectors, production admission, registry/catalog, worker registration,
  migrations, and real-worker paths remain outside this card.
- 2026-09-06: Existing cards CNV-60/CNV-71-03 are related delivered precedents,
  not duplicates; CNV-120 and generic worker-health cards do not own this exact
  fixture stack.
- 2026-09-06: Allocation-time wording is historical: `kanban-new.sh` initially
  allocated this card in `grooming/`; commit `31ba24a` subsequently moved the
  same card to `todo/`. The current lifecycle state is explicitly `todo`, and
  the historical allocation entry must not be read as a request to move it back.
- 2026-09-06: The no-row regression is intentionally fixture-free and service-level
  (`ConversionManagerWorkerAvailabilityFunctionalTest::testNoWorkerRowAgainstRealEmptyTableRejectsWithWorkerUnavailable`);
  controller/E2E tests separately provide HTTP mapping evidence. Cleanup is
  exact-set deletion by test-owned `instance_id`, followed by a post-suite
  no-owned-row assertion.

**Execution Log:**
- 2026-09-06 — Inventory across active, archived, frozen and grooming cards found
  no exact owner for the clean-stack durable capability fixture blocker.
- 2026-09-06 — EPIC-006 investigation evidence: `make test` runs no conversion
  workers/profiles and creates no durable `worker_capabilities`; 11 older
  controller/E2E tests expecting mocked conversions receive 503
  `worker_unavailable` after the intentional admission gate.
- 2026-09-06 — At allocation time CNV-141 was created via canonical
  `kanban-new.sh` in `grooming/`; commit `31ba24a` later moved the same card to
  `todo/`. This is historical allocation context, not current lifecycle state.
  No source, test, lifecycle, merge, push or deploy changes made.
- 2026-09-06 — Targeted `kanban-lint.sh` passed (1 card, 0 errors, 0 warnings);
  full board lint passed (69 cards, 0 errors, 0 warnings); `git diff --check`
  passed before commit.
- 2026-09-06 — Fresh `make TEST=1 test-php` reproduction: 1137 tests,
  6623 assertions, 11 failures, all with HTTP 503 `worker_unavailable`; the
  failures match the 11 selectors recorded above. No source or test files were
  changed.
- 2026-09-06 — After the grooming→todo move, targeted lint passed (1 card,
  0 errors, 0 warnings), full-board lint passed (69 cards, 0 errors,
  0 warnings), and staged `git diff --check` passed.
- 2026-09-06 — Canonically moved `todo → progress` for implementation. Added
  the shared test-only `WorkerCapabilityFixture`, exact-row cleanup and
  post-cleanup owned-prefix assertion; attached the approved 11 normal-queue
  selectors for `document`, `image`, and `video`. Targeted matrix passed:
  11 tests, 84 assertions. The fixture-free service regression
  `ConversionManagerWorkerAvailabilityFunctionalTest::testNoWorkerRowAgainstRealEmptyTableRejectsWithWorkerUnavailable`
  also passed: 1 test, 4 assertions.
- 2026-09-06 — Full gate `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-epic006-root-probe make test`
  reached `test-drift` and is blocked only by the already-owned CNV-140 stale
  `APP_VER=0.1.2` drift expectations; the tracked baseline is `0.2.0`.
  The two failures are `test_release_version_is_bumped_for_worker_rollout`
  and `test_ai_cuda_build_and_compose_use_local_app_ver_tags` (47 passed, 2
  failed). CNV-140, production env, and production/tests were not edited; the
  contemporaneous `progress`, not `ready`, state for CNV-141 is historical and
  superseded by the later completion move.
- 2026-09-06 — Restored dedicated AI fixture semantics: `addAi()` persists the
  prior `isAi=true` payload and full AI matrix, while `addNormal()` explicitly
  limits normal fixtures to document/image/video and rejects `ai`. AI tests
  assert the stored capability is AI-shaped; focused PHP tests pass (37 tests,
  170 assertions), no-worker regression passes (1 test, 4 assertions), CS,
  PHPStan (application and migrations), config, and diff checks pass.
- 2026-09-06 — APPROVE accepted on `epic/EPIC-006` across `c27b5bd` and `4a10cd9`: durable fixture ownership/cleanup and dedicated AI semantics were verified; focused PHP tests passed (37 tests, 170 assertions), the fixture-free no-worker regression passed (1 test, 4 assertions), and the full PHPUnit gate passed after CNV-140. Full `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-epic006-root-probe make test` passed after CNV-140, with build and lint evidence recorded on the integration gate. Evidence is sanitized; no credentials, tokens, raw request data, or generated artifacts are recorded. The contemporaneous `progress → ready`-only authorization is historical and superseded by the later explicit completion authorization; CNV-140 remained ready then, the parent remained untouched, and no merge, push, release, or deploy action was authorized.
- 2026-09-08 — Пользователь явно разрешил завершить все текущие карточки из `ready/`; это историческое разрешение оформило CNV-141 как `done` единственным переходом `ready → done`.

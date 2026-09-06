### CNV-142 — Package `workers.host_telemetry` in the worker-api image

**Criticality:** Blocking

**TAGS:**
- bug-fix
- worker
- docker
- release

**Description:**
Repair the worker-api image packaging contract so the published image contains
its minimal shared `workers.host_telemetry` module dependency.

**Problem:**
A production pull and recreate of `worker-api:latest` crash-loops with
`ModuleNotFoundError: No module named workers.host_telemetry` from
`workers/common/ws_client.py`, which imports `validate_host_name`. Read-only
inspection shows `docker/workers/api.Dockerfile` copies `workers/common` and
`workers/api` but not `workers/host_telemetry`; the host telemetry image copies
that module.

**Impact:**
The published worker-api image cannot start or register after rollout. This is
a release blocker for worker-api availability and makes a successful image pull
look deployable until the container enters its crash loop.

**Recommendation:**
- Make the worker-api Dockerfile ship exactly the minimal shared
  `workers/host_telemetry` module required by `workers/common/ws_client.py`;
  do not move or duplicate the module and do not broaden the image contents.
- Add a TDD static Dockerfile packaging regression to the existing worker-api
  operations test, proving the Dockerfile copies the module and that the
  resulting package satisfies the import dependency.
- Keep this card strictly to image packaging and its regression test: no
  config, environment, provider, Compose behavior, source repair outside the
  Dockerfile/test scope, or unrelated card changes.

**Acceptance Criteria:**
- The worker-api image build context/package contains the minimal
  `workers/host_telemetry` module needed by `workers/common/ws_client.py`.
- The targeted worker-api operations test fails for the current omission and
  passes after the packaging change; it remains a static Dockerfile/package
  contract test and does not require production credentials or providers.
- A clean build and recreated worker-api container start without the reported
  `ModuleNotFoundError`, register successfully, and remain healthy through the
  existing worker-api readiness/liveness verification.
- Rollout recovery is explicit: rebuild and publish the corrected worker-api
  image, pull the new published image on the affected host, recreate only the
  worker-api container, then verify image provenance, startup logs, readiness,
  registration, and absence of crash-loop restarts. Do not declare recovery
  from a cached image or a merely successful pull.
- No config, environment, provider, Compose behavior, unrelated source/module
  moves, or unrelated Kanban cards are changed; the missing package remains a
  release blocker until the build, recreate, and runtime verification pass.

**Open questions:**
- None for grooming; the implementation owner must use the existing worker-api
  operations test location and current release/recreate verification path.

**Decisions:**
- 2026-09-06: No existing active, grooming, ready, todo, freeze, or completed
  card was found for this exact worker-api `host_telemetry` packaging outage.
  `CNV-137` owns the host-resource telemetry feature contract, not this image
  packaging regression.
- 2026-09-06: Scope is limited to the worker-api Dockerfile's minimal shared
  module dependency, a static TDD packaging regression, and exact rollout
  recovery/verification. Config, env, provider, Compose behavior, unrelated
  source moves, and unrelated cards are out of scope.

**Dependencies:**
- The existing worker-api Dockerfile and worker-api operations test are the
  implementation surfaces; no feature or provider dependency is introduced.
- Rollout recovery depends on the existing worker-api image publication and
  host recreate procedure, but does not change that procedure.

**Execution Log:**
- 2026-09-06: Read-only inventory confirmed the import failure and Dockerfile
  omission. No source, image, config, Compose, or deployment files changed.
- Prompt evidence: model tier `standard`; token usage availability: unavailable;
  sanitized prompt summary: groom worker-api image packaging outage for missing
  shared host telemetry module with static regression and release-blocker
  rollout verification; Agent docs-kanban.
- 2026-09-06: targeted Kanban lint — 1 card checked, 0 errors, 0 warnings;
  full-board Kanban lint — 63 cards checked, 0 errors, 0 warnings; `git diff
  --check` passed before commit. No runtime/source/deploy verification was
  attempted because this change records the grooming card only.

### Backend публичного API

**Criticality:** High

**TAGS:**
- feature
- api
- authentication
- security
- privacy

**Description:**
Собрать единый последовательный поток публичного API: anonymous identity без
обязательных cookie/token, персональные API-токены, audit history и разделённая
OpenAPI-документация.

**Problem:**
Public API не имеет утверждённой anonymous identity без browser-cookie,
зарегистрированный пользователь не управляет API-токенами, история API-вызовов
отсутствует, а Swagger не разделён по аудиториям и доступам.

**Impact:**
Внешняя интеграция не получает безопасный предсказуемый контракт, а public/user/admin
документация расходится с фактическими auth, quota и privacy границами.

**Recommendation:**
Выполнять по порядку: сначала anonymous identity и ownership, затем token lifecycle,
потом API audit history и в финале OpenAPI, которая документирует завершённый
runtime-contract. Не смешивать worker/internal/admin token boundaries с личными
пользовательскими токенами.

**Acceptance Criteria:**
- Выполнены AC CNV-87, CNV-83, CNV-84 и CNV-86; endpoint visibility, quota,
  owner checks и retention согласованы между API, UI и документацией.
- Full quality gate проекта зелёный; проверены anonymous, guest, `ROLE_USER`,
  `ROLE_ADMIN`, personal-token, worker/internal и webhook сценарии.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- CNV-87 выполняется первой: public OpenAPI и audit нельзя считать корректными,
  пока не определён anonymous IP fallback.
- Public docs сохраняют `/api/doc` и `/api/doc.json`; private-user и private-admin
  используют отдельные URL. Worker/internal/webhook документируются только admin.

**Subtasks:**
- CNV-87 — anonymous public API identity по HMAC IP и 30-day retention
- CNV-83 — до трёх именованных персональных API-токенов
- CNV-84 — backend API audit contract
- CNV-86 — OpenAPI runtime areas
- CNV-109 — audit storage, endpoint и retention
- CNV-112 — anonymous identity security regression

**Integration checklist:**
- Прогнать auth/access matrix для анонима, гостя, user, admin и personal token.
- Проверить отсутствие secrets и raw IP в responses, OpenAPI, fixtures и логах.
- Выполнить полный quality gate проекта.

## EPIC Decision Digest (2026-09-05)

- **EPIC:** EPIC-006, branch `epic/EPIC-006`, baseline default branch `main` at
  `01dbf46e8bbe869f985566163ebf7fd81a6469a8` (clean).
- **Approved children and canonical order:** `CNV-87 → CNV-83 → CNV-84 → CNV-109 → CNV-86 → CNV-112`.
- **Dependencies:** CNV-87 establishes anonymous identity and 30-day cleanup; CNV-83
  consumes its ownership precedence; CNV-84 consumes token authentication and defines the
  redacted audit event; CNV-109 consumes that event for storage, history, and 90-day cleanup;
  CNV-86 consumes the completed runtime contracts and exposes the separated areas; CNV-112
  independently verifies CNV-87 privacy behavior. The ROADMAP confirms this sequence.
- **Acceptance:** all six child ACs pass; anonymous/guest/`ROLE_USER`/`ROLE_ADMIN`, personal-token,
  worker/internal, and webhook boundaries agree across runtime, quota, ownership, retention,
  and OpenAPI; the project full quality gate is green; no secret, raw IP, or plaintext token is
  present in responses, specs, fixtures, persistence, exception text, or logs.
- **Authorization boundary:** user-approved autonomous-pool execution at the **standard** model
  tier for this EPIC and these six children only. Children stop at `ready`; future release/deploy,
  push, and separate merge/finalization authorization are excluded.
- **Parked risks:** proxy trust and forwarded-header spoofing; anonymous NAT/VPN correlation and
  30-day cleanup; personal-token plaintext/allowlist leakage; audit redaction/storage drift;
  OpenAPI exposure drift. No child implementation has started.

## Dependency / Acceptance / Evidence Matrix

| Child | Predecessor | Zone and allowed paths | Scoped gate | Integration boundary / evidence |
|---|---|---|---|---|
| CNV-87 | None | backend/security; `app-symfony/src/`, `config/`, `migrations/`, `tests/` | `make TEST=1 test-php-unit` with identity/proxy/retention filter | JWT, personal-token, valid `guest_id` precedence; trusted Nginx proxy; 30-day cleanup; tests prove stable same-IP identity, owner isolation, and no raw IP/secret |
| CNV-83 | CNV-87 | backend/security; `app-symfony/src/`, `config/`, `migrations/`, `tests/` | `make TEST=1 test-php-unit` with token issuance/revoke/allowlist filter | Personal bearer feeds CNV-84 and conversion/quota/history/download/preview only; tests prove max-three, owner isolation, revoke, guest denial, and no plaintext |
| CNV-84 | CNV-83 | security contract; `app-symfony/src/`, `tests/`, contract docs only | `make TEST=1 test-php-unit` with audit contract/redaction filter | Validated token-only success/error event is the sole CNV-109 input; tests prove normalized route and allowlisted fields with body/header/file/IP/UA rejection |
| CNV-109 | CNV-84 | backend/database; `app-symfony/src/`, `config/`, `migrations/`, `tests/` | `make TEST=1 test-php-unit` with audit persistence/history/retention filter | Append-only owner-scoped private-user history; tests prove migration/index, pagination, isolation, empty state, 90-day boundary, and no update/delete or sensitive fields |
| CNV-86 | CNV-109 (and CNV-84) | OpenAPI runtime; `app-symfony/config/`, `src/`, `tests/` | `make TEST=1 test-php-unit` with OpenAPI access/spec filter | `public`, `private_user`, `private_admin` UI/JSON routes plus legacy public aliases; tests prove role access and exclusion of admin/worker/internal/webhook from public/user specs |
| CNV-112 | CNV-87 | privacy/security tests and user docs; `app-symfony/tests/`, `docs/` | `make TEST=1 test-php-unit` plus documentation/privacy check | Independent regression evidence for precedence, spoof resistance, no leakage, current-operation-only scope, and 30-day retention; must not alter CNV-87 algorithm/config |

Execution is sequential; no child may start before its predecessor is `ready`. Matrix evidence is
recorded on each child handoff and the EPIC integration gate requires a clean restart/data refresh,
full quality gate, and review of the baseline-to-head diff. No merge, push, release, or deploy is
performed by this preparation.

## EPIC Finalization Evidence (2026-09-06)

- **APPROVE:** The six declared children `CNV-87 → CNV-83 → CNV-84 → CNV-109 → CNV-86 → CNV-112` have accepted implementation and independent-review evidence on `epic/EPIC-006`; finalization is limited to this parent and those six children. `CNV-140` and `CNV-141` are accepted release-blocker cards but are not declared EPIC children and remain in `ready/`.
- **Integration gate:** The latest evidence after the CNV-140/CNV-141 repairs records `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-epic006-root-probe make test` passing, `make build` passing for all configured images, targeted/full Kanban lint at 0 errors and 0 warnings, config checks passing, and working/staged `git diff --check` passing. This evidence is recorded on CNV-140 and CNV-141; no expensive gate was rerun because no implementation changed after that verified gate.
- **Review and scope:** Independent reviews are recorded as APPROVE for all six declared children. Acceptance covers anonymous/guest/`ROLE_USER`/`ROLE_ADMIN`, personal-token, worker/internal, webhook, quota, ownership, retention, audit redaction, and separated OpenAPI boundaries. Evidence is sanitized: no credentials, tokens, raw IPs, headers, bodies, file contents, or generated artifacts are recorded.
- **Lifecycle authorization:** User authorization is now explicit for this finalization: move the parent and exactly the six declared children to `done/` through the canonical approved helper. Do not move `CNV-140` or `CNV-141`; leave both `ready/`. Merge, push, release, and deploy are separate subsequent actions and are not performed in this finalization.

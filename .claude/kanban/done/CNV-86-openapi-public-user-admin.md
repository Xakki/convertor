### CNV-86 — Backend: разделение OpenAPI runtime-areas и доступа

**Criticality:** High

**TAGS:**
- backend
- security
- api
- openapi
- nelmio

**Description:**
Backend-разработчик реализует отдельные Nelmio OpenAPI areas и runtime access control:
`public`, `private_user`, `private_admin`, с раздельными Swagger UI/JSON-маршрутами.
Карточка не проводит редакционный аудит описаний и examples.

**Problem:**
Единая public-доступная спецификация смешивает public, user и admin операции, поэтому
видимость документации не соответствует реальным ролям и API-границам.

**Impact:**
Публичная спека раскрывает административную поверхность, а интегратор не может
надёжно определить допустимую аутентификацию и аудиторию endpoint.

**Recommendation:**
Настроить named areas и `#[Areas]` на actions, отдельные UI/JSON routes и
`access_control` до общего правила `^/api`. Сохранить `/api/doc` и `/api/doc.json` как
public alias; исключить worker/internal/webhook из public и private-user areas.

**Acceptance Criteria:**
- Созданы `public`, `private_user`, `private_admin` areas и отдельные UI/JSON routes;
  legacy `/api/doc` и `/api/doc.json` остаются public alias.
- Public UI/JSON доступны анониму и гостю; private-user недоступны анониму/гостю;
  private-admin недоступны `ROLE_USER`; `ROLE_ADMIN` может открыть private-user route.
- Public/user specs не содержат admin, worker, internal и Telegram webhook operations;
  private-admin содержит административные и служебные contracts отдельно.
- Action-area mapping отражает фактические `PUBLIC_ACCESS`, `ROLE_GUEST`, `ROLE_USER` и
  `ROLE_ADMIN` границы, включая bearer allowlist после CNV-83 и audit API после CNV-109.
- Functional tests зелёные для доступа к UI/JSON и состава JSON-спек без секретов.

**Decisions:**
- **Владелец:** backend/security-разработчик.
- **Зависимости:** CNV-84 обязателен по цепочке `CNV-87 → CNV-83 → CNV-84 → CNV-86`;
  CNV-109 обязателен для включения audit endpoint в private-user spec.
- Nelmio v4.38.7 поддерживает named areas и `#[Areas]` на class/method.
- Telegram webhook, worker API и internal gateway API присутствуют только в
  private-admin спецификации.
- Редакционный OpenAPI-аудит, examples и проверка документационных утверждений
  принадлежат CNV-111.

**2026-09-05 — CNV86 implementation evidence:**
- Moved `todo → progress` and configured named Nelmio areas `public`, `private_user`,
  and `private_admin`, each using explicit `Areas` mappings. Legacy `/api/doc` and
  `/api/doc.json` remain public aliases; user/admin UI and JSON routes are
  `/api/user/doc[.json]` and `/api/admin/doc[.json]`.
- Added access-control ordering: public aliases are anonymous, private-user docs
  require `ROLE_USER`, and private-admin docs require `ROLE_ADMIN`. Public and
  private-user specs exclude admin, worker, internal, and Telegram webhook routes;
  private-admin contains those service contracts and excludes personal audit history.
- Added OpenAPI annotations for the private-user audit history operation, including
  personal-token-only semantics, cursor/limit parameters, owner-scoped response
  envelope, redacted fields, and 400/401 responses. No storage, retention, or auth
  semantics were changed.
- Focused gate: `make TEST=1 test-php FILTER='OpenApiAreasTest|ApiAuditControllerTest|ConversionOpenApiTest'`
  passed (11 tests, 82 assertions). Unit gate passed (651 tests, 4,011 assertions;
  6 deprecations, 11 notices). `make TEST=1 cs-check`, `make TEST=1 phpstan`, and
  `git diff --check` passed. Full functional `test-php` remains unavailable for
  worker-backed conversion cases because the worker service is unavailable; this
  is an existing environment caveat and not attributed to CNV-86.

**2026-09-05 — CNV86 independent-review preparation:**
- Narrowed the legacy `default` Nelmio area back to its prior internal/worker exclusion;
  public/user/admin areas remain attribute-gated. Corrected audit history security
  metadata to `PersonalToken` rather than JWT and removed unrelated controller-finality churn.
- Added functional assertions for the personal-token scheme and `ROLE_USER` denial of
  private-admin documentation. Focused gate passed: 13 tests, 101 assertions.
- Final gates: `make TEST=1 cs` (0 files fixed), focused `make TEST=1 test-php
  FILTER='OpenApiAreasTest|ApiAuditControllerTest|ConversionOpenApiTest'` (13 tests,
  101 assertions), `make TEST=1 phpstan` (application and migrations),
  `make TEST=1 config-check`, and `git diff --check` passed. No secret values were added;
  internal schemes remain metadata for private-admin operations while legacy default keeps
  its prior worker/internal path exclusion and no attribute filtering. No merge, push,
  deploy, or release.

**2026-09-05 — CNV86 final auth-metadata repair (17:14 MSK):**
- Added explicit operation-level `security: []` to anonymous/guest runtime endpoints:
  `/api/v1/examples`, its result/source file operations, and `/api/v1/quota`. Runtime
  access control and area/default composition were not changed.
- Extended the dumped public-spec functional assertions for exact empty security arrays;
  existing protected-operation assertions remain exact (`Bearer` retry and `PersonalToken`
  audit history). Public dump inspection returned `[]` for all five guest-compatible
  operations, including the pre-existing anonymous `/api/v1/convert` operation.
- Focused gate: `make TEST=1 test-php FILTER='OpenApiAreasTest|ApiAuditControllerTest|ConversionOpenApiTest'`
  passed (13 tests, 106 assertions). `make TEST=1 phpstan`, `make TEST=1 cs-check`,
  `make TEST=1 config-check`, and `git diff --check` passed. No generated source artifact
  is committed; dump was verified from the live container. No merge, push, deploy, or
  release; card remains ready for final review.

**2026-09-05 — CNV86 final reviewer acceptance evidence:**
- **APPROVE:** independent final review accepts CNV-86 on branch `epic/EPIC-006` at
  commit `7840e36031b6c918c4606990b1583f7a967e701b`. The reviewed chain is
  `4911504e60a8c4067ccf15c7d0dfd68767bbef51 → efca74491acec5884bfaf76d2341b2f33f36896c →
  7840e36031b6c918c4606990b1583f7a967e701b`; scope remains limited to CNV-86 OpenAPI
  areas, access metadata, tests, and this card.
- **Gates:** focused OpenAPI/auth test gate passed (13 tests, 106 assertions); full unit
  gate passed (651 tests, 4,011 assertions); `make TEST=1 cs-check`, `make TEST=1 phpstan`,
  `make TEST=1 config-check`, targeted Kanban lint, and `git diff --check` passed. No
  secrets, credentials, tokens, raw IPs, or generated source artifacts are recorded.
- **Areas/access:** `public`, `private_user`, and `private_admin` mappings and their UI/JSON
  routes are separated; `/api/doc` and `/api/doc.json` remain public aliases. Anonymous/
  guest access is documented with exact `security: []`, private-user requires `ROLE_USER`,
  and private-admin requires `ROLE_ADMIN`; public and private-user specs exclude admin,
  worker, internal, and Telegram webhook operations.
- **Cache/runtime verification:** public-spec assertions were checked against the live
  container dump after the final metadata repair; all five guest-compatible operations
  returned exact empty security arrays, while protected Bearer and PersonalToken operations
  retained their required security metadata. No generated dump or cache output is committed.
- CNV-86 remains `ready`; CNV-112 remains in `todo/` as the next child. No `done`, merge,
  push, release, or deploy action is included.

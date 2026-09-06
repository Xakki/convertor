### CNV-83 — Backend: персональные bearer-токены public API

**Criticality:** High

**TAGS:**
- backend
- security
- api
- authentication

**Description:**
Backend-разработчик создаёт доменную модель персональных именованных API-токенов,
выпуск/отзыв и bearer-аутентификацию только для утверждённой пользовательской
API-поверхности. Карточка не реализует dashboard UI и не создаёт audit storage.

**Problem:**
Зарегистрированный пользователь не может безопасно выдать или отозвать программный
доступ. Внутренний `WORKER_API_TOKEN` нельзя использовать как пользовательский секрет.

**Impact:**
Внешние интеграции не получают безопасный доступ, а компрометированный ключ нельзя
немедленно отозвать без затрагивания worker-инфраструктуры.

**Recommendation:**
Добавить отдельную сущность и миграцию с opaque random bearer, безопасным prefix, label
и `last_used_at`; хранить только невосстановимый verifier. Реализовать owner-scoped API
выпуска, metadata и отзыва, а также authenticator с явной route allowlist.

**Acceptance Criteria:**
- `ROLE_USER` может выпустить, перечислить metadata и отозвать только собственные токены;
  гость/аноним не имеют доступа к этим backend endpoints.
- Plaintext секрет возвращается исключительно при выпуске и не сохраняется в БД, логах,
  exception text или последующих JSON-ответах.
- Пользователь имеет максимум три активных именованных токена независимо от плана;
  четвёртый выпуск возвращает предсказуемую ошибку.
- Отозванный, malformed и неизвестный bearer не аутентифицирует запрос; JWT coexistence
  сохраняется.
- Bearer действует только для conversion, quota, history, download и preview allowlist;
  `/api/v1/admin`, `/api/v1/worker`, `/api/v1/internal` и auth-management endpoints
  недоступны.
- Через bearer сохраняются owner checks, quota, billing и действующие user/IP rate limits.
- Профильные unit/functional tests зелёные для issuance, revoke, owner isolation,
  guest denial, allowlist и отсутствия plaintext.

**Decisions:**
- **Владелец:** backend/security-разработчик.
- **Зависимость:** CNV-87; следующий обязательный шаг — CNV-84.
- `WORKER_API_TOKEN` остаётся внутренним fail-closed секретом и не меняется.
- TTL в MVP не обязателен; ротация — выпуск нового токена и отзыв старого.
- Dashboard UI выдачи и отзыва принадлежит CNV-108; запись и выдача audit history не
  принадлежат этой карточке.

**2026-09-05 — CNV-83 judgment repair evidence:**
- Consolidated the unmerged CNV-83 schema into the single retained
  `Version20260905110000` migration; removed the branch-local
  `Version20260905120000` prefix-index migration. `token_prefix` is display metadata
  only, with no unique constraint or index; the final migration uses the
  `datetime_immutable` Doctrine column comments required by the entity mapping.
- Removed prefix-collision retry/catch machinery and its tests. Secure issuance still
  uses 32 bytes of random entropy, stores only the full SHA-256 verifier, keeps the
  three-token pessimistic-lock cap, owner-scoped auth/revoke behavior, constant-time
  verifier matching, and best-effort `last_used_at` updates.
- The isolated test lifecycle was attempted only via `make TEST=1 test-down` and
  `make TEST=1 test-up`. Recreate was blocked before startup because this checkout is
  on `/home` while the sanctioned host-root probe requires the probe directory to be
  on `/`; no dev/prod stack or deployed path was modified. Read-only PHP syntax checks,
  the focused token unit test (3 tests/12 assertions; 2 existing PHPUnit notices),
  `make phpstan`, `make cs-check`, `git diff --check`, and the one-migration source
  assertion passed. Test-stack migration application/schema validation, config checks,
  and the full relevant PHPUnit run remain blocked by that prerequisite.

**2026-09-05 — CNV-83 persistence regression evidence:**
- Added `PersonalApiTokenPersistenceTest`, a real `KernelTestCase` regression that
  persists one user and exactly three active tokens, invokes the container service
  through the real Doctrine repository/EntityManager transaction path, asserts
  `ConflictHttpException` status 409 for the fourth issuance, and re-reads the
  database to prove no fourth active token persisted.
- With an isolated test stack recreated using
  `HOST_ROOT_PROBE_DIR=/var/tmp/convertor-host-root-probe`, migration status was
  current at `Version20260905110000`; focused persistence test passed (1 test,
  7 assertions), token suite passed (4 tests, 19 assertions) with the two
  existing PHPUnit notices. `cs-check` and `git diff --check` passed. The
  sanctioned `make TEST=1 phpstan` invocation hit its configured 256M child
  memory limit; no PHPStan result is claimed from that incomplete run.

**2026-09-05 — CNV-83 accepted final reviewer evidence:**
- Third-party judgment authorized acceptance of the complete CNV-83 commit chain:
  `df3dccd`, `c8c040f`, `aa3e8b2`, and `73e0266`; this card remains `ready` and
  sequential CNV-84 remains `todo`.
- Fresh isolated test-stack migration status was current at
  `Version20260905110000` with no pending migration. Token unit coverage,
  persistence-cap regression, and the full relevant unit gate passed; the
  persistence test proved the fourth issuance returns HTTP 409 and does not
  create a fourth active token.
- PHPStan passed when rerun with a 1G child memory limit. The default sanctioned
  environment remains limited by its configured 256M child limit; that resource
  limitation is environmental, not a code failure. `cs-check` and
  `git diff --check` also passed.
- Shared-stack functional verification remains conditional on worker-dependent
  services being available; this caveat does not invalidate the isolated
  migration, persistence, unit, or static-analysis evidence.
- Evidence is sanitized: no secrets, bearer values, prompts, or sensitive
  runtime content are recorded.

**2026-09-05 — CNV-83 implementation evidence:**
- Добавлены отдельные `PersonalApiToken`/repository/service и миграция
  `Version20260905110000`: хранится только SHA-256 verifier, opaque `cnv_` bearer
  возвращается только при выпуске; metadata не содержит plaintext.
- Добавлены POST/GET/DELETE `/api/v1/auth/tokens` для `ROLE_USER`; JWT-only
  management firewall исключает guest и personal bearer. Personal authenticator
  принимает только allowlist conversion/quota путей, сохраняя worker/internal/admin
  границы и существующий JWT flow; успешная аутентификация обновляет `last_used_at`.
- Покрыты выпуск, verifier/redaction и revoke state unit-тестами. Миграция применена
  к изолированной test-БД; `lint:container`, route discovery, `make TEST=1 test-php-unit`
  (627 tests, 3947 assertions), `make TEST=1 phpstan`, `make TEST=1 cs-check` и
  `git diff --check` зелёные. Существующие PHPUnit deprecations: 6.

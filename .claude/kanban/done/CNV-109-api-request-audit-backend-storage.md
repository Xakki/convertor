### CNV-109 — Backend: append-only storage, history endpoint и retention API-аудита

**Criticality:** High

**TAGS:**
- backend
- api
- audit
- database
- retention

**Description:**
Backend-разработчик реализует append-only хранилище безопасных audit events по контракту
CNV-84, owner-scoped paginated endpoint и автоматическую очистку записей старше 90 дней.

**Problem:**
После определения безопасного event contract отсутствуют долговечное storage, выдача
журнала владельцу и исполнимый retention lifecycle.

**Impact:**
Пользователь и последующие UI/docs не смогут получить историю token-вызовов, а audit
данные будут либо потеряны, либо храниться бессрочно.

**Recommendation:**
Добавить migration/entity/repository с индексом `(owner_id, created_at)`, append-only
writer, newest-first cursor/page pagination и отдельный private-user endpoint. Cleanup
удаляет audit records старше 90 дней; storage принимает только event CNV-84.

**Acceptance Criteria:**
- Migration создаёт отдельное от `Conversion` append-only audit storage с индексом
  owner+created_at и без plaintext токенов, headers, bodies, файлов, IP и UA.
- Writer сохраняет только validated CNV-84 event и не предоставляет update/delete API
  для audit record.
- Private-user history endpoint возвращает только записи текущего owner newest-first с
  детерминированной пагинацией и корректным empty state.
- Гость/аноним не могут вызвать endpoint; owner isolation доказана functional tests.
- Scheduled cleanup удаляет records старше 90 дней и имеет покрытие boundary case.
- Backend tests зелёные для persistence, pagination, isolation, empty state, retention
  и запрета неразрешённых полей.

**Decisions:**
- **Владелец:** backend-разработчик.
- **Зависимость:** CNV-84 задаёт единственный audit-event/redaction contract.
- Эта карточка не изменяет token authenticator CNV-83, OpenAPI areas CNV-86 и frontend
  history CNV-110.
- Фильтры и поиск не входят в MVP.

**2026-09-05 — CNV109 implementation evidence:**
- Implemented append-only `api_audit_records` storage with owner+created_at indexes,
  CNV-84 DTO-only writer, owner-isolated cursor history endpoint, and strict 90-day purge.
- Personal-token response listener records successful and completed error responses using
  allowlisted DTO fields; JWT, guest, worker, internal, raw headers, bodies, IP and UA are
  not persisted. Daily purge is registered in `App\\Schedule`.
- Gates: `make TEST=1 migrate` applied migration successfully; `make TEST=1 cs-check`
  passed; `make TEST=1 phpstan` application and migration analyses passed; `make TEST=1
  test-php-unit` passed (643 tests, 3,989 assertions; 6 deprecations, 4 notices).

**2026-09-05 — CNV109 real-kernel response persistence evidence:**
- Added a WebTestCase that issues a real personal token, calls authenticated
  `/api/v1/quota` (200) and `/api/v1/convert/{id}/status` (404), then reads
  `api_audit_records` through the repository after the real kernel response event.
- The test asserts exactly two owner-scoped records, redacted token metadata,
  allowlisted method/route/status, non-negative duration, UTC timestamps within
  the request window, and that authenticated `/api/v1/audit/history` does not
  self-audit.
- API firewall wiring now gives the personal-token authenticator precedence over
  JWT parsing for `cnv_` credentials while preserving JWT and guest fallback.
- Gates: focused real-kernel test passed (1 test, 29 assertions); full
  `ApiAuditControllerTest` passed (6 tests, 48 assertions); `cs-check` and both
  PHPStan configurations passed. Migration check remains the existing successful
  evidence above.

## Accepted evidence — EPIC-006 wrap-up (2026-09-05)

- **Independent review:** APPROVE. CNV-109 acceptance evidence is complete for append-only audit storage, owner-scoped history, redaction, retention, and real-kernel response persistence on branch `epic/EPIC-006`.
- **Implementation commits:** `960f36c4d8611b5b0578d26bb158481855992ffe` (personal-token audit history persistence); `4b485832d88a5de25dcceb728e36551ccec06571` (personal-token precedence and real-kernel audit E2E wiring).
- **Gates:** migration application succeeded; focused real-kernel test passed (1 test, 29 assertions); `ApiAuditControllerTest` passed (6 tests, 48 assertions); `make TEST=1 cs-check` passed; application and migration PHPStan analyses passed; prior unit gate passed (643 tests, 3,989 assertions; 6 deprecations, 4 notices).
- **Real E2E:** authenticated personal-token requests produced exactly two owner-scoped audit records with redacted metadata and allowlisted fields; authenticated history did not self-audit. The test verified success and completed-error responses, deterministic timestamps, and non-negative duration.
- **Known functional-environment caveat outside CNV-109:** worker-backed functional checks were unavailable because the required worker service was unavailable. This does not change the CNV-109 real-kernel audit result and is not attributed to CNV-109.
- **Security boundary:** evidence is sanitized; no tokens, credentials, headers, bodies, file contents, IP addresses, user agents, or other secrets are recorded.
- **Lifecycle boundary:** CNV-109 is moved `progress → ready` only. CNV-86 remains in `todo/`; no `done`, merge, push, release, or deploy action is included.

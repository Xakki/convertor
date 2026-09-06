### CNV-84 — Security: контракт безопасного аудита API-токенов

**Criticality:** High

**TAGS:**
- security
- api
- audit
- privacy

**Description:**
Security-специалист фиксирует и внедряет единый контракт audit event для вызовов,
аутентифицированных персональным API-токеном: допустимые поля, маскирование токена,
нормализацию route и запрет чувствительных данных на границе записи. Карточка не
создаёт таблицу, retention job или history endpoint.

**Problem:**
`Conversion` хранит бизнес-результат и не может быть транспортным audit-log. Без
центрального redaction-контракта будущая запись журнала рискует сохранить bearer,
Authorization header, body или файл.

**Impact:**
Audit storage и UI не имеют безопасного, согласованного формата; утечка секретов в
журнале сделает отзыв токена недостаточной защитой.

**Recommendation:**
Определить typed audit-event contract для завершённых token-authenticated HTTP-ответов,
включая ошибки: `method`, нормализованный route, `status`, `duration`, label/mask токена
и при наличии conversion ID. Встроить deny-by-default redaction на границе формирования
события и передать контракт CNV-109.

**Acceptance Criteria:**
- Контракт принимает только token-authenticated завершённые HTTP-вызовы, включая
  завершившиеся HTTP-ошибки; JWT/web и anonymous flow не создают эти события.
- Событие содержит только утверждённые method, normalized route, status, duration,
  token label/mask и optional conversion ID.
- Plaintext токен, полный Authorization header, request/response body, файлы, raw IP и
  User-Agent отклоняются или не сериализуются на границе контракта.
- Route нормализуется до стабильного шаблона без query, path-secret и пользовательских
  значений; события не используют `Conversion` как хранилище.
- Unit tests подтверждают allowlisted fields, redaction, token-only scope и обработку
  success/error response.
- Передаваемый CNV-109 контракт достаточен для append-only storage и owner-scoped
  endpoint без добавления чувствительных полей.

**Decisions:**
- **Владелец:** security-разработчик.
- **Зависимость:** CNV-83; этот security-contract является шагом audit в цепочке
  `CNV-87 → CNV-83 → CNV-84 → CNV-86`.
- Retention, migration, append-only storage и paginated endpoint принадлежат CNV-109.
- Frontend-вкладки принадлежат CNV-110; OpenAPI реализация и документационный аудит не
  входят в эту карточку.
- Срок хранения audit records — 90 дней; IP и UA не хранятся.

**2026-09-05 — scope-correction evidence:**
- Ошибочно добавленные CNV-109 migration/entity/repository/writer/listener,
  history route/controller, purge command, authenticator wiring и persistence
  test удалены отдельным corrective commit; история и retention остаются CNV-109.
- Карточка возвращена в `todo`; сохранён только typed CNV-84 contract и его unit
  test. Contract serializes only the allowlisted fields, rejects query-bearing or
  non-normalized routes and requires the masked token form; no DB/table/job/route
  remains in CNV-84.
- Проверки correction evidence: refreshed test stack reports 30 available migrations,
  0 unavailable and 0 new; `/api/v1/audit/history` отсутствует в route table;
  scheduler содержит только file/anonymous-identity/worker-capability cleanup.
  `make TEST=1 test-php-unit` (633 tests, 3974 assertions), `make TEST=1 cs-check`
  и `make TEST=1 phpstan` зелёные; test DB reset/recreated only through Make.

**2026-09-05 — CNV84 repair evidence:**
- Card moved from `todo/` to `progress/` while the contract is repaired; CNV-109
  storage/listener/endpoint/migration work remains explicitly out of scope.
- `ApiAuditEvent` now requires typed `ApiAuditTokenMetadata` and accepts only
  final HTTP statuses `200..599`; informational `1xx` responses are rejected,
  while success and client/server error responses are covered by unit tests.
- `ApiAuditTokenMetadata` requires the `PERSONAL_TOKEN` identity type, a safe
  label and exactly `cnv_********`; JWT, guest, worker, internal, missing,
  bearer/header-like and raw-token metadata are rejected before event creation.
  Serialization remains limited to the existing allowlisted fields; route
  normalization and query/path-secret redaction remain enforced.

**2026-09-05 — final reviewer readiness evidence:**
+- **APPROVE:** reviewer confirms CNV-84 acceptance gates on branch `epic/EPIC-006`
  at commit `47a4605e5d2271f1d2ab0beb8090a64ef36586da`: typed personal-token-only
  completed-response contract, success/error coverage, stable route normalization,
  allowlisted serialization, and rejection of sensitive identity/route data.
+- **APPROVE:** `make TEST=1 test-php-unit` — 642 tests, 3,986 assertions, exit 0;
  `make TEST=1 cs-check` — 0 files requiring fixes; `make TEST=1 phpstan` —
  application and migration analyses report no errors; targeted Kanban lint —
  1 card checked, 0 errors, 0 warnings.
+- Corrective scope removal is accepted and remains explicit: premature CNV-109
  migration/entity/repository/writer/listener, history route/controller, purge
  command, authenticator wiring, and persistence test were removed; CNV-109 stays
  in `todo/` and retains storage, history, and retention ownership. No CNV-109
  implementation is included in this handoff.
+- CNV-84 is ready for the next sequential child only. This is a `progress → ready`
  handoff; the child is not moved to `done/`, and no merge, push, release, or
  deploy is authorized.

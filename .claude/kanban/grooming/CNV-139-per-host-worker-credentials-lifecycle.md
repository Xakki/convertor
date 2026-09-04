### Жизненный цикл per-host credential и admin control plane для collectors/workers

**Criticality:** High

**TAGS:**
- feature
- admin
- security
- remote-workers
- credentials
- lifecycle

**Description:**
Спроектировать и реализовать отдельный per-host credential lifecycle и admin
control plane для remote collector/worker credentials. Карточка владеет host-bound
verifier storage, безопасным enrollment/activation, доставкой секрета только
collector'у, admin list/detail и операциями issue/rotate/revoke/quarantine,
audit/revocation semantics, host binding/rate limits, installer/deployment
integration и negative security tests.

Это не telemetry-карточка: `CNV-137` временно использует общий
`WORKER_API_TOKEN`, а этот scope устраняет отложенное ограничение отдельным
решением и не приписывает CNV-137 уже несуществующую per-host изоляцию. Это не
command-control: HTTP command contract принадлежит `CNV-134`, WS-доставка и
worker-side pause/start/auto-update — `CNV-135`, UI этих команд — `CNV-136`.

**Problem:**
Сейчас `WORKER_API_TOKEN` общий для gateway и множества workers/collectors.
Поэтому нельзя отозвать или заменить credential только для одного host, безопасно
завершить потерянный enrollment, изолировать скомпрометированный host или
доказать, что telemetry и будущий control channel пришли от заявленного host.
Admin workers не имеет отдельной поверхности для управления credential state, а
installer не имеет безопасного per-host activation handoff.

**Impact:**
Компрометация одного host может сохранить доступ до ручной общей ротации и
затронуть соседние hosts. Повторное использование enrollment secret, подмена
`HOST_NAME`, утечка секрета в logs/installer output или слишком широкий admin
endpoint могут выдать credential не тому collector'у, открыть telemetry/control
маршрут либо сделать revoke только косметическим.

**Recommendation:**
- Ввести отдельную credential entity для точного canonical `HOST_NAME` (и
  выбранной owner/installation identity), с opaque ID, verifier/hash вместо
  plaintext, state `pending|active|revoked|quarantined|expired`, issued/activated/
  revoked timestamps, last-used/last-seen metadata, version/epoch и reason.
  Уникальность, replacement fencing и запрет возврата plaintext после issue
  должны быть durable и переживать restart.
- Сделать enrollment одноразовым и короткоживущим: admin issue создаёт pending
  credential и выдаёт секрет ровно один раз через утверждённый защищённый
  handoff; activation требует proof of possession плюс exact host binding.
  Повтор, просрочка, неправильный host, уже использованный enrollment или
  mismatch identity должны завершаться fail-closed без активации. Не фиксировать
  wire-метод, TTL или внешний secret manager до решений из Open questions.
- Разделить collector-only secret delivery от worker command/control и internal
  Symfony credentials. Collector получает только credential для разрешённого
  outbound telemetry channel (и иных явно утверждённых collector endpoints),
  никогда `GATEWAY_INTERNAL_TOKEN`, admin JWT, DB/KeyDB/S3 credential или общий
  command credential. Installer/deployment получает secret без попадания в
  public CLI/gist, образ, репозиторий, rendered logs или произвольный worker env;
  redaction проверяется контрактными тестами.
- Gateway проверяет verifier, exact `HOST_NAME`, credential state/epoch, audience/
  channel, body size, freshness, replay/nonce policy и per-credential/per-host
  rate/concurrency limits до relay. Revoke/quarantine должны немедленно
  запрещать новые requests согласно выбранному bounded cache/revalidation
  contract; in-flight semantics, fail-closed behavior при storage/cache outage и
  propagation deadline фиксируются решением, а не угадываются реализацией.
- Добавить admin-only list/detail с redacted metadata: host, state, credential
  ID, epoch, created/activated/revoked time, last seen, reason и safe health
  summary; plaintext/verifier, enrollment secret, bearer headers и private
  endpoints не возвращаются. Операции issue, rotate, revoke и quarantine должны
  требовать explicit actor/reason, exact target и idempotency/replay semantics,
  а rotate — atomic replacement с fencing старого credential.
- Записывать append-only audit для allow/deny, issue, activation, rotate, revoke,
  quarantine, failed enrollment и rejected binding: actor, exact host, credential
  ID/epoch, correlation ID, reason, result и timestamps без secrets/payload.
  Определить retention, immutable event semantics, actor authorization и
  distinction между revoke (permanent invalidation) и quarantine (операторский
  containment с отдельным release path).
- Интегрировать installer/deployment: host-specific enrollment/activation,
  atomic local secret-file replacement с restrictive permissions, restart/reload
  boundary, rollback/failure behavior и provenance без публикации секрета.
  Existing bootstrap/update mechanics `CNV-32` остаются базой и не превращаются
  в admin command path; runtime/deploy implementation выполняется только после
  grooming.

**Acceptance Criteria:**
- Хранилище не содержит plaintext credential/enrollment secret; verifier lookup
  exact-match работает по canonical `HOST_NAME` + credential ID/epoch, старый
  revoked/quarantined/expired credential не проходит даже после restart, а
  replacement не принимает stale epoch.
- Enrollment tests подтверждают single-use/expiry, proof-of-possession, exact
  host binding, identity replacement fencing, replay rejection, missing/invalid
  host rejection и отсутствие activation при storage/cache failure согласно
  принятому fail-closed контракту.
- Collector-only delivery не раскрывает `GATEWAY_INTERNAL_TOKEN`, admin JWT,
  DB/KeyDB/S3 credential, plaintext credential или secret в response, image,
  public installer output, logs, metrics, audit, error и произвольном worker
  environment; negative tests пытаются получить каждый запрещённый маршрут.
- Gateway rejects wrong-host, wrong-channel/audience, revoked, quarantined,
  expired, stale-epoch, replayed and malformed requests before relay. Tests
  покрывают per-credential/per-host rate and body/freshness limits, cache/storage
  outage behavior, concurrent rotate/revoke and no cross-host acceptance.
- `ROLE_ADMIN` и выбранные granular permissions enforced server-side: non-admin,
  unauthorized admin и cross-tenant/cross-scope actor получают deterministic
  denial без state/audit leakage; list/detail возвращают только redacted metadata.
- Issue/rotate/revoke/quarantine endpoints являются idempotent в утверждённом
  actor/target scope: retry не создаёт второй effect, conflicting reuse получает
  deterministic conflict, rotate атомарно fencing'ит predecessor, revoke
  необратим, а quarantine не маскируется под revoke.
- Audit содержит allow/deny и каждую lifecycle transition с actor, exact host,
  credential ID/epoch, reason и correlation ID; negative tests подтверждают
  отсутствие secret, bearer, verifier и raw request payload. Retention и cleanup
  проверяются отдельными storage tests после выбора владельца.
- Installer/deployment contract тестирует host-specific secret handoff,
  restrictive permissions, atomic replace/reload, failed activation и rollback;
  секрет не попадает в public CLI/gist, image layer, repository или общий
  `WORKER_API_TOKEN` env. `CNV-32` bootstrap/update остаётся отдельным owner.
- Карточка не меняет telemetry formulas, cadence, latest-only snapshot или UI
  comparison `CNV-137`; не меняет command schema/state machine `CNV-134`, WS
  protocol/worker executor `CNV-135` и command UI `CNV-136`. Shared
  `WORKER_API_TOKEN` остаётся явно временной/deferred limitation до cutover, без
  заявления о least privilege или per-host revoke в CNV-137.

**Open questions:**
- Какой canonical host binding нужен помимо `HOST_NAME`: только deployment
  identity, registration epoch, signed host key/attestation или комбинация; кто
  является owner при переустановке и rename host?
- Где хранить verifier и append-only audit, какой secret manager/HSM допустим,
  кто владеет retention/cleanup и какой cache/revalidation SLA нужен для
  near-immediate revoke/quarantine?
- Какой enrollment handoff выбрать (одноразовый bootstrap link, operator-mediated
  file/secret manager или mTLS/signed exchange), как ограничить TTL и как
  безопасно recover потерянный pending/active credential?
- Должен ли rotate быть overlap с двумя credential epochs или atomic cutover;
  что происходит с in-flight telemetry, reconnect и queued requests, и как
  quarantine снимается (кто, с каким reason и дополнительным approval)?
- Какие granular admin permissions, tenant/role boundaries, rate/concurrency
  limits и audit retention required; какие endpoints считаются collector-only
  до появления отдельного control credential?
- Какой точный installer/deployment owner и release gate обеспечивает secret
  delivery на remote host без раскрытия оператору, CI logs или public artifact;
  допускается ли remote activation без live host verification?

**Decisions:**
- 2026-09-04: создано после inventory `CNV-137`, `CNV-134`, `CNV-135`, `CNV-136`,
  `CNV-32`, `CNV-133`, `CNV-35` и `CNV-69`; точного per-host credential
  lifecycle card не найдено. Scope выделен отдельно от telemetry и command
  control, чтобы shared-token limitation не стала скрытым security claim.
- 2026-09-04: до отдельного cutover `CNV-137` может использовать общий
  `WORKER_API_TOKEN` только для ограниченного gateway telemetry channel; это
  временная/deferred limitation. Она не означает least privilege, per-host
  rotation, revoke или quarantine и не выдаёт collector'у `GATEWAY_INTERNAL_TOKEN`.
- 2026-09-04: карточка остаётся в `grooming/`; storage, enrollment transport,
  identity proof, cache/revocation SLA, permissions, retention, overlap и
  deployment handoff намеренно не выбраны.

**Dependencies:**
- Использует canonical host identity и outbound-only topology из
  `[[CNV-133-distributed-workers-stage2]]`, а telemetry boundary/limits и
  explicit deferred shared-token state из `[[CNV-137-host-resource-telemetry-admin-workers]]`.
- Должна согласовать collector-only channel с `CNV-137`; после решения credential
  contract станет prerequisite для замены shared credential в telemetry.
- Не дублирует `CNV-134` admin command contract, `CNV-135` WS delivery/executor,
  `CNV-136` command UI, `CNV-35`/`CNV-69` worker observability/listing или `CNV-32`
  public bootstrap/update; интеграционные точки потребуют их contract review.

**Execution Log:**
- 2026-09-04: inventory выполнен по active/grooming/todo/done cards и
  `ROADMAP.md`; runtime/source/config/deploy/secrets не изменялись.
- Prompt evidence: model tier `standard`; token usage availability: unavailable;
  sanitized prompt summary: create one Russian grooming card for per-host
  credential lifecycle, collector-only delivery, admin management and negative
  security tests without duplicating telemetry or command-control cards;
  Agent docs-kanban.
- Validation before commit: targeted Kanban lint for CNV-139 and CNV-137 — 2
  cards checked, 0 errors, 0 warnings; full-board Kanban lint — 72 cards checked,
  0 errors, 0 warnings; `git diff --check` and staged diff check passed.

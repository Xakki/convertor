### Контракт host-resource telemetry и проценты в admin workers

**Criticality:** Medium

**TAGS:**
- feature
- admin
- infra
- worker
- ux

**Description:**
Реализовать один сквозной backend+UI контракт host-resource telemetry для
`/admin/workers`. Worker собирает host snapshot не чаще одного раза в 10 минут,
backend хранит последний snapshot, а admin UI показывает значения с единицами,
scope, timestamp и честным stale/unknown состоянием.

Карточка не меняет routing matrix, worker identity или semantics очередей. Она
владеет контрактом источника, единицами, freshness/error-состояниями,
агрегированием и admin-представлением; существующий
`CNV-35-registry-08-worker-observability` и готовая host-свёртка
`CNV-69-admin-workers` являются базой, а не повторной работой.

**Problem:**
Сейчас worker liveness передаёт `cpu`, `mem`, `load` как доли `0..1`, причём
реализация читает преимущественно cgroup контейнера. В админке это не позволяет
ответить, сколько потребляют Convertor worker containers относительно всего
host. В текущем контракте также отсутствуют logical CPU count, свободная память,
ёмкость памяти и занятый диск с ёмкостью; UI не может честно вычислить значения
или проценты без нового источника.

**Impact:**
Оператор может принять долю контейнера за загрузку host, сравнить
несопоставимые числа или принять отсутствие host telemetry за нулевую загрузку.
Скрытая подмена недоступных значений нулями даст ложную картину памяти, диска и
нагрузки; слишком частый сбор увеличит нагрузку и раскроет лишние сведения о
host.

**Recommendation:**
- Ввести versioned telemetry contract worker → gateway/relay → PHP → admin.
  Для каждого значения явно передавать `unit`, `scope`, `observedAt`, `source`,
  `freshUntil`/stale-признак и `available`; недоступные значения сериализуются
  как `null`, никогда не как `0`.
- Worker host snapshot содержит logical `cpuCount`, `memTotalBytes`,
  `memAvailableBytes`, `diskTotalBytes` и `diskUsedBytes` для `/`; UI
  отображает `memoryFreeGb` из `MemAvailable` и `diskUsedGb` для root `/`.
  Snapshot bounded и не содержит путей кроме `/`, имён процессов, env или
  секретов.
- Собирать snapshot scheduler-ом worker с минимальным интервалом 10 минут;
  backend сохраняет только последний snapshot для каждого canonical host.
  On-demand refresh не добавляется. Snapshot старше 20 минут считается stale.
- Scope resource percentages — агрегированные только Convertor worker
  containers на выбранном host. Worker CPU percentage — aggregate worker CPU
  usage relative to logical host CPUs; worker memory percentage — aggregate
  worker RSS/cgroup usage divided by host `MemTotal`; normalized Load — 1m load
  average divided by logical CPU count. Host displayed free memory is
  `MemAvailable`, not `MemTotal - worker usage`.
- Передавать total capacity values вместе с raw usage, хранить raw precision и
  округлять только при рендере. Не вычислять процент при смешении snapshots или
  при недоступном знаменателе; показывать `—`/unknown и stale marker.
- Admin API остаётся под `ROLE_ADMIN`; host name и aggregate resource values
  доступны только авторизованным admin users. Ошибки/partial availability не
  раскрывают process, container, env или secret details. Backend не хранит
  историю: retention policy — latest snapshot per canonical host, с bounded
  payload и rate/freshness guards.
- В UI добавить native `title` и доступное описание для каждого metric value:
  CPU count, CPU worker/host %, MEM worker/host %, Load worker/host %, free
  memory GB, used disk GB, timestamp и stale/unknown. Text объясняет единицу,
  scope, знаменатель и окно измерения.
- Сохранять строки workers по ключу `host + workerType + instanceId`; image,
  matrix или stream не являются identity и не используются для схлопывания.
  Аудио/видео routing semantics и существующие worker rows остаются без
  изменения и не требуют отдельного задания в этой карточке.

**Acceptance Criteria:**
- Versioned contract и contract tests задают для каждого telemetry field unit,
  scope, observedAt, source, freshness/stale и null/unknown policy; tests не
  допускают смешения worker/container и host знаменателей.
- Worker gathers at most one host snapshot per 10-minute interval; snapshot
  includes logical CPU count, `memTotalBytes`, `memAvailableBytes`,
  `diskTotalBytes`, `diskUsedBytes` for `/`; backend stores the latest snapshot
  per exact `HOST_NAME` key and no telemetry history. Latest-only uniqueness is
  by exact key, with no alias-based deduplication.
- Deployment must supply a nonempty lowercase DNS-label/FQDN `HOST_NAME`;
  missing or invalid values are rejected/unknown at the validator boundary.
  Collector and workers send the exact configured value; no automatic backend
  alias normalization or fallback to the container hostname is allowed.
  Deployment tests cover valid values, missing/invalid rejection, exact-value
  propagation and distinct exact-key retention.
- Liveness/admin API additively exposes the snapshot; unavailable source yields
  null/unknown, never zero, and an observation older than 20 minutes is visibly
  stale. Existing liveness frequency and routing behavior remain unchanged.
- For a selected host, summary shows separate worker and host CPU/MEM/Load
  percentages. Worker CPU uses aggregate worker CPU / logical host CPUs, worker
  memory uses aggregate worker RSS/cgroup / host MemTotal, and normalized Load
  uses 1m load / logical CPU count. Displayed free memory uses MemAvailable.
  Values use one snapshot or show `—`; raw precision is preserved and rounding
  occurs only in the UI.
- Each metric value has tooltip/title and ARIA description explaining unit,
  scope, denominator, measurement window, observed timestamp and stale/unknown
  semantics. Browser tests confirm raw telemetry, process details and secrets do
  not appear in the DOM.
- Rows remain distinct by `(host, workerType, instanceId)` and existing stream
  and workerType labels remain distinct; no image/matrix-based deduplication is
  introduced.
- Tests cover malformed/partial payload, stale snapshot, unavailable source,
  mixed hosts, capacity/percentage formulas, rounding, admin authorization,
  latest-only retention, rate/freshness limits and unchanged routing matrix.
- Collector delivery uses an outbound-only gateway telemetry channel
  authenticated with the existing shared worker credential, `WORKER_API_TOKEN`;
  the collector never receives `GATEWAY_INTERNAL_TOKEN` and cannot directly call
  Symfony internal routes. The gateway validates the telemetry frame scope,
  `HOST_NAME` format, rate, body and freshness limits before relaying accepted
  telemetry to Symfony with its existing server-side internal credential.
  `HOST_NAME` is only a validated client assertion and payload/latest-only key,
  not authenticated host identity: a compromised shared token can forge a
  snapshot for another known host. Do not claim authenticated host binding,
  least privilege, per-host revoke, rotate or quarantine for this temporary
  remote path; those capabilities are deferred to
  `[[CNV-139-per-host-worker-credentials-lifecycle]]`. Preserve the remote
  outbound-only and no-Docker-socket constraints.

**Decisions:**
- 2026-09-01: duplicate implementation card не найдена. `CNV-35`/`CNV-69`
  покрывают базовые liveness, host grouping и container-oriented `cpu/mem/load`;
  CNV-137 ограничена отсутствующим host-resource contract и UI comparison.
- 2026-09-01: approved telemetry semantics — worker gathers one host snapshot
  every 10 minutes at most; backend stores the last snapshot per canonical host;
  scope is aggregate Convertor worker containers only.
- 2026-09-01: worker CPU percentage is aggregate worker CPU usage relative to
  logical host CPUs. Worker memory percentage is aggregate worker RSS/cgroup
  usage divided by host `MemTotal`; displayed free memory is `MemAvailable`.
  Normalized Load is 1m load average divided by logical CPU count.
- 2026-09-01: snapshot includes total CPU, memory and root filesystem capacity
  values needed for derived percentages; disk scope is filesystem `/`, with
  `diskUsedBytes` and `diskTotalBytes`. No multi-mount aggregation is needed.
- 2026-09-01: freshness is strict snapshot scheduling (no on-demand refresh);
  snapshots older than 20 minutes are stale. Backend retention is latest-only
  per canonical host, with bounded payload and no history.
- 2026-09-01: admin authorization is `ROLE_ADMIN`; host aggregates are not
  public. Partial/unavailable data is null/unknown and does not expose process,
  container, env or secret details. Push/poll is bounded by the 10-minute
  collection interval and freshness guard.
- 2026-09-01: audio/video image pairing and routing semantics are out of scope;
  this card preserves existing identity and stream behavior rather than
  restating explanatory media material.
- 2026-09-03: approved container-collector architecture — deploy a dedicated
  Compose container collector on each host. It uses read-only host mounts only,
  has no Docker socket/API access, and relies on a statically configured
  deployment allowlist mapping Convertor workers to cgroup paths/units. The
  collector performs sanitized local aggregation and sends results outbound
  through authenticated delivery.
- 2026-09-03: strict collector acceptance boundary — run non-root, expose only
  the minimum required read-only mounts, and prohibit arbitrary host file reads.
  API payloads must not contain Docker metadata, environment variables or
  process names. The collector opens no inbound remote port. Any allowlist
  mapping update is coupled to the corresponding worker deploy/recreate.

- 2026-09-03: approved canonical host identity policy — `HOST_NAME` is the
  sole canonical host key. Deployment must provide a nonempty lowercase
  DNS-label/FQDN; the validator rejects missing/invalid values or marks them
  unknown. Collector and workers send the exact configured value. The backend
  performs no automatic alias normalization; aliases change only through
  explicit deployment configuration. Latest-only retention is unique by exact
  key, and there is no fallback to the container hostname. Deployment tests
  cover validation, exact-value propagation, alias non-normalization and
  distinct exact-key retention.
- 2026-09-03: approved final collector allowlist deployment lifecycle —
  deployment generates a versioned, read-only `allowlist.json`, atomically
  refreshed with every worker pull/recreate; collector reload/recreate is
  coupled to that change. Validation or recreate failure is fail-closed, and
  the mapping rolls back with the deployment. The collector has no Docker
  socket/API access; mappings use strict relative cgroup paths under the
  collector mounted root. The manifest does not include container IDs/env/Docker
  metadata. Validation runs before activation, activation uses atomic rename,
  and health/provenance verification must confirm rollback coherence. No
  low-level file path or schema is fixed by this decision.
- 2026-09-04: superseded by the 2026-09-04 shared-worker-credential decision
  below — prior approved gateway-mediated least-privilege collector transport
  recorded for history only:
  the remote collector sends snapshots only through a new authenticated,
  outbound-only gateway telemetry channel. The gateway validates a separate
  collector credential bound to the exact `HOST_NAME`, applies host binding and
  rate limits, and relays telemetry to Symfony using its existing server-side
  internal credential. The payload host is not trusted standalone. Credentials
  must support per-host rotation, revocation and quarantine; they grant no
  internal or worker route access. No remote host receives
  `GATEWAY_INTERNAL_TOKEN` or shared `WORKER_API_TOKEN`; installer and secret
  material is collector-scoped, not a public CLI, gist or general worker env.
  Credential issuance, storage and enrollment mechanics remain undecided.
- 2026-09-04: user-approved superseding clarification for the temporary remote
  telemetry path — reuse the shared `WORKER_API_TOKEN` only toward the gateway
  telemetry channel; it never receives `GATEWAY_INTERNAL_TOKEN` and cannot
  directly call Symfony internal routes. The gateway validates `HOST_NAME`
  syntax plus telemetry frame scope, rate, body and freshness limits, but does
  not authenticate the asserted host identity. A compromised shared token can
  forge snapshots for another known host. Exact `HOST_NAME` propagation and
  exact-key latest-only retention remain data validation semantics, not proof of
  host identity. Authenticated host binding and per-host credential
  rotate/revoke/quarantine are deferred remediation owned by
  `[[CNV-139-per-host-worker-credentials-lifecycle]]`; no least-privilege or
  one-host-revocation claim is made. Remote outbound-only and no-Docker-socket
  controls are preserved.

**Dependencies:**
- Uses `[[CNV-35-registry-08-worker-observability]]` and
  `[[CNV-69-admin-workers]]`; does not duplicate their storage, host grouping or
  current liveness endpoint.
- Coordinates with `[[CNV-133-distributed-workers-stage2]]` for remote-host
  privacy, identity and rollout topology; remote hosts/secrets are not required
  to implement this card.
- Per-host credential separation and lifecycle are owned by
  `[[CNV-139-per-host-worker-credentials-lifecycle]]`; until that card's cutover,
  this card retains the explicitly deferred shared `WORKER_API_TOKEN` limitation.

**Execution Log:**
- 2026-09-01: inventory completed against compose, WorkerType, worker capability
  catalog, gateway liveness, WorkerStatsProvider and admin template; no
  source/runtime/secrets/deploy changes.
- Prompt evidence: model tier `standard`; token usage availability: unavailable;
  sanitized prompt summary: finalize approved CNV-137 telemetry semantics as one
  backend+UI todo task, validate and commit card lifecycle change; Agent
  docs-kanban.
- 2026-09-01: user-approved worker snapshot cadence, latest-only backend storage,
  worker-container scope, CPU/memory/load formulas, capacity fields, MemAvailable
  display, root filesystem and stale-after-20m policy recorded. All prior open
  questions resolved; card is implementable as one backend+UI task.

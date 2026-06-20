### Run workers as standalone containers on any host (Redis + S3 only)

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Today workers run inside the project compose stack on the same host, sharing the `backend` docker
network and (currently) the `/shared-files` volume. Goal: be able to launch a worker container on
**any server / laptop** that consumes **this project's KeyDB streams** and reads/writes **S3**, with
no app stack and no shared volume on that host. Workers already declare capabilities and join a
consumer group (`convertor`) — overlap + multiple instances are supported by design; this card is
about the **connectivity + packaging** to run them off-box.

This is the concrete first step of the queue-epic's deferred "multi-worker launch/scaling" follow-up
([[fix-queue-php-worker-mismatch]]). The **ai** worker is the first intended external worker: it is
gated off the main stack via `profiles: ["ai"]` (done in [[optimize-worker-dockerfiles]]) and is too
heavy for prod-on-box.

**Decisions (2026-06-20, user):**
- **Redis access = TLS SNI-stream via `shared-nginx`** — same pattern already used on saFin for
  `mongo.*` / `pg.*` / `opensearch.*` (stream :81). Expose KeyDB as `redis.<domain>` over TLS;
  external worker connects with TLS + KeyDB password. (NOT a public plain port; NOT a per-host VPN.)

**Depends on:** [[storage-input-to-s3]] — a remote worker cannot mount `/shared-files`, so input must
already be in S3.

**Open questions:**
- **Worker-only bundle:** ship a separate `docker-compose.workers.yml` + minimal `.env.worker`
  (REDIS_DSN → `redis.<domain>:<port>` with TLS, S3 creds, capability/profile set) so a host runs
  only the worker(s) it should? Which workers does it include by default?
- **KeyDB auth/TLS:** is `requirepass` enabled? Generate a worker-scoped password; confirm `redis-py`
  TLS config (`ssl=True`) works through the SNI-stream. Who owns the shared-nginx stream block + DNS
  `redis.*` + firewall/whitelist (root-only infra — see network-ports skill / PORTS.md)?
- **Observability of remote workers:** fluent-log/Graylog GELF is currently reachable only inside
  infra — how does an off-box worker ship structured logs (public GELF endpoint vs local stdout only)?
- **Launch/scaling strategy:** which host runs which capability, how many instances, restart policy —
  still open from the queue epic.
- **Security:** rate/connection limits on the exposed redis stream; restrict to known worker IPs?

**Acceptance Criteria (target):**
- A worker container started on a **different host** with only `REDIS_DSN` (TLS, password) + S3 creds
  joins the `convertor` consumer group and processes jobs end-to-end (input from S3 → result to S3 →
  status to Redis), with no `/shared-files` and no app stack on that host.
- Documented bootstrap: env template + compose bundle + the infra steps to expose `redis.<domain>`.
- Existing on-box workers keep working unchanged; ai runnable both on-box (`COMPOSE_PROFILES=ai`) and
  off-box.

**Decisions:**
- (Redis transport decided above; remaining open questions to resolve before → todo.)

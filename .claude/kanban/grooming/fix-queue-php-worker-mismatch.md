### Fix php↔worker queue mechanism mismatch (Streams vs list)

**Criticality:** Blocking

**TAGS:**
- bug-fix

**Description:**
Discovered during review of [[fix-configs-working-state]]. The PHP producer and the Python workers use **incompatible KeyDB queue mechanisms**, so dispatched conversion jobs are never consumed.

**Problem:**
- PHP side: Symfony Messenger Redis transport using **Redis Streams** (`config/packages/messenger.yaml` — stream `conversions`, group `convertor`; `bus->dispatch(...)`).
- Worker side: `workers/common/keydb_client.py` uses a plain **list** (`lpush` + `brpoplpush` on `<queue>` / `<queue>:processing`).
- Streams ≠ lists → messages produced by PHP land in a stream the workers never read, and vice versa. No conversion ever flows end-to-end.

Related config smells found in the same review:
- `WORKER_*_URL` inconsistent/stale: compose default `http://worker-libreoffice:6001` (health port) vs `app-symfony/.env` `http://libreoffice-worker:8000` (wrong host+port); neither targets the real HTTP service `libreoffice:6000`.
- `REDIS_QUEUE_DB=2` is dead config — `base_worker.py` reads `REDIS_DB` (default 0); workers run on db 0 (coincidentally aligned with Messenger `redis://keydb:6379` db 0). Worker healthcheck pings db 2 — cosmetic mismatch.
- Callback path dormant: workers support `callback_url` but PHP never sets it. When wired, note workers are on `backend` and nginx (HTTP entry) is on `default` only → callback would be unreachable as-is.

**Impact:**
Core product is non-functional: a submitted file is queued but never processed. Containers report healthy while nothing converts.

**Recommendation:**
Pick ONE queue contract and align both sides. Per project CLAUDE.md the architecture follows ExRate (Symfony Messenger). Decide between:
- (a) Workers consume Redis Streams (rewrite `keydb_client.py` to `XREADGROUP`/`XACK`, matching Messenger's stream+group), or
- (b) PHP produces to a plain list compatible with the workers' `brpoplpush` reliable-queue pattern (custom transport / direct lpush), dropping Messenger Redis transport for the worker channel.
Then reconcile `WORKER_*_URL`, `REDIS_DB`/healthcheck db, and the callback path / status-update mechanism (callback vs polling).

**Acceptance Criteria:**
- A file submitted via the API is picked up by the correct worker and produces output in `/shared-files/`.
- Status updates reach PHP (callback or polling) and the job is marked done.
- One queue mechanism used consistently on both sides; `WORKER_*_URL`, `REDIS_DB`, healthcheck db reconciled.
- Covered by an e2e/integration test (ties into [[smoke-run-verify]] / [[worker-conversion-tests]]).

**Open questions:**
- Streams (a) or list (b)? ExRate reference suggests Messenger/Streams — confirm.
- Status updates: worker→PHP callback HTTP, or PHP polling KeyDB? (callback needs a reachable PHP HTTP endpoint across networks).
- One channel per category (`conversion.documents/images/...` per CLAUDE.md) vs single channel + routing?

**Decisions:**
- (to be filled during grooming)

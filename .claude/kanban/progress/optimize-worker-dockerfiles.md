### Optimize worker Docker images (docker/workers)

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
Six worker Dockerfiles under `docker/workers/` (Dockerfile, ai/data/ffmpeg/image/libreoffice). Five Python workers duplicate base setup; only `libreoffice.Dockerfile` follows good practices (non-root, apt cleanup, healthcheck).

**Problem:**
- Repeated base setup across 5 Python Dockerfiles (env vars, apt install curl/tini, pip install redis/aiohttp/structlog).
- No `.dockerignore` → bloated build context.
- Unpinned pip + apt versions → non-reproducible builds.
- 5 of 6 images run as root.
- Source `COPY` before/with deps → poor layer caching.
- Incomplete apt cleanup (only `/var/lib/apt/lists/*`) in 5 files.
- Missing `ca-certificates` in Python workers (SSL risk).
- Heavy images (ffmpeg, tesseract+imagemagick, libreoffice) — possible multi-stage / libvips wins.

**Impact:**
Slow rebuilds, large images, security exposure (root), surprise breakage from floating versions.

**Recommendation:**
- Introduce shared `docker/workers/base.Dockerfile` (common env + apt + pip + non-root `app` user + tini entrypoint); workers `FROM` it.
- Add `.dockerignore` (.git, __pycache__, tests, *.md, .env*, etc.).
- Pin pip versions from `workers/requirements.txt`; pin key apt packages.
- Reorder: deps first, `COPY` source last.
- Standardize apt cleanup + healthcheck; add `ca-certificates`.

**Acceptance Criteria:**
- All worker images build via `docker compose build` (and via Makefile build targets once those are fixed — see [[fix-configs-working-state]]).
- Images run as non-root; `.dockerignore` present; versions pinned.
- Measurable size reduction vs baseline (record before/after).

**Open questions:**
- Shared base image: build locally as separate stage, or publish to Harbor registry and `FROM` that? (affects CI)
- Acceptable to swap ImageMagick → libvips, or must keep IM for format parity?
- Pre-bake AI models (faster-whisper) into image vs runtime download — image size vs cold-start tradeoff?
- Version-pinning policy: exact `==` pins, or `~=`/ranges + lockfile?

**Decisions (2026-06-20, user chose full card):**
- **Shared base = identical multi-stage base *stage* per Dockerfile** (NOT a separate `worker-base` image, NOT Harbor). Reason: a standalone local base image is not reliably built before dependents by `docker compose build` (AC requires compose build to work); an identical base stage always builds and BuildKit dedups the layers via cache.
- **boto3 everywhere** (redis+structlog+boto3 base for every python worker) — required now and by the upcoming shared-files→S3 change. Land this subset as the **FIRST commit** so the Blocking [[fix-queue-php-worker-mismatch]] ffmpeg migration can start immediately; non-root/pinning/base-stage refactor as fast-follow.
- **ImageMagick kept** (format parity) — libvips deferred (separate follow-up).
- **AI whisper models: runtime download into mounted volume kept** (no pre-bake). Non-root move: cache path `/root/.cache` → `/home/app/.cache/huggingface`; the whisper-models volume mount MUST follow to the new path or models re-download each start.
- **Pinning:** per-worker pinned deps (`==`) resolved from a real build (`pip freeze`), sourced from `workers/requirements.txt` ranges.
- **libreoffice = hygiene only** (already the card's good example). Do NOT add redis/boto3 via pip — bookworm is PEP 668 externally-managed (uses apt `python3-aiohttp`); its stream-consumer deps land at its migration (card L).
- Non-root: give `app` user a writable tmp/work dir (needed immediately for shared-files→S3 staging).

**Hard verification gate (not just "builds"):**
- Rebuild `worker-image` from no-cache → re-run its pytest (`test_image_worker_stream.py`, `test_stream_consumer.py`) + image e2e round-trip → MUST stay green. Non-root + pins can silently break the only proven slice (file-write perms esp.).
- Clean `docker compose build` from no cache succeeds for all (discriminating test for the base-stage ordering).
- Record before/after sizes. Baseline (2026-06-20): image 468MB · data 437MB · ffmpeg 834MB · libreoffice 1.35GB · ai 1.46GB. ai/libreoffice are payload-dominated → expect modest size change; honest wins are reproducibility/non-root/cache/.dockerignore.

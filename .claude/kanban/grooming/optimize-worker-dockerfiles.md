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

**Decisions:**
- (to be filled during grooming)

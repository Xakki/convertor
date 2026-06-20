### Fix configs & bring the stack to a working state

**Criticality:** Blocking

**TAGS:**
- bug-fix
- tech-debt

**Description:**
Audit of compose/env/Makefile/Symfony config found several confirmed breakages plus missing init steps (Phase 1 of `ROADMAP.md` — базовый boot). This card collects everything required to make `docker compose up` start a healthy stack end-to-end. Downstream cards depend on it: [[smoke-run-verify]], and all docs-phase cards build on a running MVP.

**Problem (confirmed by inspection):**
- `docker-compose.yml` nginx mounts `./app-back/public` and `./app-back/storage` (lines 172-173), but the app lives in `app-symfony/` → mount targets a non-existent dir.
- Volume mismatch: services mount `sock:/run/sock` (lines 65, 171) but the top-level volume is declared as `php-socket:` (line 399) — `sock` is undefined → compose likely rejects the file.
- `Makefile` build targets reference `docker/workers/Dockerfile.<name>` but files are `<name>.Dockerfile` → `make build-*` fail.
- `Makefile` `PHP_CONT = $(COMPOSE_PROJECT_NAME)-php-fpm` — verify against actual php container name (service `working_dir: /app-symfony`); if wrong, `make migrate/console/test-php` fail.
- `.env` has empty critical secrets: `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `TELEGRAM_BOT_TOKEN`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `CRYPTOMUS_*`, `DOCKER_USER/PASS`, `MACHINE_NAME=change_me`.
- `app-symfony/vendor/` not installed; JWT keypair (`config/jwt/*.pem`) likely missing.

**Phase 1 init steps (from `ROADMAP.md` — базовый boot):**
- `composer install` in `app-symfony`.
- `php bin/console lexik:jwt:generate-keypair`.
- `make init` (migrations/seeds/startup) — after Makefile fixes.

**Impact:**
Stack does not come up cleanly; nginx/php fail; auth/payments unusable.

**Recommendation:**
Fix the confirmed compose/Makefile defects, reconcile `.env` against `.env_dist` (document required-vs-optional secrets), wire composer install + JWT keygen into init flow.

**Acceptance Criteria:**
- `docker compose config` validates with no errors.
- `docker compose up` brings all services to healthy (php, nginx, mariadb, keydb/redis, libreoffice, all workers).
- `make build-<worker>` targets succeed.
- `.env_dist` documents every var; `.env` has working dev values (placeholder secrets clearly marked).
- App responds on the configured nginx port.

**Decisions (grooming resolved):**
- **Service naming → `keydb`.** Rename service `redis:` → `keydb:` (matches image keydb, volume keydb-data, conf keydb.conf, and the 5 workers' existing `depends_on: keydb`). Update php/cron `depends_on`, the healthcheck, and Symfony `REDIS_URL` host accordingly.
- **App path → `/app-symfony`.** nginx root/fastcgi (`docker/nginx/dev/conf.d/default.conf`, `pf.conf`, and `prod/conf.d/default.conf`) currently use `/app-back/public`; PHP mounts source at `/app-symfony`. Fix all nginx confs + the nginx compose mount to `/app-symfony` so fastcgi resolves. `app-back/` is a rudiment (no dir on disk, no rename in git history).
- **Bootstrap → `composer deploy` script.** Add a `deploy` script to `app-symfony/composer.json` running: install → cache:clear → `lexik:jwt:generate-keypair --skip-if-exists` → `doctrine:migrations:migrate`. The php `command: "composer deploy"` then boots a working container; `make init` stays as the wrapper.
- **Secrets → required set for boot.** JWT keypair + dev DB/keydb passwords generated locally. Payments are FROZEN (free-with-limits for now, see [[docs-payments-integration]]), so **Stripe/Cryptomus/Telegram-Stars secrets are NOT needed**. `.env_dist` documents every var; unused payment vars stay empty/commented.
- **Ports → leave as-is.** `172.17.0.1:10090` (docker0 bridge) for nginx is treated as intentional (shared reverse-proxy reach); other services stay on `127.0.0.1`. Revisit only if access problems surface.

**Blocker / needs user input:**
- `TELEGRAM_BOT_TOKEN` (login auth) — supplied by user before the runtime boot/smoke-run. `DOCKER_USER`/`DOCKER_PASS` not needed (host already logged into harbor). Payment secrets dropped (payments frozen).

**Execution Log:**
- Implemented by config-fixer; reviewed by reviewer (verdict: changes requested → resolved).
- Done: redis→keydb (compose service/volume/healthcheck/container_name, fluent-logging.yml, php/cron depends_on, REDIS_DSN); app-back→app-symfony (compose mount + 3 nginx confs + common_locations + xdebug path; dropped Laravel-style storage mount); `composer deploy` script (install→cache:clear→jwt keygen --skip-if-exists→migrate -n), php `command` unchanged (harbor entrypoint execs php-fpm after one-shot); sock→php-socket volume; Makefile (PHP_CONT, worker `<name>.Dockerfile`, build-php neutralized); .env/.env_dist (MACHINE_NAME, DB dev creds, PUID/PGID=1000, fluent vars documented); networks (php+keydb on [default,backend], libreoffice+workers on backend internal, nginx/cron/mariadb on default); .gitignore app/→app-symfony/ + config/jwt/.
- Review blocker B1 fixed: `COMPOSE_FILE` no longer includes `docker/fluent-logging.yml` (missing FluentLog submodule) — re-add via [[fluent-logging-setup]].
- Security fix S3: `/app-symfony/.env` gitignored (held a live Telegram token).
- Validation: PyYAML parse OK (sandbox denies `docker compose config`); grep confirms zero stale refs.
- Committed: `c3d3f22 fix: bring docker stack config to working state`.

**Out-of-scope findings spun off (not fixed here):**
- [[fix-queue-php-worker-mismatch]] (Blocking) — PHP Messenger Streams vs worker list; conversions won't flow.
- worker-ai Whisper egress on `internal` backend — noted in [[docs-workers-conversion-validation]].

**Status — handed to `test/`:** static fixes done + reviewed. Runtime acceptance (`docker compose up` healthy, app responds, `make build-*`) **deferred to [[smoke-run-verify]]** — cannot boot here (no docker in sandbox; needs TELEGRAM_BOT_TOKEN). Note: full e2e conversion also blocked by [[fix-queue-php-worker-mismatch]].

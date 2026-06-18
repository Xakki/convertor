### Fix configs & bring the stack to a working state

**Criticality:** Blocking

**TAGS:**
- bug-fix
- tech-debt

**Description:**
Audit of compose/env/Makefile/Symfony config found several confirmed breakages plus missing init steps (Phase 1 of `docs/plan.md`). This card collects everything required to make `docker compose up` start a healthy stack end-to-end. Downstream cards depend on it: [[smoke-run-verify]], and all docs-phase cards build on a running MVP.

**Problem (confirmed by inspection):**
- `docker-compose.yml` nginx mounts `./app-back/public` and `./app-back/storage` (lines 172-173), but the app lives in `app-symfony/` → mount targets a non-existent dir.
- Volume mismatch: services mount `sock:/run/sock` (lines 65, 171) but the top-level volume is declared as `php-socket:` (line 399) — `sock` is undefined → compose likely rejects the file.
- `Makefile` build targets reference `docker/workers/Dockerfile.<name>` but files are `<name>.Dockerfile` → `make build-*` fail.
- `Makefile` `PHP_CONT = $(COMPOSE_PROJECT_NAME)-php-fpm` — verify against actual php container name (service `working_dir: /app-symfony`); if wrong, `make migrate/console/test-php` fail.
- `.env` has empty critical secrets: `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `TELEGRAM_BOT_TOKEN`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `CRYPTOMUS_*`, `DOCKER_USER/PASS`, `MACHINE_NAME=change_me`.
- `app-symfony/vendor/` not installed; JWT keypair (`config/jwt/*.pem`) likely missing.

**Phase 1 init steps (from docs/plan.md):**
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

**Open questions:**
- nginx `app-back` mounts: rename to `app-symfony`, or is `app-back` a planned separate backend? Confirm intended path.
- `redis` vs `keydb` service naming — audit flagged a possible service/depends_on mismatch; verify and pick one canonical name.
- Which secrets are mandatory for a *local dev* boot vs only for prod features (Telegram/Stripe/Cryptomus)? Define a minimal dev `.env`.
- Port binding asymmetry (`172.17.0.1:10090` for nginx vs `127.0.0.1:*` for others) — intentional or normalize to localhost?
- Should composer install + JWT keygen run in the php entrypoint, or stay manual `make` steps?

**Decisions:**
- (to be filled during grooming)

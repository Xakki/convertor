### CI: провижининг test-DB (convertor_test) через миграции

**Критичность:** Medium

**TAGS:**
- chore
- ci
- tests

**Описание:**
В ходе задачи `auth-refresh-token` (2026-06-22) появились первые функциональные/интеграционные тесты, которым нужна реальная БД `convertor_test` и KeyDB db1. tester провизионил `convertor_test` вручную через root (`CREATE` + `GRANT` to `convertor`) и собрал схему через `doctrine:schema:create` (НЕ через миграции проекта).

**Проблема:**
- На любом окружении без заранее созданной `convertor_test` happy-path + deactivated-user тесты **молча скипаются** (self-skip с сообщением) → «зелёный» прогон вне dev не покрывает HTTP-слой refresh.
- Схема собрана `schema:create`, а не миграциями → дрейф от прод-схемы.

**Решение (ориентир):**
- Кодифицировать шаг подготовки test-DB (Makefile-таргет, напр. `test-db-setup`): создать `convertor_test`, прогнать `doctrine:migrations:migrate` (НЕ schema:create).
- Прокинуть это в CI перед `make test-php`, чтобы live-dep тесты реально выполнялись.
- Убедиться, что test-окружение поднимает KeyDB db1 (`REDIS_SESSIONS_DSN`).

**Decisions:**
- 2026-06-22: вынесено из `auth-refresh-token` как отдельная инфра-задача (вне скоупа фичи).
- Provision via a **Makefile target** `test-db-setup`. RULE: if the action runs inside app-symfony, write a **composer script** (composer.json) first and have the Makefile target call the composer script (not raw symfony console in the Makefile).
- Schema via **`doctrine:migrations:migrate`** under APP_ENV=test (NOT schema:create/fixtures).
- MUST FIX the naming mismatch: existing `docker/mariadb/dev/init/create-test-db.sh` makes `convertor-test` (hyphen) + wrong user; Symfony needs DB `convertor_test` (underscore) accessed as user `convertor`. Target must create+grant correctly then migrate.
- No seed-plans needed (users.plan is VARCHAR default, not FK).

**Status:** ready (todo). Unblocks: smoke-run-verify, auth-refresh-token tests, api-integration-tests.

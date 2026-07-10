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
- No seed-plans needed (users.plan is VARCHAR default, not FK).
- **2026-07-10 (user, ПЕРЕОПРЕДЕЛЯЕТ старое решение о naming):**
  - MariaDB — **отдельный тестовый юзер + БД, имя `convertor-test` (дефис, как уже есть)**;
    prod-юзера `convertor` НЕ переиспользуем. Старое требование «`convertor_test`
    (underscore) под юзером `convertor`» отменено — recon показал, что существующий
    `create-test-db.sh` уже делает отдельного юзера+БД `convertor-test` консистентно
    с `.env.test`/`test-e2e`; underscore был только в cosmetic skip-message.
  - KeyDB — тест использует **db3** (уже зарезервирован под тест: `.env.test`,
    `test-e2e`); prod занимает 0=cache/1=sessions/2=queues, поэтому #0 брать нельзя.

**Recon (2026-07-10):** нет таргета `test-db-setup`; `create-test-db.sh` (user+db
`convertor-test`, pw `123456`, GRANT ALL) запускается ТОЛЬКО при первом init тома;
`phpunit.dist.xml` форсит `APP_ENV=test` → тесты грузят `.env.test`, но на окружении
без провиженинга `convertor-test` self-skip'аются (`AuthRefreshControllerTest::skipUnlessTestDb`,
skip-message ошибочно пишет `convertor_test`). Composer scripts секции для миграций нет.

**Status:** ready (todo). Unblocks: smoke-run-verify, auth-refresh-token tests, api-integration-tests.

## Execution Log

- **2026-07-10 (branch `task/ci-test-db-provisioning`):**
  - `docker/mariadb/dev/init/create-test-db.sh` — пароль тест-юзера теперь из
    `${DB_TEST_PASS:-123456}` (не хардкод); добавлен `ALTER USER` (синк пароля на
    повторе). Скрипт остался БЕЗ `set -eu` — mariadb-entrypoint SOURCE'ит не-executable
    .sh, `set -e` утёк бы в шелл энтрипойнта и сорвал fresh-init. Идемпотентен, работает
    и как init-entrypoint, и on-demand из `test-db-setup`.
  - `app-symfony/composer.json` — новый скрипт `test-db-migrate`:
    `@php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration`
    (composer-script rule: действие внутри app-symfony → сначала composer-скрипт,
    Makefile его дёргает). `--allow-no-migration` → повтор на up-to-date БД = rc 0.
  - Root `Makefile` — таргеты `test-db-setup` (провижининг+грант+миграция, идемпотентно)
    и `test-php-live` (= `test-db-setup` + `test-php`). Использован `$(DC)`, НЕ
    `$(COMPOSE_TEST)`: изоляция тестов app-layer (имя БД `convertor-test` + KeyDB db3
    через `app-symfony/.env.test`/`APP_ENV=test`), а не container-layer; `$(COMPOSE_TEST)`
    (APP_ENV=test) пересоздал бы shared dev mariadb/keydb/php в test-режим и сломал dev.
    Миграция таргетит `convertor-test` строго через `-e APP_ENV=test` на exec.
  - `AuthRefreshControllerTest.php` — cosmetic skip-message `convertor_test` →
    `convertor-test` (+ подсказка `make test-db-setup`). Других stray `convertor_test`
    как идентификаторов в коде нет (остальные — в этой карточке/старой done-карточке).

  **Verify (evidence):**
  - `make docker-check` → rc 0.
  - `make test-db-setup` ×2 → оба rc 0 (2-й: `[OK] Already at the latest version`,
    grant/user IF NOT EXISTS). Dev-стек не тронут (php остался `APP_ENV=dev`, контейнеры
    Running, без recreate).
  - Схема через миграции: `doctrine:migrations:current` = `Version20260710113302`
    (не schema:create).
  - `AuthRefreshControllerTest` — **5/5 PASS, 39 assertions** (ранее happy-path +
    deactivated-user молча скипались; теперь реально выполняются DB+KeyDB-пути).

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

**Открытые вопросы:**
- Где провизионить (docker-compose test-сервис vs CI-шаг)?
- Гонять миграции или dedicated test-fixtures?

**Decisions:**
- 2026-06-22: вынесено из `auth-refresh-token` как отдельная инфра-задача (вне скоупа фичи).

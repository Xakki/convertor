#!/bin/bash
# Провижининг изолированной тест-БД + тест-юзера. Имена — `<база>-test` / `<юзер>-test`
# (дефис сохраняем: совпадает с app-symfony/.env.test и workers test-e2e). Пароль берём
# из DB_TEST_PASS (фолбэк 123456), чтобы не хардкодить.
#
# Два пути запуска (оба идемпотентны, IF NOT EXISTS):
#   1) init-entrypoint при ПЕРВОМ создании тома mariadb (docker-entrypoint-initdb.d);
#   2) on-demand из `make test-db-setup` на уже поднятом контейнере.
# NB: entrypoint SOURCE'ит не-executable .sh → НЕ ставим `set -eu` (утечёт в шелл
# энтрипойнта и может сорвать fresh-init). Heredoc — одна команда, её код возврата
# и так пробрасывается вызывающему.
TEST_PASS="${DB_TEST_PASS:-123456}"
mariadb -u root -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
	CREATE USER IF NOT EXISTS '${MARIADB_USER}-test'@'%' IDENTIFIED BY '${TEST_PASS}';
	ALTER USER '${MARIADB_USER}-test'@'%' IDENTIFIED BY '${TEST_PASS}';
	CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}-test\`;
	GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}-test\`.* TO '${MARIADB_USER}-test'@'%';
	FLUSH PRIVILEGES;
EOSQL

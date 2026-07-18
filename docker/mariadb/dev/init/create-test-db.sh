#!/bin/bash
# Провижининг изолированной тест-БД + тест-юзера. Имена — `<база>-test` / `<юзер>-test`
# (дефис сохраняем: совпадает с app-symfony/.env.test и workers test-e2e). Пароль
# `123456` — ЗАФИКСИРОВАН (единый источник правды с app-symfony/.env.test's
# DATABASE_URL, которое его хардкодит). НЕ читаем из DB_TEST_PASS env — раньше был
# фолбэк `${DB_TEST_PASS:-123456}`, но это давало два независимых источника, которые
# могли разъехаться (кто-то переопределит DB_TEST_PASS в окружении → провижининг
# создаст юзера с новым паролем, а phpunit по-прежнему подключится со старым
# хардкодом из .env.test → access denied). Если понадобится сменить пароль — менять
# оба места разом.
#
# Два пути запуска (оба идемпотентны, IF NOT EXISTS):
#   1) init-entrypoint при ПЕРВОМ создании тома mariadb (docker-entrypoint-initdb.d);
#   2) on-demand из `make test-db-setup` на уже поднятом контейнере.
# NB: entrypoint SOURCE'ит не-executable .sh → НЕ ставим `set -eu` (утечёт в шелл
# энтрипойнта и может сорвать fresh-init). Heredoc — одна команда, её код возврата
# и так пробрасывается вызывающему.
TEST_PASS="123456"
mariadb -u root -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
	CREATE USER IF NOT EXISTS '${MARIADB_USER}-test'@'%' IDENTIFIED BY '${TEST_PASS}';
	ALTER USER '${MARIADB_USER}-test'@'%' IDENTIFIED BY '${TEST_PASS}';
	CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}-test\`;
	GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}-test\`.* TO '${MARIADB_USER}-test'@'%';
	FLUSH PRIVILEGES;
EOSQL

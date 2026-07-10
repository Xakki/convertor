SHELL = /bin/bash
### https://makefiletutorial.com/

HOST_NAME := $(shell hostname)
HOST_IP  := $(shell hostname -I 2>/dev/null | awk '{print $$1}' || echo unknown)
MYSQL_SLOWLOG_PATH := $(CURDIR)/docker/logs
JSON_LOG_PATH := $(CURDIR)/docker/logs
include .env
-include .env.local
export

# Don't leak .env's COMPOSE_FILE into recipe shells: it would shadow the
# COMPOSE_FILE that `--env-file .env.test` supplies for the test/e2e path
# (adds docker/docker-compose.e2e.yml). Plain `docker compose` still reads
# COMPOSE_FILE from the auto-loaded .env in cwd.
unexport COMPOSE_FILE
# Don't leak APP_ENV=dev from root .env into recipe shells: the test compose
# (--env-file .env.test) must win with APP_ENV=test so PHP boots in test mode,
# skips .env.local, and uses test-worker-token / test-internal-token.
# Shell env > --env-file precedence means we must unexport to let env-file win.
unexport APP_ENV
DC         = docker compose
COMPOSE_TEST = docker compose --env-file .env.test
# Strip dev-only vars for the e2e stack-up so --env-file .env.test wins (test tokens
# + internal nginx URL). ONLY used as prefix for that one $(COMPOSE_TEST) up call;
# dev `make up` and the restore line keep the exported .env.local values.
E2E_CLEAN_ENV = env -u WORKER_API_TOKEN -u GATEWAY_INTERNAL_TOKEN -u API_BASE_URL
DB_TEST_PASS  = 123456
PHP_CONT   = $(COMPOSE_PROJECT_NAME)-php
KEYDB_CONT = $(COMPOSE_PROJECT_NAME)-keydb
PUID := $(shell id -u)
PGID := $(shell id -g)

# Colours
BOLD  = \033[1m
RESET = \033[0m
GREEN = \033[32m
CYAN  = \033[36m

# Guard for data-destroying targets: when APP_ENV is production, require an explicit
# interactive "yes". No TTY (e.g. CI) → refuse. Non-prod envs pass through silently.
CONFIRM_PROD = if [ "$(APP_ENV)" = "production" ] || [ "$(APP_ENV)" = "prod" ]; then if [ ! -t 0 ]; then echo "✋ APP_ENV=$(APP_ENV): refusing destructive target without interactive confirmation"; exit 1; fi; read -p "⚠️  APP_ENV=$(APP_ENV): this DESTROYS DB data. Type 'yes' to continue: " a; [ "$$a" = "yes" ] || { echo "Aborted."; exit 1; }; fi

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\n$(BOLD)Usage:$(RESET)\n  make $(CYAN)<target>$(RESET)\n"} \
	     /^[a-zA-Z0-9_%\-]+:.*?##/ { printf "  $(CYAN)%-25s$(RESET) %s\n", $$1, $$2 } \
	     /^##@/ { printf "\n$(BOLD)%s$(RESET)\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Project lifecycle

.PHONY: init
init: build up migrate seed-plans ## First-time setup: build + up + migrate + seed-plans
	@echo -e "$(GREEN)Project initialised!$(RESET)"

.PHONY: up
up: ## Start all services in background
	$(DC) up -d

.PHONY: down
down: ## Stop and remove containers
	$(DC) down

.PHONY: restart
restart: down up ## Restart all services

.PHONY: build
build: ## Build all images
	$(DC) build

.PHONY: rebuild
rebuild: ## Build all images without cache
	$(DC) build --no-cache

.PHONY: pull
pull: ## Pull latest base images
	$(DC) pull

.PHONY: ps
ps: ## Show running containers
	$(DC) ps

docker-check:  ## Check docker config
	@$(DC) config -q

##@ Logs

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f

.PHONY: logs-%
logs-%: ## Tail logs for a specific service (make logs-php)
	$(DC) logs -f $*

##@ Docker auth

.PHONY: login
login: ## Login to Docker registry
	docker login $(DOCKER_HOST) -u $(DOCKER_USER) -p $(DOCKER_PASS)

##@ Testing

.PHONY: test
test: test-php test-python ## Run all tests (PHPUnit + pytest)

# Провижининг тест-БД `convertor-test` + грант тест-юзеру + миграции под APP_ENV=test.
# Идемпотентно, безопасно к повторному запуску (IF NOT EXISTS + --allow-no-migration).
#
# Почему $(DC), а НЕ $(COMPOSE_TEST): изоляция тестов — на уровне ПРИЛОЖЕНИЯ (отдельное
# имя БД `convertor-test` + логический KeyDB db3), а не контейнера. Выбор тест-БД делает
# Symfony через app-symfony/.env.test (APP_ENV=test), НЕ compose env-file. mariadb/keydb
# для провижининга env-agnostic. $(COMPOSE_TEST) (APP_ENV=test) пересоздал бы уже
# поднятые dev-контейнеры mariadb/keydb/php в test-режим и сломал бы dev-стек — как и
# test-e2e, shared-инфру не трогаем. Миграция таргетит `convertor-test` строго из-за
# `-e APP_ENV=test` на exec (composer-скрипт test-db-migrate → app-symfony/.env.test).
.PHONY: test-db-setup
test-db-setup: ## Провижининг тест-БД convertor-test + KeyDB + миграции (идемпотентно)
	$(DC) up -d --wait mariadb keydb php
	$(DC) exec -T -e DB_TEST_PASS='$(DB_TEST_PASS)' mariadb bash /docker-entrypoint-initdb.d/create-test-db.sh
	docker exec -e APP_ENV=test $(PHP_CONT) composer test-db-migrate

.PHONY: test-php-live
test-php-live: test-db-setup test-php ## Провижининг тест-БД, затем PHPUnit (live-DB/KeyDB тесты реально выполняются)

# ---------------------------------------------------------------------------
# Per-component fragments (variables above must be defined before these are
# included — fragments inherit DC / PHP_CONT / KEYDB_CONT / COMPOSE_TEST etc.)
# ---------------------------------------------------------------------------
include app-symfony/Makefile
include workers/Makefile

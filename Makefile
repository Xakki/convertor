SHELL = /bin/bash
### https://makefiletutorial.com/

# ─────────────────────────────────────────────────────────────────────────────
# Окружение — ЕДИНСТВЕННЫЙ источник поведения всех таргетов и compose.
# Порядок наложения: .env (база) → .env.local (секреты хоста) → .env.test (только
# при TEST=1). include — директива ПАРСИНГА, поэтому тест-окружение включается
# пере-входом в make с TEST=1 (см. $(MAKE_TEST) и таргеты test*): sub-make
# перечитывает Makefile уже с .env.test. Присваивания в makefile выигрывают у
# унаследованного окружения, поэтому никаких unexport / `--env-file` / `env -u`
# больше не нужно — compose получает ровно то, что собрал make.
# ─────────────────────────────────────────────────────────────────────────────
include .env
-include .env.local
ifeq ($(TEST),1)
include .env.test
endif
export

DC         = docker compose
# Пере-вход в make с тест-окружением (идемпотентен — повторный TEST=1 безвреден).
MAKE_TEST  = $(MAKE) --no-print-directory TEST=1
# fluent-bit-сайдкар — submodule-компоуз docker/fluent-log/docker-fluent.yml.
# Трекаемый COMPOSE_FILE его не включает (saFin → host-level shared-fluent);
# remote .env.local из .env.local_worker_example ДОБАВЛЯЕТ его в COMPOSE_FILE.
# fluent-* таргеты поднимают сайдкар через $(DC_FLUENT) независимо от COMPOSE_FILE.
DC_FLUENT  = COMPOSE_FILE=docker-compose.yml:docker/fluent-log/docker-fluent.yml:docker/fluent-logging.yml docker compose
PHP_CONT   = $(COMPOSE_PROJECT_NAME)-php
KEYDB_CONT = $(COMPOSE_PROJECT_NAME)-keydb
HOST_NAME ?= $(shell hostname)
HOST_IP   := $(shell hostname -I 2>/dev/null | awk '{print $$1}' || echo unknown)
MYSQL_SLOWLOG_PATH := $(CURDIR)/docker/logs
JSON_LOG_PATH      := $(CURDIR)/docker/logs
PUID := $(shell id -u)
PGID := $(shell id -g)

# Тест-таргеты, которым нужен стенд, обязаны идти через тест-окружение: иначе они
# смотрели бы в dev-контейнеры и dev-БД.
REQUIRE_TEST = @if [ "$(TEST)" != "1" ]; then echo "✋ $@ требует тест-окружения: make test   (или make TEST=1 $@)"; exit 1; fi

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
init: build up migrate ## First-time setup: build + up + migrate (планы сеются миграциями)
	@echo -e "$(GREEN)Project initialised!$(RESET)"

.PHONY: up
up: ## Start stack и дождаться healthy — ГЛАВНЫЙ СЕРВЕР (remote-хосты: workers-recreate)
	$(DC) up -d --wait

.PHONY: down
down: ## Stop & remove containers — ГЛАВНЫЙ СЕРВЕР (remote-хосты гасят свои воркеры)
	$(DC) down

.PHONY: down-v
down-v: ## Stop & remove containers ВМЕСТЕ С ТОМАМИ (стирает БД/KeyDB стенда)
	$(CONFIRM_PROD)
	$(DC) down -v

.PHONY: restart
restart: down up ## Restart all services

.PHONY: build
build: ## Build all images
	$(DC) build

.PHONY: rebuild
rebuild: ## Build all images without cache
	$(DC) build --no-cache

.PHONY: pull
pull: ## Подтянуть образы: внешние (php/mariadb/nginx/keydb) + воркеры из Harbor
	$(DC) pull

.PHONY: ps
ps: ## Show running containers
	$(DC) ps

.PHONY: docker-check
docker-check: ## Проверить compose-конфиг обоих стендов (dev + test)
	@$(DC) config -q && echo "dev: ok"
	@$(MAKE_TEST) config-check && echo "test: ok"

.PHONY: config-check
config-check:
	@$(DC) config -q

.PHONY: harbor-login
harbor-login: ## Login to Harbor (DOCKER_* from .env/.env.local)
	@if [ -z "$(DOCKER_REGISTRY)" ] || [ -z "$(DOCKER_USER)" ] || [ -z "$(DOCKER_PASS)" ]; then \
		echo "✋ harbor-login: задайте DOCKER_REGISTRY в .env, DOCKER_USER и DOCKER_PASS в .env.local"; \
		exit 1; \
	fi
	docker login $(DOCKER_REGISTRY) -u $(DOCKER_USER) -p $(DOCKER_PASS)

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f

.PHONY: logs-%
logs-%: ## Tail logs for a specific service (make logs-php)
	$(DC) logs -f $*

##@ Database

.PHONY: db-dump
db-dump: ## Дамп БД стенда в ./backup/dump.sql.gz (перезаписывает предыдущий)
	$(DC) exec -T mariadb bash /scripts/create_dump.sh
	@# внутри контейнера дамп создаётся под root — отдаём владельца хост-юзеру,
	@# иначе следующий `>` в тот же файл упрётся в права.
	@$(DC) exec -T mariadb chown $(PUID):$(PGID) /backup/dump.sql.gz
	@ls -lh backup/dump.sql.gz

.PHONY: db-restore
db-restore: ## Восстановить БД стенда из ./backup/dump.sql.gz (дамп сам дропает таблицы)
	$(CONFIRM_PROD)
	$(DC) exec -T mariadb bash /scripts/restore.sh

# CNV-10: одноразовый контейнер minio/mc (НЕ docker compose — точечная transport-задача,
# см. workers/Makefile RUN_PYTEST_TEST для того же паттерна). Бакет/политика/версионирование —
# админ-шаги вне охвата (см. .claude/kanban/progress/CNV-10-db-backup-s3-pipeline.md).
MC_IMAGE ?= minio/mc:latest
DUMP_PREFIX ?= $(COMPOSE_PROJECT_NAME)

.PHONY: db-dump-push
db-dump-push: db-dump ## Свежий дамп + отгрузка в S3 ${S3_DUMP_BUCKET} новым ключом (никогда не перезаписывает)
	docker run --rm -u $(PUID):$(PGID) -e HOME=/tmp \
	    -v "$(CURDIR)/backup:/backup:ro" \
	    -v "$(CURDIR)/docker/mariadb/scripts:/scripts:ro" \
	    -e S3_ENDPOINT -e S3_KEY -e S3_SECRET -e S3_REGION -e S3_USE_PATH_STYLE \
	    -e S3_DUMP_BUCKET -e DUMP_PREFIX \
	    --entrypoint sh $(MC_IMAGE) /scripts/push_dump.sh

.PHONY: db-dump-pull
db-dump-pull: ## Скачать дамп из S3 в ./backup/dump.sql.gz (DUMP_KEY=<ключ> — конкретный, иначе последний по времени)
	docker run --rm -u $(PUID):$(PGID) -e HOME=/tmp \
	    -v "$(CURDIR)/backup:/backup" \
	    -v "$(CURDIR)/docker/mariadb/scripts:/scripts:ro" \
	    -e S3_ENDPOINT -e S3_KEY -e S3_SECRET -e S3_REGION -e S3_USE_PATH_STYLE \
	    -e S3_DUMP_BUCKET -e DUMP_PREFIX -e DUMP_KEY \
	    --entrypoint sh $(MC_IMAGE) /scripts/pull_dump.sh

##@ Testing (всё гоняется на ИЗОЛИРОВАННОМ тест-стенде из .env.test)

.PHONY: test
test: test-up ## Полный прогон: тест-стенд + PHPUnit + pytest воркеров + drift-guard
	$(MAKE_TEST) test-php test-python test-drift

.PHONY: smoke
smoke: ## Smoke e2e: 1 конвертация/категорию (doc/image/audio/video/data/ai) на тест-стенде
	$(MAKE_TEST) smoke-run

.PHONY: test-up
test-up: ## Поднять тест-стенд (свой compose-проект/порты/БД) + накатить миграции
	$(MAKE_TEST) up migrate

.PHONY: test-down
test-down: ## Снести тест-стенд вместе с томами (тест-БД и KeyDB стираются)
	$(MAKE_TEST) down-v

# ---------------------------------------------------------------------------
# Per-component fragments (переменные выше должны быть определены до include —
# фрагменты наследуют DC / MAKE_TEST / PHP_CONT / KEYDB_CONT / IMAGE_NS).
# ---------------------------------------------------------------------------
include app-symfony/Makefile
include workers/Makefile

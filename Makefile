SHELL = /bin/bash
### https://makefiletutorial.com/

HOST_NAME := $(shell hostname)
HOST_IP  := $(shell hostname -I 2>/dev/null | awk '{print $$1}' || echo unknown)
MYSQL_SLOWLOG_PATH := $(CURDIR)/docker/logs
JSON_LOG_PATH := $(CURDIR)/docker/logs
include .env
-include .env.local
export

DC         = docker compose
PHP_CONT   = $(COMPOSE_PROJECT_NAME)-php
KEYDB_CONT = $(COMPOSE_PROJECT_NAME)-keydb

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

##@ Logs

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f

.PHONY: logs-%
logs-%: ## Tail logs for a specific service (make logs-php)
	$(DC) logs -f $*

.PHONY: worker-logs
worker-logs: ## Tail logs for all worker services
	$(DC) logs -f worker-libreoffice worker-ffmpeg worker-image worker-ai worker-data

##@ PHP / Symfony

.PHONY: shell-php
shell-php: ## Open shell inside php-fpm container
	docker exec -it $(PHP_CONT) bash

.PHONY: migrate
migrate: ## Run Doctrine migrations (no interaction)
	docker exec $(PHP_CONT) php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: migrate-diff
migrate-diff: ## Generate a new Doctrine migration diff
	docker exec $(PHP_CONT) php bin/console doctrine:migrations:diff

.PHONY: seed-plans
seed-plans: ## Seed subscription plans
	docker exec $(PHP_CONT) php bin/console doctrine:fixtures:load --group=plans --no-interaction || \
	    docker exec $(PHP_CONT) php bin/console app:seed:plans || true

.PHONY: console
console: ## Run Symfony console command (make console CMD="debug:router")
	docker exec $(PHP_CONT) php bin/console $(CMD)

.PHONY: composer
composer: ## Run composer command (make composer CMD="require vendor/package")
	docker exec $(PHP_CONT) composer $(CMD)

##@ Testing

.PHONY: test
test: test-php test-python ## Run all tests (PHPUnit + pytest)

.PHONY: test-php
test-php: ## Run PHPUnit tests
	docker exec $(PHP_CONT) php vendor/bin/phpunit

.PHONY: test-python
test-python: ## Run pytest for all workers
	pytest workers/tests/ -v

.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	docker exec $(PHP_CONT) php vendor/bin/phpstan analyse

.PHONY: cs
cs: ## Fix code style with php-cs-fixer
	docker exec $(PHP_CONT) php vendor/bin/php-cs-fixer fix

.PHONY: cs-check
cs-check: ## Check code style with php-cs-fixer (no changes)
	docker exec $(PHP_CONT) php vendor/bin/php-cs-fixer fix --dry-run --diff

##@ Queue / Workers

.PHONY: queue-status
queue-status: ## Show queue lengths in KeyDB (db 2)
	@echo "=== Queue lengths (KeyDB db 2) ==="
	@docker exec $(KEYDB_CONT) keydb-cli -a "$(REDIS_PASSWORD)" -n $(REDIS_QUEUE_DB) \
	    eval "local ks=redis.call('keys','queue:*') local out={} for _,k in ipairs(ks) do out[#out+1]=k..': '..redis.call('llen',k) end return out" 0

##@ Docker auth

.PHONY: login
login: ## Login to Docker registry
	docker login $(DOCKER_HOST) -u $(DOCKER_USER) -p $(DOCKER_PASS)

##@ Build individual worker images

.PHONY: build-libreoffice
build-libreoffice: ## Build worker-libreoffice image
	docker build -t $(COMPOSE_PROJECT_NAME)/worker-libreoffice:latest \
	    -f docker/workers/libreoffice.Dockerfile .

.PHONY: build-ffmpeg
build-ffmpeg: ## Build worker-ffmpeg image
	docker build -t $(COMPOSE_PROJECT_NAME)/worker-ffmpeg:latest \
	    -f docker/workers/ffmpeg.Dockerfile .

.PHONY: build-image
build-image: ## Build worker-image image
	docker build -t $(COMPOSE_PROJECT_NAME)/worker-image:latest \
	    -f docker/workers/image.Dockerfile .

.PHONY: build-ai
build-ai: ## Build worker-ai image
	docker build -t $(COMPOSE_PROJECT_NAME)/worker-ai:latest \
	    -f docker/workers/ai.Dockerfile .

.PHONY: build-data
build-data: ## Build worker-data image
	docker build -t $(COMPOSE_PROJECT_NAME)/worker-data:latest \
	    -f docker/workers/data.Dockerfile .

.PHONY: build-php
build-php: ## PHP image is pulled from harbor (DOCKER_IMAGE_PHP), not built here
	@echo "php image is pulled from harbor: $(DOCKER_IMAGE_PHP) — run 'make pull'. No local docker/php/Dockerfile."

.PHONY: build-workers
build-workers: build-libreoffice build-ffmpeg build-image build-ai build-data ## Build all worker images

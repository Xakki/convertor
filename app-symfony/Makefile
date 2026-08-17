## Don't use alone — included into the parent Makefile

# Shared exec chains for the php container (PHP_CONT comes from the root Makefile).
PHP_EXEC = docker exec $(PHP_CONT)
CONSOLE  = $(PHP_EXEC) php bin/console

##@ PHP / Symfony

.PHONY: restart-php
restart-php: ## Restart только php + cron (напр. перечитать app-symfony/.env.local)
	$(DC) restart php cron

.PHONY: shell-php
shell-php: ## Open shell inside php-fpm container
	docker exec -it $(PHP_CONT) bash

.PHONY: migrate
migrate: ## Run Doctrine migrations (no interaction)
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: migrate-diff
migrate-diff: ## Generate a new Doctrine migration diff
	$(CONSOLE) doctrine:migrations:diff

.PHONY: console
console: ## Run Symfony console command (make console CMD="debug:router")
	$(CONSOLE) $(CMD)

.PHONY: tg-set-webhook
tg-set-webhook: ## Register Telegram bot webhook (URL from API_URL + TELEGRAM_WEBHOOK_SECRET)
	$(CONSOLE) app:telegram:set-webhook $(API_URL)

.PHONY: tg-set-commands
tg-set-commands: ## Register Telegram bot command menu (/start, /help, /convert, /balance, /topup)
	$(CONSOLE) app:telegram:set-commands

.PHONY: seed-examples
seed-examples: ## Seed curated landing examples via the REAL pipeline into examples/ S3 (needs workers+ws-gateway up)
	$(CONSOLE) app:examples:seed

.PHONY: clean-test-data
clean-test-data: ## Чистка ручного мусора в ОСНОВНОЙ БД convertor (conversions/payments/social_identities/file_storage + не-админ users; админ и конфиг остаются). Голый вызов = dry-run; FORCE=1 — реальное удаление
ifeq ($(FORCE),1)
	$(CONFIRM_PROD)
	$(CONSOLE) app:clean-test-data --force
else
	@echo -e "$(BOLD)Bare 'make clean-test-data' only shows the plan (dry-run). Pass FORCE=1 to actually delete: make clean-test-data FORCE=1$(RESET)"
	$(CONSOLE) app:clean-test-data --dry-run
endif

TTL_HOURS ?= $(WORKER_CAPABILITY_GC_TTL_HOURS)

.PHONY: worker-capability-gc
worker-capability-gc: ## Синхронно удалить устаревшие worker_capabilities (TTL_HOURS=)
	$(CONFIRM_PROD)
	$(CONSOLE) app:worker-capability:gc --ttl-hours=$(TTL_HOURS)

.PHONY: composer
composer: ## Run composer command (make composer CMD="require vendor/package")
	$(PHP_EXEC) composer $(CMD)

##@ PHP / Quality

.PHONY: test-php
test-php: ## Run PHPUnit (в php-контейнере тест-стенда; phpunit форсит APP_ENV=test). FILTER=<regex> — сузить до совпадающих тестов
	$(REQUIRE_TEST)
	$(PHP_EXEC) php vendor/bin/phpunit $(if $(FILTER),--filter="$(FILTER)")

.PHONY: test-php-unit
test-php-unit: ## Fast PHPUnit: only tests/Unit (no test-stand/DB)
	$(PHP_EXEC) php vendor/bin/phpunit tests/Unit

.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	$(PHP_EXEC) php vendor/bin/phpstan analyse
	$(PHP_EXEC) php vendor/bin/phpstan analyse -c phpstan-migrations.neon

.PHONY: cs
cs: ## Fix code style with php-cs-fixer
	$(PHP_EXEC) php vendor/bin/php-cs-fixer fix --allow-risky=yes --sequential --show-progress=none

.PHONY: cs-check
cs-check: ## Check code style with php-cs-fixer (no changes)
	$(PHP_EXEC) php vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes --sequential --show-progress=none

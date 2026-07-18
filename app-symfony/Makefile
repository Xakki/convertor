## Don't use alone — included into the parent Makefile

# Shared exec chains for the php container (PHP_CONT comes from the root Makefile).
PHP_EXEC = docker exec $(PHP_CONT)
CONSOLE  = $(PHP_EXEC) php bin/console

##@ PHP / Symfony

.PHONY: restart-php
restart-php: ## Restart only php + cron (e.g. to reload app-symfony/.env.local)
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
tg-set-commands: ## Register Telegram bot command menu (/start, /help, /convert)
	$(CONSOLE) app:telegram:set-commands

.PHONY: composer
composer: ## Run composer command (make composer CMD="require vendor/package")
	$(PHP_EXEC) composer $(CMD)

.PHONY: build-php
build-php: ## PHP image is pulled from harbor (DOCKER_IMAGE_PHP), not built here
	@echo "php image is pulled from harbor: $(DOCKER_IMAGE_PHP) — run 'make pull'. No local docker/php/Dockerfile."

##@ PHP / Quality

.PHONY: test-php
test-php: ## Run PHPUnit tests
	$(PHP_EXEC) php vendor/bin/phpunit

.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	$(PHP_EXEC) php vendor/bin/phpstan analyse

.PHONY: cs
cs: ## Fix code style with php-cs-fixer
	$(PHP_EXEC) php vendor/bin/php-cs-fixer fix --allow-risky=yes

.PHONY: cs-check
cs-check: ## Check code style with php-cs-fixer (no changes)
	$(PHP_EXEC) php vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes

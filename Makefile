SHELL := bash
.SHELLFLAGS := -eu -o pipefail -c
MAKEFLAGS += --warn-undefined-variables
DOCKER_COMPOSE := docker compose

include .env

help:
	@echo -e "\033[0;32m Usage: make [target] "
	@echo
	@echo -e "\033[1m targets:\033[0m"
	@egrep '^(.+):*\ ##\ (.+)' ${MAKEFILE_LIST} | column -t -c 2 -s ':#'
.PHONY: help

<---docker------->: ## -----------------------------------------------------------------------
clean: ## Stop containers and clean up volumes
	@echo "Cleaning up containers and volumes..."
	@$(DOCKER_COMPOSE) down -v --remove-orphans
	@echo "Cleanup complete."
.PHONY: clean

<---composer----->: ## -----------------------------------------------------------------------
install: ## Run composer install
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli composer install --no-cache
.PHONY: install

update: ## Run composer update
	$(DOCKER_COMPOSE) run --rm --no-deps -e XDEBUG_MODE=off phpcli composer update
.PHONY: update

autoload: ## Run composer dump-autoload
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli composer dumpautoload
.PHONY: autoload

<---qa tools----->: ## -----------------------------------------------------------------------
phpunit: ## Run all tests
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests
.PHONY: phpunit

phpunit-unit: ## Run unit tests only
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpunit --testsuite "Unit Tests"
.PHONY: phpunit-unit

phpunit-coverage: ## Run all tests with coverage text
	$(DOCKER_COMPOSE) run --rm --no-deps -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-text
.PHONY: phpunit-coverage

phpunit-coverage-html: ## Run all tests with HTML coverage
	$(DOCKER_COMPOSE) run --rm --no-deps -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-html tests/reports/coverage-html
.PHONY: phpunit-coverage-html

phpstan: ## Run PHPStan analysis
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpstan analyse /app/src -c phpstan.neon
.PHONY: phpstan

phpcs: ## Run coding standards
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpcs /app/src
.PHONY: phpcs

<---development----->: ## -----------------------------------------------------------------------
shell: ## Run a shell inside the phpcli container
	$(DOCKER_COMPOSE) run --rm --no-deps -it phpcli sh
.PHONY: shell

<---hooks-------->: ## -----------------------------------------------------------------------
install-hooks: ## Install git hooks (pre-commit + pre-push)
	@echo '#!/bin/bash' > .git/hooks/pre-commit
	@echo 'bash ./support/pre-commit-hook.sh' >> .git/hooks/pre-commit
	@chmod +x .git/hooks/pre-commit
	@echo '#!/bin/bash' > .git/hooks/pre-push
	@echo '# Jardis Pre-Push Hook — Quality Gate' >> .git/hooks/pre-push
	@echo 'set -e' >> .git/hooks/pre-push
	@echo 'echo "=== Jardis Pre-Push Quality Gate ==="' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpcs"' >> .git/hooks/pre-push
	@echo 'make phpcs || { echo "PHPCS fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpstan"' >> .git/hooks/pre-push
	@echo 'make phpstan || { echo "PHPStan fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'if [ -d "tests" ]; then' >> .git/hooks/pre-push
	@echo '  echo ">>> make phpunit"' >> .git/hooks/pre-push
	@echo '  make phpunit || { echo "PHPUnit fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'else' >> .git/hooks/pre-push
	@echo '  echo ">>> make phpunit: uebersprungen (Interface-Projekt)"' >> .git/hooks/pre-push
	@echo 'fi' >> .git/hooks/pre-push
	@echo 'echo "=== Quality Gate bestanden ==="' >> .git/hooks/pre-push
	@chmod +x .git/hooks/pre-push
	@echo "Hooks installed."
.PHONY: install-hooks

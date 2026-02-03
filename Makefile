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

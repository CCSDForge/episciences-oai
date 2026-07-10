# Variables
DOCKER_COMPOSE := docker compose
EXEC_PHP       := $(DOCKER_COMPOSE) exec web

# Default target
.DEFAULT_GOAL := help

.PHONY: help test phpstan up build down shell composer-update composer-install

help: ## Show this help message
	@echo "📡 Episciences OAI-PMH Service"
	@echo ""
	@echo "Usage: make [target] [options]"
	@echo ""
	@echo "Targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "Examples:"
	@echo "  make test target=tests/Controller/OaiPmhControllerTest.php"
	@echo "  make phpstan target=src/Controller/OaiPmhController.php"
	@echo ""

up: ## Start the Docker containers in detached mode
	$(DOCKER_COMPOSE) up -d
	@echo ""
	@echo "🌐 OAI-PMH Service is running locally at:"
	@echo "   👉 https://oaing-dev.episciences.org/"
	@echo "   👉 https://oaing-dev.episciences.org/?verb=Identify"
	@echo ""

build: ## Build or rebuild Docker services
	$(DOCKER_COMPOSE) build

down: ## Stop and remove Docker containers
	$(DOCKER_COMPOSE) down

shell: ## Start an interactive bash session inside the web container
	$(DOCKER_COMPOSE) exec web bash

composer-install: ## Install composer dependencies inside the container
	$(EXEC_PHP) php composer.phar install --no-progress --prefer-dist

composer-update: ## Update composer dependencies inside the container
	$(EXEC_PHP) php composer.phar update --no-progress --prefer-dist

test: ## Run PHPUnit tests. Usage: make test [target=<file|dir>]
ifdef target
	$(EXEC_PHP) vendor/bin/phpunit $(target)
else
	$(EXEC_PHP) vendor/bin/phpunit
endif

phpstan: ## Run PHPStan static analysis. Usage: make phpstan [target=<file|dir>]
ifdef target
	$(EXEC_PHP) vendor/bin/phpstan analyse $(target) --memory-limit=1G
else
	$(EXEC_PHP) vendor/bin/phpstan analyse --memory-limit=1G
endif

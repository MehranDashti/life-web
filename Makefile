# LifeWeb — developer entrypoints.
# Everything runs against Docker Compose unless suffixed with `-local`.

SHELL := /bin/bash
DC    := docker compose
APP   := $(DC) exec -T app

.DEFAULT_GOAL := help
.PHONY: help build up down restart logs shell install key migrate fresh seed \
        index synthetic test unit feature integration lint lint-fix analyse \
        rector rector-fix ci loadtest loadtest-steady bench hooks

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## ---------------------------------------------------------------- environment
build: ## Build the application image
	$(DC) build

up: ## Start mysql, redis, elasticsearch, app, queue, scheduler
	$(DC) up -d
	@echo "API: http://localhost:$${APP_PORT:-9900}/api/v1/up"

down: ## Stop everything (keeps volumes)
	$(DC) down

restart: ## Recreate the app, queue and scheduler containers
	$(DC) up -d --force-recreate app queue scheduler

logs: ## Tail application logs
	$(DC) logs -f app queue scheduler

shell: ## Open a shell in the app container
	$(DC) exec app bash

## ---------------------------------------------------------------- application
install: ## Install composer dependencies inside the container
	$(APP) composer install

key: ## Generate APP_KEY and Passport signing keys
	$(APP) php artisan key:generate
	$(APP) php artisan passport:keys --force

migrate: ## Run migrations
	$(APP) php artisan migrate --force

fresh: ## Drop, re-migrate and re-seed
	$(APP) php artisan migrate:fresh --seed --force

seed: ## Seed the demo user
	$(APP) php artisan db:seed --force

index: ## Create the posts index template and import data.json
	$(APP) php artisan posts:index data.json

synthetic: ## Generate N synthetic posts for the benchmark (make synthetic N=100000)
	$(APP) php artisan posts:synthetic $(or $(N),10000)

## ---------------------------------------------------------------------- tests
test: unit feature ## Unit + feature suites (no infrastructure needed)

unit: ## Unit suite
	$(APP) php artisan test --testsuite=Unit

feature: ## Feature suite
	$(APP) php artisan test --testsuite=Feature

integration: ## Integration suite — requires a live Elasticsearch
	$(APP) php artisan test --testsuite=Integration

## ------------------------------------------------------------------- quality
lint: ## Check code style (no changes)
	vendor/bin/pint --test

lint-fix: ## Fix code style
	vendor/bin/pint

analyse: ## Static analysis (PHPStan level 6 via Larastan)
	vendor/bin/phpstan analyse --memory-limit=1G

rector: ## Report refactors Rector would apply
	vendor/bin/rector process --dry-run

rector-fix: ## Apply Rector refactors
	vendor/bin/rector process

ci: lint analyse rector ## The full gate — run before every push
	@composer validate --strict --no-check-publish
	php artisan test

## ----------------------------------------------------------------- benchmark
# DOCKER_UID/GID are passed so k6 can write its results into the bind mount.
loadtest: ## Run the k6 ramp scenario against the running stack
	DOCKER_UID=$(shell id -u) DOCKER_GID=$(shell id -g) $(DC) --profile loadtest run --rm k6 \
		run --summary-export=/scripts/results/k6-report-api.json /scripts/report-api.js

loadtest-steady: ## Run the fixed-rate read scenario
	DOCKER_UID=$(shell id -u) DOCKER_GID=$(shell id -g) $(DC) --profile loadtest run --rm k6 \
		run --summary-export=/scripts/results/k6-list-reports.json /scripts/list-reports.js

bench: ## Sweep the corpus and measure query + generation time (long; flushes the index)
	$(APP) php artisan bench:search --sizes=$(or $(SIZES),10000,100000,1000000)
	$(APP) php artisan bench:report --sizes=$(or $(SIZES),10000,100000,1000000)
	@echo "Raw results in loadtest/results/. Re-seed the demo corpus with: make index" 

hooks: ## Install the repo's git hooks
	git config core.hooksPath .githooks
	@echo "core.hooksPath -> .githooks"

COMPOSE ?= docker compose
SERVICE ?= app
TAIL    ?= 200
BACKUP_DIR ?= backups

# Read DB creds from .env (used by db-* targets)
-include .env
export

.DEFAULT_GOAL := help

.PHONY: help setup env up start build rebuild down stop restart ps logs logs-app logs-nginx logs-db \
        shell sh php-shell db-shell redis-cli table webhook \
        cert cert-renew cert-info nginx-reload nginx-test \
        pma-up pma-down db-backup db-restore \
        pull update clean nuke

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "Usage: make \033[36m<target>\033[0m\n\n"} \
		/^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

##@ Setup
setup: env up ## First run: create .env (if missing) and start stack
	@echo "Edit .env first if this was a fresh copy, then: make restart"

env: ## Create .env from .env.example if missing
	@test -f .env || (cp .env.example .env && echo "Created .env — set DOMAIN, MYSQL_*, TELEGRAM_*")

##@ Stack
up: env ## Build and start all services (detached)
	$(COMPOSE) up -d --build

start: ## Start existing containers
	$(COMPOSE) start

build: ## Build images
	$(COMPOSE) build

rebuild: ## Rebuild app image without cache and recreate
	$(COMPOSE) build --no-cache app
	$(COMPOSE) up -d app

down: ## Stop and remove containers (keeps volumes)
	$(COMPOSE) down

stop: ## Stop containers
	$(COMPOSE) stop

restart: ## Restart all services (or SERVICE=app)
	$(COMPOSE) restart $(if $(filter command line,$(origin SERVICE)),$(SERVICE),)

ps: ## Show service status
	$(COMPOSE) ps

##@ Logs
logs: ## Tail logs of all services (TAIL=200)
	$(COMPOSE) logs -f --tail=$(TAIL)

logs-app: ## Tail app (php-fpm) logs
	$(COMPOSE) logs -f --tail=$(TAIL) app

logs-nginx: ## Tail nginx logs
	$(COMPOSE) logs -f --tail=$(TAIL) nginx

logs-db: ## Tail MySQL logs
	$(COMPOSE) logs -f --tail=$(TAIL) db

##@ Shells
shell: ## Shell in a service (SERVICE=app)
	$(COMPOSE) exec $(SERVICE) sh -c 'command -v bash >/dev/null && exec bash || exec sh'

sh: shell ## Alias for shell

php-shell: ## Shell in app container as www-data
	$(COMPOSE) exec -u www-data app bash

db-shell: ## MySQL client as app user
	$(COMPOSE) exec db mysql -u$(MYSQL_USER) -p$(MYSQL_PASSWORD) $(MYSQL_DATABASE)

redis-cli: ## Redis CLI
	$(COMPOSE) exec redis redis-cli

##@ App
table: ## Run DB schema create/migrate (table.php) twice
	$(COMPOSE) exec -u www-data app php table.php
	$(COMPOSE) exec -u www-data app php table.php

webhook: ## Re-register Telegram webhook
	$(COMPOSE) exec app php docker/php-set-webhook.php

##@ TLS / nginx
cert: ## Issue real Let's Encrypt cert, then reload nginx
	$(COMPOSE) --profile certissue run --rm certissue
	$(COMPOSE) exec nginx nginx -s reload

cert-renew: ## Force certbot renew and reload nginx
	$(COMPOSE) exec certbot certbot renew --webroot -w /var/www/certbot
	$(COMPOSE) exec nginx nginx -s reload

cert-info: ## List installed certificates
	$(COMPOSE) exec certbot certbot certificates

nginx-test: ## Test nginx config
	$(COMPOSE) exec nginx nginx -t

nginx-reload: nginx-test ## Reload nginx
	$(COMPOSE) exec nginx nginx -s reload

##@ phpMyAdmin
pma-up: ## Start phpMyAdmin (profile)
	$(COMPOSE) --profile phpmyadmin up -d phpmyadmin

pma-down: ## Stop phpMyAdmin
	$(COMPOSE) --profile phpmyadmin stop phpmyadmin

##@ Database
db-backup: ## Dump DB to $(BACKUP_DIR)/<db>-<timestamp>.sql.gz
	@mkdir -p $(BACKUP_DIR)
	$(COMPOSE) exec -T db mysqldump -uroot -p$(MYSQL_ROOT_PASSWORD) --single-transaction --routines $(MYSQL_DATABASE) \
		| gzip > $(BACKUP_DIR)/$(MYSQL_DATABASE)-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -1t $(BACKUP_DIR) | head -1 | sed 's|^|Saved $(BACKUP_DIR)/|'

db-restore: ## Restore DB: make db-restore FILE=backups/x.sql.gz
	@test -n "$(FILE)" || (echo "Usage: make db-restore FILE=path.sql.gz" && exit 1)
	@printf "Overwrite database '$(MYSQL_DATABASE)' from $(FILE)? [y/N] " && read ans && [ "$$ans" = y ]
	gunzip -c $(FILE) | $(COMPOSE) exec -T db mysql -uroot -p$(MYSQL_ROOT_PASSWORD) $(MYSQL_DATABASE)

##@ Maintenance
pull: ## Pull latest base images
	$(COMPOSE) pull --ignore-buildable

update: ## git pull, rebuild, restart app
	git pull --ff-only
	$(COMPOSE) up -d --build

clean: ## Remove stopped containers and dangling images
	$(COMPOSE) rm -f
	docker image prune -f

nuke: ## DANGER: remove containers AND volumes (DB + certs)
	@printf "This DELETES the database and certificates. Type 'yes': " && read ans && [ "$$ans" = yes ]
	$(COMPOSE) down -v

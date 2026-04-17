SHELL := /bin/bash
DOCKER_COMPOSE := docker compose

.PHONY: up down restart build logs ps backend-shell crawler-shell mysql-shell redis-cli

up:
	$(DOCKER_COMPOSE) up -d --build

down:
	$(DOCKER_COMPOSE) down

restart: down up

build:
	$(DOCKER_COMPOSE) build

logs:
	$(DOCKER_COMPOSE) logs -f --tail=100

ps:
	$(DOCKER_COMPOSE) ps

backend-shell:
	$(DOCKER_COMPOSE) exec php-fpm bash

crawler-shell:
	$(DOCKER_COMPOSE) exec crawler bash

mysql-shell:
	$(DOCKER_COMPOSE) exec mysql mysql -u"$${MYSQL_USER:-app}" -p"$${MYSQL_PASSWORD:-app}" "$${MYSQL_DATABASE:-commerce_leads}"

redis-cli:
	$(DOCKER_COMPOSE) exec redis redis-cli

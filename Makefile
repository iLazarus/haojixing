SHELL := /bin/bash

.PHONY: init-perms dev-up dev-down dev-logs debug-up prod-up ps shell-app artisan composer migrate test health validate smoke smoke-verbose

init-perms:
	mkdir -p storage bootstrap/cache
	@if [ "$$(podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null)" = "true" ]; then \
		podman unshare chown -R $$(id -u):$$(id -g) storage bootstrap/cache; \
	else \
		chown -R $$(id -u):$$(id -g) storage bootstrap/cache; \
	fi

dev-up:
	podman-compose up -d --build

dev-down:
	podman-compose down

dev-logs:
	podman-compose logs -f --tail=200

debug-up:
	ENABLE_XDEBUG=1 XDEBUG_MODE=debug,develop podman-compose up -d --build

prod-up:
	APP_ENV=production APP_DEBUG=false ENABLE_XDEBUG=0 XDEBUG_MODE=off podman-compose up -d --build

ps:
	podman-compose ps

shell-app:
	podman-compose exec app sh

artisan:
	podman-compose exec app php artisan $(cmd)

composer:
	podman-compose exec app composer $(cmd)

migrate:
	podman-compose exec app php artisan migrate --force

test:
	podman-compose exec app php artisan test

health:
	podman-compose ps
	podman healthcheck run haojixing-app || true
	podman healthcheck run haojixing-nginx || true
	podman healthcheck run haojixing-db || true
	podman healthcheck run haojixing-redis || true

validate:
	podman-compose --dry-run -f compose.yaml up -d

smoke:
	curl -fsS http://127.0.0.1:$${APP_PORT:-9001}/healthz >/dev/null
	curl -fsS http://127.0.0.1:$${APP_PORT:-9001}/api/v1/groups >/dev/null
	podman exec haojixing-app php artisan route:list --path=api >/dev/null
	@echo "smoke ok"

smoke-verbose:
	@echo "[1/7] container status" && podman ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | sed -n '1,20p'
	@echo "[2/7] healthz" && curl -i -s http://127.0.0.1:$${APP_PORT:-9001}/healthz | sed -n '1,12p'
	@echo "[3/7] groups api" && curl -i -s http://127.0.0.1:$${APP_PORT:-9001}/api/v1/groups | sed -n '1,20p'
	@echo "[4/7] php extensions" && podman exec haojixing-app php -m | grep -E 'pdo_pgsql|pgsql|pdo_sqlite'
	@echo "[5/7] db probe"
	@podman exec haojixing-app php -r 'try { new PDO(getenv("DB_CONNECTION") . ":host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo "db ok\n"; } catch (Exception $e) { echo "db fail\n"; exit(1); }'
	@echo "[6/7] db tables + counts + last row" && podman exec haojixing-app php /var/www/html/db_tables_probe.php
	@echo "[7/7] api routes count" && podman exec haojixing-app php artisan route:list --path=api | wc -l
	@echo "smoke-verbose ok"

#!/usr/bin/env bash
# Inventoros container entrypoint: prepare the app on first boot, then exec the server.
set -euo pipefail

cd /app

# Generate .env from the example on first boot
if [ ! -f .env ]; then
    cp .env.example .env
    echo "[entrypoint] copied .env.example -> .env"
fi

# One APP_KEY for every container of the stack, kept in the shared storage
# volume. Each container gets its own .env (from .env.example), so without
# this the app, worker and scheduler each generated a different key: the
# worker could not decrypt what the app encrypted, and every
# `docker compose up --build` replaced the key (logging everyone out and
# making stored secrets unreadable). An APP_KEY set in the environment wins.
key_file=storage/.docker-app-key
if [ -z "${APP_KEY:-}" ]; then
    if [ -s "$key_file" ]; then
        key=$(cat "$key_file")
        if grep -qE '^APP_KEY=' .env; then
            sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" .env
        else
            printf '\nAPP_KEY=%s\n' "$key" >> .env
        fi
        echo "[entrypoint] using the stack's shared APP_KEY"
    else
        if ! grep -qE '^APP_KEY=base64:' .env; then
            php artisan key:generate --force --no-interaction
            echo "[entrypoint] generated APP_KEY"
        fi
        grep -E '^APP_KEY=' .env | head -n1 | cut -d= -f2- > "$key_file"
        chmod 600 "$key_file" || true
        echo "[entrypoint] saved APP_KEY to ${key_file} for the other containers"
    fi
fi

# The database settings: the environment (docker-compose.yml) wins over .env.
env_value() {
    { grep -E "^$1=" .env || true; } | tail -n1 | cut -d= -f2- | tr -d '"'
}
db_connection="${DB_CONNECTION:-$(env_value DB_CONNECTION)}"

# SQLite: make sure the DB file exists so migrations don't fail
if [ "$db_connection" = "sqlite" ]; then
    mkdir -p database
    touch database/database.sqlite
fi

# Wait for a database server if one is configured (max 30s)
case "$db_connection" in
    pgsql|mysql|mariadb)
        db_host="${DB_HOST:-$(env_value DB_HOST)}"
        db_port="${DB_PORT:-$(env_value DB_PORT)}"
        if [ "$db_connection" = "pgsql" ]; then
            db_host=${db_host:-postgres}; db_port=${db_port:-5432}
        else
            db_host=${db_host:-mysql}; db_port=${db_port:-3306}
        fi
        echo "[entrypoint] waiting for ${db_connection} at ${db_host}:${db_port}…"
        for i in $(seq 1 30); do
            if (echo > "/dev/tcp/${db_host}/${db_port}") >/dev/null 2>&1; then
                echo "[entrypoint] database ready"
                break
            fi
            sleep 1
        done
        ;;
esac

# Run migrations (idempotent) and create the storage symlink.
# Worker / scheduler containers set RUN_MIGRATIONS=false so only the app
# container owns schema changes — avoids concurrent migrate races on SQLite.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction || true
    php artisan storage:link --force --no-interaction || true
else
    echo "[entrypoint] RUN_MIGRATIONS=false — skipping migrate/storage:link (app container owns schema)"
fi

# Recompile config in dev (cleared so .env edits take effect on container restart)
php artisan config:clear --no-interaction || true
php artisan view:clear --no-interaction || true

echo "[entrypoint] ready — handing off to: $*"
exec "$@"

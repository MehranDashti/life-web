#!/usr/bin/env bash
set -euo pipefail

# Wait for the services the app cannot boot without. Elasticsearch is deliberately
# NOT waited on — search backs reporting, not the core API, and the health endpoint
# reports it separately so the container still starts during an ES outage.
wait_for() {
    local host="$1" port="$2" label="$3" attempts="${4:-60}"
    for ((i = 1; i <= attempts; i++)); do
        if (echo > "/dev/tcp/${host}/${port}") >/dev/null 2>&1; then
            echo "[entrypoint] ${label} is up"
            return 0
        fi
        sleep 1
    done
    echo "[entrypoint] timed out waiting for ${label} at ${host}:${port}" >&2
    return 1
}

[ -f .env ] || cp .env.example .env

wait_for "${DB_HOST:-mysql}" "${DB_PORT:-3306}" "mysql"
wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "redis"

php artisan config:clear
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

# Passport signing keys. Idempotent: --force only rewrites when absent.
[ -f storage/oauth-private.key ] || php artisan passport:keys --force

php artisan migrate --force

# Idempotent: creates the OAuth personal access client if it isn't there yet.
php artisan db:seed --class=PassportClientSeeder --force
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"

#!/usr/bin/env bash
set -euo pipefail

# Migrating is opt-in and defaults to OFF. The flag is the portable primitive: in
# Compose it designates the one-shot `migrate` service, in Kubernetes the same flag
# designates an init Job. Defaulting to off means a replica added to a running
# system waits for the schema rather than mutating it.
RUN_MIGRATIONS="${APP_RUN_MIGRATIONS:-false}"
SCHEMA_WAIT_ATTEMPTS="${APP_SCHEMA_WAIT_ATTEMPTS:-120}"

fail() {
    echo "[entrypoint] $1" >&2
    exit 1
}

# APP_ENV may arrive as a real environment variable (Kubernetes) or only inside a
# mounted .env (Compose), and the guards below have to agree with the framework
# about which environment this is.
detect_environment() {
    if [ -n "${APP_ENV:-}" ]; then
        echo "${APP_ENV}"
    elif [ -f .env ]; then
        sed -n 's/^APP_ENV=//p' .env | head -1 | tr -d '"' | tr -d "'"
    else
        echo "local"
    fi
}

APP_ENVIRONMENT="$(detect_environment)"
[ -n "${APP_ENVIRONMENT}" ] || APP_ENVIRONMENT="local"

is_production() {
    [ "${APP_ENVIRONMENT}" = "production" ]
}

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

# A container that does not own the schema waits for whoever does, rather than
# starting against an absent schema and crash-looping. A crash loop converges too,
# but it is indistinguishable in the logs from a real failure and burns the
# orchestrator's restart budget. This says what it is waiting for.
wait_for_schema() {
    for ((i = 1; i <= SCHEMA_WAIT_ATTEMPTS; i++)); do
        if php artisan migrate:status >/dev/null 2>&1; then
            echo "[entrypoint] schema is ready"
            return 0
        fi
        sleep 1
    done
    fail "timed out after ${SCHEMA_WAIT_ATTEMPTS}s waiting for the database schema. Set APP_RUN_MIGRATIONS=true on exactly one service so something migrates."
}

# Production must be configured, not guessed at. A container that boots on example
# defaults and self-generates a key serves wrong behaviour quietly; one that
# refuses to start fails once, loudly, at deploy time.
if [ ! -f .env ]; then
    if is_production; then
        fail "APP_ENV=production but no .env is mounted. Refusing to boot on .env.example defaults."
    fi
    cp .env.example .env
fi

wait_for "${DB_HOST:-mysql}" "${DB_PORT:-3306}" "mysql"
wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "redis"

php artisan config:clear

# The key may be injected as an environment variable rather than written to .env,
# so both are checked before concluding there isn't one.
if ! grep -q '^APP_KEY=base64:' .env && [ -z "${APP_KEY:-}" ]; then
    if is_production; then
        fail "APP_ENV=production but APP_KEY is not set. Refusing to generate one: a per-container key cannot decrypt anything another container wrote."
    fi
    php artisan key:generate --force
fi

# Passport signing keys must be identical across instances. Generating them per
# container is silently wrong the moment there is more than one: a token issued by
# one instance fails verification on the next, producing intermittent 401s that
# depend on which instance answered.
if [ -z "${PASSPORT_PRIVATE_KEY:-}" ] && [ -z "${PASSPORT_PUBLIC_KEY:-}" ]; then
    if is_production; then
        fail "APP_ENV=production but PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY are not set. Refusing to generate a per-container keypair; inject the existing keys as secrets."
    fi
    # Idempotent outside production: --force only rewrites when absent.
    [ -f storage/oauth-private.key ] || php artisan passport:keys --force
fi

if [ "${RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate:locked
    # Seeding the personal access client is a schema-adjacent write, so it belongs
    # to whoever owns the migration step rather than racing in every container.
    php artisan db:seed --class=PassportClientSeeder --force
else
    wait_for_schema
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"

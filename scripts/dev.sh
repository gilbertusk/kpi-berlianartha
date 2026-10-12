#!/usr/bin/env bash
# Developer commands for the Docker Compose environment.
# Works on Linux/macOS and on Windows through Git Bash (or scripts/dev.ps1).
#
# Usage: bash scripts/dev.sh <command>
#   bootstrap     create .env with generated local secrets, build, start, migrate, verify
#   up | down     start (waiting for healthchecks) | stop the environment (volumes kept)
#   ps | logs     service status | follow logs (optionally: logs <service>)
#   migrate       run pending migrations
#   seed          run development seeders (APP_ENV=local only)
#   test          run the test suite (in-memory SQLite) inside the app container
#   test-pgsql    run the test suite against the PostgreSQL kpi_test database and Redis
#   lint          Pint format check
#   analyse       Larastan static analysis
#   build         rebuild Vite assets (npm ci + npm run build) in the assets container
#   queue-check   dispatch a probe job and verify the worker processes it exactly once
#   readiness     check database, cache, and object storage from the app container
#   smoke         end-to-end health evidence (HTTP, services, storage privacy, queue)
#   reset-db      DROP all tables of the LOCAL database and migrate again (asks confirmation)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# Git Bash would otherwise rewrite container paths such as /var/www/html.
export MSYS_NO_PATHCONV=1

ENV_FILE=.env
APP_PORT_DEFAULT=8088

compose() {
    docker compose "$@"
}

exec_app() {
    compose exec -T app "$@"
}

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

random_hex() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex "$1"
    else
        head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

random_base64() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 "$1"
    else
        head -c "$1" /dev/urandom | base64
    fi
}

env_value() {
    local key="$1" default="${2:-}" value
    value="$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"\r' || true)"
    printf '%s' "${value:-$default}"
}

# Sets KEY=value only when the key is missing or empty, so existing local
# values (and real secrets) are never overwritten.
env_set_if_empty() {
    local key="$1" value="$2"

    if [ -n "$(env_value "$key")" ]; then
        return
    fi

    if grep -qE "^${key}=" "$ENV_FILE"; then
        local tmp
        tmp="$(mktemp)"
        awk -v k="$key" -v v="$value" 'BEGIN { FS = OFS = "=" } $1 == k { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE"
        rm -f "$tmp"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi

    info "$key dibuat di $ENV_FILE"
}

ensure_env() {
    if [ ! -f "$ENV_FILE" ]; then
        cp .env.example "$ENV_FILE"
        info "$ENV_FILE dibuat dari .env.example"
    fi

    env_set_if_empty APP_KEY "base64:$(random_base64 32)"
    env_set_if_empty DB_PASSWORD "$(random_hex 24)"
    env_set_if_empty AWS_ACCESS_KEY_ID "kpi$(random_hex 8)"
    env_set_if_empty AWS_SECRET_ACCESS_KEY "$(random_hex 24)"
    env_set_if_empty AWS_BUCKET "kpi-local"

    # Bind-mounted files must be writable by the container user on Linux/macOS.
    case "$(uname -s)" in
        Linux | Darwin)
            env_set_if_empty DOCKER_UID "$(id -u)"
            env_set_if_empty DOCKER_GID "$(id -g)"
            ;;
    esac
}

cmd_bootstrap() {
    command -v docker >/dev/null 2>&1 || fail "Docker tidak ditemukan."
    ensure_env

    info "Membangun image development"
    compose build app

    info "Menjalankan seluruh service dan menunggu healthcheck"
    compose up -d --wait

    cmd_migrate
    cmd_smoke
}

cmd_up() {
    compose up -d --wait
    compose ps
}

cmd_down() {
    compose down
}

cmd_migrate() {
    info "Menjalankan migration"
    exec_app php artisan migrate --force --no-interaction
}

cmd_seed() {
    [ "$(env_value APP_ENV)" = "local" ] || fail "Seeder development hanya untuk APP_ENV=local."
    exec_app php artisan db:seed --no-interaction
}

cmd_test() {
    # The full suite compiles every Livewire page in one process. Give the
    # test runner headroom without changing the 256M production PHP limit.
    exec_app php -d memory_limit=512M artisan test "$@"
}

cmd_test_pgsql() {
    # Tests are refused unless the database name ends with _test (tests/TestCase.php).
    compose exec -T \
        -e DB_CONNECTION=pgsql \
        -e DB_DATABASE=kpi_test \
        -e CACHE_STORE=redis \
        -e REDIS_DB=13 \
        -e REDIS_CACHE_DB=14 \
        app php -d memory_limit=512M artisan test "$@"
}

cmd_lint() {
    exec_app vendor/bin/pint --test
}

cmd_analyse() {
    exec_app vendor/bin/phpstan analyse --no-progress --memory-limit=1G
}

cmd_build() {
    compose run --rm --no-deps assets sh -c "npm ci --no-audit --no-fund && npm run build"
}

cmd_queue_check() {
    exec_app php artisan ops:queue-smoke --timeout=30
}

cmd_readiness() {
    exec_app php artisan ops:readiness --disk=s3
}

cmd_smoke() {
    local port bucket status
    port="$(env_value APP_PORT "$APP_PORT_DEFAULT")"
    bucket="$(env_value AWS_BUCKET kpi-local)"

    info "Status service"
    compose ps --format 'table {{.Service}}\t{{.State}}\t{{.Health}}'

    info "HTTP GET /health melalui nginx"
    curl -fsS "http://127.0.0.1:${port}/health"
    echo

    info "Readiness dependency (database, cache Redis, object storage)"
    cmd_readiness

    info "Bucket object storage tidak dapat diakses anonim"
    # Body and status are captured together (no -o /dev/null: Windows curl with
    # MSYS_NO_PATHCONV cannot open it); the last three characters are the status.
    status="$(curl -s -w '%{http_code}' "http://127.0.0.1:$(env_value S3_PORT 8333)/${bucket}/" | tail -c 3)"
    [ "$status" = "403" ] || fail "Akses anonim ke bucket menghasilkan HTTP ${status}, seharusnya 403."
    echo "HTTP ${status} (ditolak)"

    info "Queue smoke job"
    cmd_queue_check

    info "Heartbeat worker dan scheduler"
    # Heartbeats are keyed by host name, so each check runs in its own container.
    compose exec -T worker php artisan ops:heartbeat worker --check --max-age=120
    compose exec -T scheduler php artisan ops:heartbeat scheduler --check --max-age=150 \
        || echo "Heartbeat scheduler belum ada (scheduler menulis heartbeat tiap pergantian menit)."
}

cmd_reset_db() {
    local database app_env db_host answer
    database="$(env_value DB_DATABASE kpi_berlianartha)"
    app_env="$(env_value APP_ENV)"
    db_host="$(compose exec -T app printenv DB_HOST | tr -d '\r')"

    [ "$app_env" = "local" ] || fail "reset-db hanya diizinkan untuk APP_ENV=local (saat ini: ${app_env:-kosong})."
    [ "$db_host" = "postgres" ] || fail "reset-db hanya untuk PostgreSQL Docker Compose (DB_HOST=postgres)."

    if [ "${KPI_CONFIRM_RESET:-}" != "$database" ]; then
        printf 'Seluruh tabel database lokal "%s" akan dihapus. Ketik nama database untuk melanjutkan: ' "$database"
        read -r answer
        [ "$answer" = "$database" ] || fail "Konfirmasi tidak cocok; reset dibatalkan."
    fi

    exec_app php artisan migrate:fresh --no-interaction
}

main() {
    local command="${1:-help}"
    shift || true

    case "$command" in
        bootstrap) cmd_bootstrap ;;
        up) cmd_up ;;
        down) cmd_down ;;
        ps) compose ps ;;
        logs) compose logs -f "$@" ;;
        migrate) cmd_migrate ;;
        seed) cmd_seed ;;
        test) cmd_test "$@" ;;
        test-pgsql) cmd_test_pgsql "$@" ;;
        lint) cmd_lint ;;
        analyse) cmd_analyse ;;
        build) cmd_build ;;
        queue-check) cmd_queue_check ;;
        readiness) cmd_readiness ;;
        smoke) cmd_smoke ;;
        reset-db) cmd_reset_db ;;
        help | -h | --help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//' ;;
        *) fail "Perintah tidak dikenal: $command (lihat: bash scripts/dev.sh help)" ;;
    esac
}

main "$@"

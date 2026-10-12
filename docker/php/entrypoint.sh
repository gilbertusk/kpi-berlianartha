#!/bin/sh
# Entry point shared by the web (php-fpm), worker, and scheduler roles.
set -eu

cd /var/www/html

# Development only: install Composer dependencies on a fresh bind-mounted
# checkout. Enabled for the single "app" service to avoid concurrent installs.
if [ "${KPI_AUTO_INSTALL:-false}" = "true" ] && [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ tidak ditemukan; menjalankan composer install"
    composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/autoload.php tidak ada. Jalankan: bash scripts/dev.sh bootstrap" >&2
    exit 1
fi

# Production: cache config/routes/views/events at start-up so every role uses
# the injected environment. Migrations are never run automatically.
if [ "${APP_OPTIMIZE:-false}" = "true" ]; then
    php artisan optimize --no-interaction
fi

exec docker-php-entrypoint "$@"

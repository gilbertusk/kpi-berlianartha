# Multi-stage build for the KPI Berlian Artha Label Laravel application.
#
# Targets:
#   development  PHP-FPM + Composer for Docker Compose (source is bind-mounted)
#   production   Immutable application image. The same image runs every role:
#                  web        php-fpm (default command)
#                  worker     php artisan queue:work ...
#                  scheduler  php artisan schedule:work
#   http         nginx with the public/ assets of the same build (front of web)
#
# No .env, credentials, tests, or development dependencies enter production.
# Base images are pinned with literal FROM lines so Dependabot can update them.

FROM composer:2.10.3 AS composer
FROM mlocati/php-extension-installer:2.12.3 AS php-extension-installer

# -----------------------------------------------------------------------------
# base: PHP runtime and extensions shared by every PHP target
# -----------------------------------------------------------------------------
FROM php:8.5.11-fpm-trixie AS base

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Debian security updates are applied at build time so fixable OS CVEs do not
# wait for the next upstream php image release (see DEPENDENCY_POLICY.md).
RUN install-php-extensions bcmath intl pcntl pdo_pgsql pgsql redis zip \
    && apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends libfcgi-bin \
    && rm -rf /var/lib/apt/lists/* /usr/local/bin/install-php-extensions

COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/kpi-entrypoint
COPY --chmod=0755 docker/php/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck

WORKDIR /var/www/html

ENTRYPOINT ["kpi-entrypoint"]
CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# development: Composer available, source bind-mounted by Docker Compose
# -----------------------------------------------------------------------------
FROM base AS development

ARG UID=1000
ARG GID=1000

# Development only: pest-plugin-browser (Step 19 E2E) needs ext-sockets to
# install. The production image is built from base and does not get it.
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions sockets \
    && rm /usr/local/bin/install-php-extensions

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && groupmod --non-unique --gid "${GID}" www-data \
    && usermod --non-unique --uid "${UID}" --gid "${GID}" www-data \
    && mkdir -p /tmp/composer /var/www/html/vendor /var/cache/laravel-views \
    && chown www-data:www-data /tmp/composer /var/www/html/vendor /var/cache/laravel-views

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/conf.d/opcache-development.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

ENV COMPOSER_HOME=/tmp/composer \
    XDG_CONFIG_HOME=/tmp/config

USER www-data

# -----------------------------------------------------------------------------
# vendor: production Composer dependencies and optimized autoloader
# -----------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi

# -----------------------------------------------------------------------------
# assets: Vite production build
# -----------------------------------------------------------------------------
FROM node:26.11.1-trixie-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
# Tailwind scans these vendor views and imports Flux CSS.
COPY --from=vendor /app/vendor/livewire/flux ./vendor/livewire/flux
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views

RUN npm run build

# -----------------------------------------------------------------------------
# production: immutable application image (web, worker, scheduler)
# -----------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    APP_OPTIMIZE=true

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/opcache-production.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

# The php base image keeps its compiler toolchain (PHPIZE_DEPS: gcc, g++,
# make, kernel headers, ...) for building extensions; production needs none
# of it (Step 18 container triage). Keep the libraries PHP and its extensions
# link against (pattern of the official php image), the FastCGI healthcheck,
# and the CA bundle; remove every other non-essential package.
RUN apt-mark auto '.*' > /dev/null \
    && apt-mark manual libfcgi-bin ca-certificates > /dev/null \
    && find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print "*/" so }' \
        | sort -u | xargs -r dpkg-query --search | grep -v '^diversion' | cut -d: -f1 | sort -u | xargs -r apt-mark manual > /dev/null \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/* \
    && php -m > /dev/null \
    && ! php -m 2>&1 | grep -qi 'warning'

# Code is owned by root (read-only for the runtime user); only storage/ and
# bootstrap/cache/ are writable by www-data.
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER www-data

# -----------------------------------------------------------------------------
# http: nginx serving public/ of the same build and proxying PHP to php-fpm
# -----------------------------------------------------------------------------
FROM nginx:1.31.0-alpine AS http

ENV PHP_FPM_UPSTREAM=app:9000

RUN apk upgrade --no-cache

COPY docker/nginx/templates/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=vendor /app/public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

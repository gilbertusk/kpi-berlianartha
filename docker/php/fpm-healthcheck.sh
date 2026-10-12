#!/bin/sh
# Container healthcheck for the web (php-fpm) role: asks the FastCGI ping endpoint.
set -eu

response=$(SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null || true)

case "$response" in
    *pong*) exit 0 ;;
    *) echo "php-fpm ping failed" >&2; exit 1 ;;
esac

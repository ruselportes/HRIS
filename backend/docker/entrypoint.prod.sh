#!/bin/sh
# Entrypoint for the production API containers (api runs php-fpm as root, which
# drops its workers to www-data; scheduler runs as www-data via compose's user:).
set -e

if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
    chown -R www-data:www-data storage bootstrap/cache
    # artisan must not run as root or it leaves root-owned logs/caches that
    # php-fpm's www-data workers cannot write.
    as_app="runuser -u www-data --"
else
    as_app=""
fi

# Config is cached at start, not build, because the values come from the
# container environment. No route:cache: routes/web.php has a closure route,
# which cannot be serialized.
$as_app php artisan config:cache
$as_app php artisan view:cache

if [ "${HRIS_MIGRATE_ON_START:-false}" = "true" ]; then
    $as_app php artisan migrate --force
fi

exec "$@"

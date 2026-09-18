#!/bin/sh
# Entrypoint for the HRIS API containers (api, scheduler).
set -e

# vendor/ is a named volume, so it starts empty and must follow composer.lock:
# reinstall whenever the lock file differs from the one last installed.
if [ "${HRIS_SKIP_INSTALL:-false}" != "true" ]; then
    lock_hash=$(sha1sum composer.lock | cut -d' ' -f1)
    if [ ! -f vendor/autoload.php ] || [ "$(cat vendor/.composer-lock-hash 2>/dev/null)" != "$lock_hash" ]; then
        composer install --no-interaction --prefer-dist
        echo "$lock_hash" > vendor/.composer-lock-hash
    fi
fi

# A fresh clone has no .env yet.
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

if [ "${HRIS_MIGRATE_ON_START:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"

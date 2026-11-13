#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

# Only the app container migrates and seeds; the seeder skips a seeded database.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" >/dev/null 2>&1; do
        echo "Waiting for PostgreSQL at $DB_HOST:$DB_PORT..."
        sleep 1
    done
    php artisan migrate --force --no-interaction
    php artisan db:seed --force --no-interaction
fi

# `docker compose exec` runs as root; keep storage writable for the php-fpm workers (www-data).
chown -R www-data:www-data storage bootstrap/cache

exec "$@"

#!/bin/sh
# Entrypoint for pf-web only. pf-queue and pf-scheduler override it
# with `entrypoint: [""]`, so only one container ever migrates.
set -e

echo "[entrypoint] waiting for database..."
tries=0
until php -r "
try {
    new PDO(
        'pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: '5432').';dbname='.getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
    );
    exit(0);
} catch (Throwable \$e) { exit(1); }
" 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "[entrypoint] database unreachable after 30 attempts, giving up"
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] database ready"

echo "[entrypoint] running migrations..."
php artisan migrate --force

# Built after the environment is present, so they belong here rather
# than in the Dockerfile.
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] ready"
exec "$@"

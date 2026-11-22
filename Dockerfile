# syntax=docker/dockerfile:1

# One image, three roles. The compose file picks the role by command:
#   pf-web        nginx + php-fpm (default CMD), migrates on start
#   pf-queue      php artisan queue:work   (also runs ffmpeg jobs)
#   pf-scheduler  php artisan schedule:work

# ---------------------------------------------------------------
# Stage 1: PHP base
#
# Shared by every later stage, so Composer resolves platform
# requirements against exactly the extensions production runs.
#
# Build-only headers go into a virtual package and are dropped. The
# matching runtime libraries (icu-libs, libzip, libpq) must stay:
# deleting them breaks the extensions at boot, long after the build
# passed.
#
# The patch version is pinned and bumped deliberately. CI refuses an
# image below 8.4.27 (PHP2Shell, CVE-2026-109618/109619).
#
# pcntl lets queue:work enforce job timeouts and stop cleanly on
# SIGTERM. ffmpeg is here from the first deploy so the image job
# never needs a different image.
# ---------------------------------------------------------------
FROM php:8.4.28-fpm-alpine AS base

RUN apk add --no-cache \
        nginx supervisor curl ffmpeg \
        icu-libs libzip libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql bcmath intl zip opcache pcntl \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# ---------------------------------------------------------------
# Stage 2: PHP dependencies (no dev)
#
# --no-scripts because artisan needs the full app tree, which isn't
# here yet. Scripts run in the runtime stage.
# ---------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------
# Stage 3: frontend assets
#
# Built on the PHP base, not a plain Node image: the Wayfinder Vite
# plugin runs `php artisan wayfinder:generate` during the build to
# write the typed route helpers the Vue pages import.
# ---------------------------------------------------------------
FROM base AS assets

RUN apk add --no-cache nodejs npm

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# ---------------------------------------------------------------
# Stage 4: runtime
# ---------------------------------------------------------------
FROM base AS runtime

WORKDIR /var/www/html

COPY docker/php.ini          /usr/local/etc/php/conf.d/99-app.ini
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Run the composer scripts skipped earlier, now that artisan exists.
# Composer is borrowed for this step only; production doesn't carry it.
RUN --mount=from=composer:2,source=/usr/bin/composer,target=/usr/local/bin/composer \
    composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# nginx workers run as www-data, but the Alpine package leaves
# /var/lib/nginx owned by nginx:nginx. Responses too big for the
# in-memory buffers spool to disk there and fail with "Permission
# denied" unless the worker can reach it.
RUN chown -R www-data:www-data /var/lib/nginx

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

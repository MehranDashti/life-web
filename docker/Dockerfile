# syntax=docker/dockerfile:1
FROM php:8.3-cli AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip libzip-dev libpng-dev libonig-dev libssl-dev \
        libcurl4-openssl-dev supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring bcmath zip gd pcntl sockets exif

# Swoole powers Octane; redis is used for the queue, cache, and rate limiting.
RUN pecl install swoole redis \
    && docker-php-ext-enable swoole redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ---------------------------------------------------------------- dependencies
# Copied first so the vendor layer is cached across source-only changes.
COPY composer.json composer.lock ./
RUN --mount=type=ssh composer install \
        --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . /app

RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/reports bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]

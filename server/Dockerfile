# syntax=docker/dockerfile:1

# Сервер Contact Sync: FrankenPHP (Caddy + PHP) отдаёт Symfony напрямую, без отдельного nginx.
FROM dunglas/frankenphp:1-php8.5 AS base

WORKDIR /app

RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        pdo_sqlite \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    # HTTP на 80 порту: TLS снаружи (обратный прокси) или не нужен для локального запуска.
    SERVER_NAME=:80

# --- Для тестов: с dev-зависимостями ---
FROM base AS dev

ENV APP_ENV=test
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist
COPY . .

CMD ["php", "bin/phpunit"]

# --- Для запуска ---
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && php bin/console assets:install public \
    && mkdir -p var/storage \
    && chown -R www-data:www-data var

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

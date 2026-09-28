#!/bin/sh
set -e

# Ждём базу, применяем миграции и прогреваем кэш — после этого сервер готов принимать запросы.
if [ "$1" = "frankenphp" ]; then
    echo "Ожидание базы данных…"
    attempts=0
    until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "База данных недоступна" >&2
            php bin/console dbal:run-sql "SELECT 1"
            exit 1
        fi
        sleep 1
    done

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console cache:warmup
    mkdir -p var/storage/apk
    chown -R www-data:www-data var
fi

exec docker-php-entrypoint "$@"

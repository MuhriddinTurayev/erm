#!/bin/sh
# Oftob_Sohtumon — запуск контейнера: ждём MySQL, применяем миграции, кэшируем настройки, потом supervisor
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY не задан. Запустите deploy/install.sh или впишите APP_KEY в файл .env рядом с docker-compose.yml" >&2
    exit 1
fi

# том storage при первом запуске пустой — создаём нужные папки
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache public/uploads

# ждём базу данных (до 2 минут)
if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    i=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then echo "База данных недоступна" >&2; exit 1; fi
        echo "Жду базу данных..."
        sleep 2
    done
fi

php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache public/uploads

exec "$@"

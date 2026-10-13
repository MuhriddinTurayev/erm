#!/bin/sh
# Команды программы внутри контейнера всегда от www-data (как сайт и планировщик):
# иначе файлы и папки, созданные командой от root, сайт потом не сможет менять.
#   docker compose exec app artisan oftob:user email пароль --role=superadmin
cd /var/www/html
if [ "$(id -u)" = "0" ]; then
    exec runuser -u www-data -- php artisan "$@"
fi
exec php artisan "$@"

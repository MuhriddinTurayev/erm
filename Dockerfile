# Oftob_Sohtumon — образ для сервера: nginx + PHP-FPM 8.4 + планировщик и очередь (supervisor) в одном контейнере.
# База данных — отдельный контейнер MySQL (см. docker-compose.yml).

# 1) зависимости PHP (без dev-пакетов)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# 2) рабочий образ
FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor curl unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath exif opcache pcntl \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/nginx.conf /etc/nginx/conf.d/oftob.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-oftob.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-oftob.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/oftob.conf
COPY docker/entrypoint.sh /usr/local/bin/oftob-entrypoint

RUN chmod +x /usr/local/bin/oftob-entrypoint \
    && rm -f .env \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private public/uploads \
    && chown -R www-data:www-data storage bootstrap/cache public/uploads

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 CMD curl -fsS http://127.0.0.1/up >/dev/null || exit 1
ENTRYPOINT ["oftob-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

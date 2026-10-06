FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev icu-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql intl zip pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && printf "memory_limit=512M\nupload_max_filesize=50M\npost_max_size=50M\n" > /usr/local/etc/php/conf.d/99-maintenance.ini \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
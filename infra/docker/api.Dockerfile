# Laravel API (php-fpm). Served through the nginx gateway (infra/docker/nginx.conf).
# Uploads: PHP's defaults (2 MB per file) would refuse ordinary workbooks, so the limits
# match the gateway's 60 MB, with memory and time for large Excel files.
FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache icu-dev libpq-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_pgsql pdo_mysql intl zip gd bcmath opcache pcntl \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/api/ ./
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/app storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && printf "opcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=256\n" > /usr/local/etc/php/conf.d/opcache.ini \
 && printf "upload_max_filesize=60M\npost_max_size=64M\nmemory_limit=1024M\nmax_execution_time=300\nmax_input_time=300\n" > /usr/local/etc/php/conf.d/aixbi-uploads.ini
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

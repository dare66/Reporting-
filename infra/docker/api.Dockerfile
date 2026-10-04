# Laravel API (php-fpm). Served through the nginx gateway (infra/docker/nginx.conf).
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
 && printf "opcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=256\n" > /usr/local/etc/php/conf.d/opcache.ini
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

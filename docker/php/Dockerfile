FROM composer:2 AS composer

FROM php:8.3-fpm-alpine

RUN apk add --no-cache libpq-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql pcntl

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first so code edits do not invalidate this layer.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/zz-maqad.conf /usr/local/etc/php-fpm.d/zz-maqad.conf

ENTRYPOINT ["/var/www/html/docker/php/entrypoint.sh"]
CMD ["php-fpm"]

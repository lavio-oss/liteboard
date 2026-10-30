# php:8.4-fpm also ships php-cli: dev runs `artisan serve`, production overrides the command with php-fpm.
FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libsqlite3-dev unzip \
    && docker-php-ext-install pdo_pgsql pdo_sqlite opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY backend/docker/php.ini /usr/local/etc/php/conf.d/zz-liteboard.ini
COPY backend/docker/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-liteboard.conf

WORKDIR /var/www/html

COPY backend/ ./

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8800

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8800"]

FROM php:8.4.27-cli-bookworm AS php-base

ENV TZ=UTC
WORKDIR /var/www/app

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS libonig5 libonig-dev libzip4 libzip-dev \
    && docker-php-ext-install bcmath mbstring pcntl pdo_mysql zip \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libonig-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS app-build
COPY --from=composer:2.11.0 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php-base AS app
COPY --from=app-build /var/www/app /var/www/app
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM php-base AS test
COPY --from=composer:2.11.0 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data

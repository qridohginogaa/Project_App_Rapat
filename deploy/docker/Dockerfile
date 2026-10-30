# syntax=docker/dockerfile:1
# Build context: root repo (folder yang berisi laravel-app/ dan uploads/).

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY laravel-app/package.json laravel-app/package-lock.json laravel-app/.npmrc ./
RUN npm ci --no-audit --no-fund
COPY laravel-app/ ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY laravel-app/composer.json laravel-app/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY laravel-app/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM php:8.3-fpm-bookworm AS app
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath gd opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY laravel-app/ ./
COPY --from=assets /app/public/build ./public/build
# Laravel membaca berkas rapat dari base_path('../uploads') = /var/www/uploads.
RUN php artisan package:discover --ansi \
    && mkdir -p /var/www/uploads \
    && chown -R www-data:www-data storage bootstrap/cache /var/www/uploads

FROM nginx:1.27-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public

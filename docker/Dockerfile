FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev libonig-dev libxml2-dev libsqlite3-dev unzip git \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /var/www/html

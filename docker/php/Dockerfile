# syntax=docker/dockerfile:1.7
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        curl \
        icu-dev \
        libzip-dev \
        libpng-dev \
        oniguruma-dev \
        zlib-dev \
        linux-headers \
    && docker-php-ext-install \
        pdo_mysql \
        intl \
        opcache \
        zip \
        mbstring \
        exif \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Composer 2.x
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

RUN mkdir -p /var/run/php && chown www-data:www-data /var/run/php

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD php-fpm -t || exit 1

EXPOSE 9000

CMD ["php-fpm"]

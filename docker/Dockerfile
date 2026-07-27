# syntax=docker/dockerfile:1.7

FROM php:8.2-fpm-bookworm

ARG DEBIAN_FRONTEND=noninteractive

ENV COMPOSER_ALLOW_SUPERUSER=1

# 1. Unificar instrucciones RUN para reducir capas (Regla S7031)
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg2 \
    && mkdir -p /usr/share/keyrings \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor --yes \
        -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list \
        -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends \
        msodbcsql18 \
        unixodbc \
        unixodbc-dev \
        g++ \
        make \
        autoconf \
        pkg-config \
        git \
        unzip \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd curl pdo \
    && pecl install sqlsrv-5.11.1 pdo_sqlsrv-5.11.1 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /tmp/pear

# Composer: evita utilizar "latest" para que no cambie constantemente.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Límites de subida de archivos (import de CSV)
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Pool de PHP-FPM: sin request_terminate_timeout
COPY docker/php-fpm-import.conf /usr/local/etc/php-fpm.d/zz-import.conf

WORKDIR /var/www/html

# 2. Sin patrones Glob para evitar archivos no deseados (Regla S6470)
COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader

# 3. Evitar copias recursivas (. .) para no filtrar secretos (Regla S6470)
# Ajusta estas carpetas según la estructura real de tu proyecto
COPY src/ src/
COPY public/ public/
COPY config/ config/
COPY bin/ bin/

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

RUN mkdir -p var/cache var/log public/assets \
    && chown -R www-data:www-data var public/assets

EXPOSE 9000

CMD ["php-fpm"]
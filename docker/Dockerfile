# syntax=docker/dockerfile:1.7

FROM php:8.2-fpm-bookworm

ARG DEBIAN_FRONTEND=noninteractive

ENV COMPOSER_ALLOW_SUPERUSER=1

# 1. Registrar repositorio Microsoft.
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
        -o /etc/apt/sources.list.d/mssql-release.list

# 2. Instalar dependencias y ODBC.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update \
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
        libcurl4-openssl-dev

# 3. Compilar extensiones.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd curl pdo \
    && pecl install sqlsrv-5.11.1 pdo_sqlsrv-5.11.1 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /tmp/pear

# Composer: evita utilizar "latest" para que no cambie constantemente.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Límites de subida de archivos (import de CSV) -- ver comentarios en el
# propio archivo. Se copia antes que el resto del código porque casi nunca
# cambia -- aprovecha la caché de capas de Docker.
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Pool de PHP-FPM: sin request_terminate_timeout, para que la importación
# masiva de CSV no la mate FPM a mitad de camino. El prefijo zz- asegura
# que se cargue después de www.conf y sobrescriba sus valores.
COPY docker/php-fpm-import.conf /usr/local/etc/php-fpm.d/zz-import.conf

WORKDIR /var/www/html

# Instalar dependencias primero para aprovechar la caché.
COPY composer.json composer.lock* ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader

# El código cambia frecuentemente, por eso se copia después.
COPY . .

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
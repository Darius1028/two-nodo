# FIX: se cambia la base de php:8.2-fpm-alpine a php:8.2-fpm-bookworm (Debian).
# Motivo: Microsoft solo publica y soporta oficialmente el driver ODBC de SQL
# Server (msodbcsql18) para Debian/Ubuntu/RHEL vía su repo apt. En Alpine el
# extension sqlsrv/pdo_sqlsrv compila bien con unixodbc-dev, pero en runtime
# no hay ningún driver ODBC real registrado y la conexión falla con
# "Driver not found" / "IM002". Ver: https://learn.microsoft.com/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server
FROM php:8.2-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl gnupg2 ca-certificates apt-transport-https unixodbc unixodbc-dev \
        g++ make autoconf git unzip \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    # FIX: apt en Debian 12 (bookworm) ya no confía en claves .asc puestas
    # directo en /etc/apt/trusted.gpg.d (error "NO_PUBKEY EB3E94ADBE1229CF").
    # Hay que convertir la clave a formato binario con gpg --dearmor y
    # guardarla en /usr/share/keyrings/, que es donde el prod.list de
    # Microsoft ya la referencia vía "signed-by=".
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 mssql-tools18 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo \
    # FIX: "pecl install sqlsrv pdo_sqlsrv" sin versión trae siempre la
    # última release (5.12.0+), que dejó de soportar PHP 8.2 y exige 8.3+
    # ("pecl/sqlsrv requires PHP >= 8.3.0"). Se fija 5.11.1, la última
    # release compatible con PHP 8.2. Si más adelante subís la imagen base
    # a php:8.3-fpm-bookworm, podés volver a "pecl install sqlsrv pdo_sqlsrv"
    # sin versión para tener parches más nuevos.
    && pecl install sqlsrv-5.11.1 pdo_sqlsrv-5.11.1 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV PATH="/opt/mssql-tools18/bin:${PATH}"

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer install primero (aprovecha cache de Docker)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts || true

# Copiar código
COPY . .

# Reinstalar con todo el código
RUN composer install --no-dev --optimize-autoloader

# Permisos
RUN mkdir -p var/cache var/log public/assets \
    && chown -R www-data:www-data var public/assets

EXPOSE 9000
CMD ["php-fpm"]

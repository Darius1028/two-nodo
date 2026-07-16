# FIX: se cambia la base de php:8.2-fpm-alpine a php:8.2-fpm-bookworm (Debian).
# Motivo: Microsoft solo publica y soporta oficialmente el driver ODBC de SQL
# Server (msodbcsql18) para Debian/Ubuntu/RHEL vía su repo apt. En Alpine el
# extension sqlsrv/pdo_sqlsrv compila bien con unixodbc-dev, pero en runtime
# no hay ningún driver ODBC real registrado y la conexión falla con
# "Driver not found" / "IM002". Ver: https://learn.microsoft.com/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server
FROM php:8.2-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl gnupg2 apt-transport-https unixodbc unixodbc-dev \
        g++ make autoconf git unzip \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
           | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 mssql-tools18 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo \
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

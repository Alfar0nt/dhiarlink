FROM php:8.5-alpine3.22
MAINTAINER Dhiarlink <admin@dhiarr.qzz.io>

ENV PDO_SQLSRV_VERSION='5.13.0'
ENV MS_ODBC_DOWNLOAD='fae28b9a-d880-42fd-9b98-d779f0fdd77f'
ENV MS_ODBC_SQL_VERSION='18_18.5.1.1'

# Install all PHP extensions and their dependencies in a single layer for faster builds
RUN apk add --no-cache \
        oniguruma-dev \
        sqlite-libs \
        sqlite-dev \
        icu-dev \
        postgresql-dev \
        libzip-dev \
        zlib-dev && \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        calendar \
        mbstring \
        intl \
        sockets \
        bcmath

# Install Xdebug and zip via PIE (PHP Installer for Extensions)
COPY --from=ghcr.io/php/pie:bin /pie /usr/bin/pie
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS linux-headers && \
    pie install xdebug/xdebug && \
    pie install pecl/zip && \
    apk del .phpize-deps

# Install sqlsrv driver
RUN apk add --update linux-headers && \
    wget https://download.microsoft.com/download/${MS_ODBC_DOWNLOAD}/msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
    apk add --allow-untrusted msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
    apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS unixodbc-dev && \
    pecl install pdo_sqlsrv-${PDO_SQLSRV_VERSION} && \
    docker-php-ext-enable pdo_sqlsrv && \
    apk del .phpize-deps && \
    rm msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Make home directory writable by anyone
RUN chmod 777 /home

VOLUME /home/dhiarlink
WORKDIR /home/dhiarlink

# Expose roadrunner port
EXPOSE 8080

CMD \
    # Install dependencies if the vendor dir does not exist
    if [[ ! -d "./vendor" ]]; then /usr/local/bin/composer install ; fi && \
    # Download roadrunner binary
    if [[ ! -f "./bin/rr" ]]; then ./vendor/bin/rr get --no-interaction --no-config --location bin/ && chmod +x bin/rr ; fi && \
    # Create env file if it does not exist yet
    if [[ ! -f "./config/params/dhiarlink_dev_env.php" ]]; then cp ./config/params/dhiarlink_dev_env.php.dist ./config/params/dhiarlink_dev_env.php ; fi && \
    # Run with `exec` so that signals are properly handled
    exec ./bin/rr serve -c config/roadrunner/.rr.dev.yml

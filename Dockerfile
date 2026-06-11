FROM php:8.5-alpine3.22 AS base

ARG DHIARLINK_VERSION=latest
ENV DHIARLINK_VERSION=${DHIARLINK_VERSION}
ARG DHIARLINK_RUNTIME=rr
ENV DHIARLINK_RUNTIME=${DHIARLINK_RUNTIME}

ENV USER_ID='1001'
ENV PDO_SQLSRV_VERSION='5.13.0'
ENV MS_ODBC_DOWNLOAD='fae28b9a-d880-42fd-9b98-d779f0fdd77f'
ENV MS_ODBC_SQL_VERSION='18_18.5.1.1'
ENV LC_ALL='C'

WORKDIR /etc/dhiarlink

# Install required PHP extensions (batched for fewer layers and faster builds)
RUN \
    # Temp install dev dependencies needed to compile the extensions \
    apk add --no-cache --virtual .dev-deps sqlite-dev postgresql-dev icu-dev libzip-dev zlib-dev linux-headers && \
    # All extensions in a single docker-php-ext-install call for parallel compilation \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql pdo_sqlite intl calendar sockets bcmath zip && \
    # Remove temp dev extensions, and install prod equivalents that are required at runtime \
    apk del .dev-deps && \
    apk add --no-cache postgresql icu libzip libpng sqlite-libs

# Install APCu for fast in-process metadata caching (Doctrine metadata, class maps)
RUN apk add --no-cache --virtual .apcu-deps ${PHPIZE_DEPS} && \
    pecl install apcu && \
    docker-php-ext-enable apcu && \
    apk del .apcu-deps

# Install sqlsrv driver for x86_64 builds
RUN if [ $(uname -m) == "x86_64" ]; then \
      apk add --no-cache --virtual .phpize-deps ${PHPIZE_DEPS} unixodbc-dev && \
      wget https://download.microsoft.com/download/${MS_ODBC_DOWNLOAD}/msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
      apk add --allow-untrusted msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
      pecl install pdo_sqlsrv-${PDO_SQLSRV_VERSION} && \
      docker-php-ext-enable pdo_sqlsrv && \
      rm msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
      apk del .phpize-deps; \
    fi

# Install dhiarlink
FROM base AS builder
COPY . .
COPY --from=composer:2 /usr/bin/composer ./composer.phar
RUN apk add --no-cache git && \
    php composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-progress --no-interaction && \
    php composer.phar clear-cache && \
    rm -r docker composer.* && \
    sed -i "s/%DHIARLINK_VERSION%/${DHIARLINK_VERSION}/g" module/Core/src/Config/Options/AppOptions.php


# Prepare final image
FROM base
LABEL maintainer="Dhiarlink <admin@dhiarr.qzz.io>"

COPY --from=builder --chown=${USER_ID} /etc/dhiarlink .
RUN ln -s /etc/dhiarlink/bin/cli /usr/local/bin/dhiarlink && \
    if [ "$DHIARLINK_RUNTIME" == 'rr' ]; then \
      php ./vendor/bin/rr get --no-interaction --no-config --location bin/ && chmod +x bin/rr ; \
    fi;

# Expose default port
EXPOSE 8080

# Copy config specific for the image
COPY docker/docker-entrypoint.sh docker-entrypoint.sh
COPY docker/config/php.ini ${PHP_INI_DIR}/conf.d/

USER ${USER_ID}

ENTRYPOINT ["/bin/sh", "./docker-entrypoint.sh"]

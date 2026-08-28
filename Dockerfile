# metaglot — one-shot CLI image. Invoked by cron, exits when the run is done.
FROM php:8.3-cli-alpine

# curl and mbstring ship compiled into the official image; pdo_pgsql needs
# the libpq headers to build and libpq at runtime.
RUN apk add --no-cache libpq \
 && apk add --no-cache --virtual .build-deps postgresql-dev \
 && docker-php-ext-install pdo_pgsql \
 && apk del .build-deps \
 && php -m | grep -qi '^curl$' \
 && php -m | grep -qi '^mbstring$' \
 && php -m | grep -qi '^pdo_pgsql$'

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Dependencies first so this layer is cached until composer.lock changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader

# Application source; the autoloader is generated once the PSR-4 paths exist.
COPY bin/ bin/
COPY src/ src/
COPY migrations/ migrations/
RUN composer dump-autoload --optimize --no-dev --no-interaction

# Nothing here needs root.
RUN adduser -D -u 1000 metaglot
USER metaglot

ENTRYPOINT ["php", "/app/bin/metaglot"]

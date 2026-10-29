# The JumPHP playground: Symfony on FrankenPHP (PHP 8.4). Stages: base (dev: the code is mounted), prod.
FROM dunglas/frankenphp:1-php8.5 AS base
RUN install-php-extensions pdo_mysql pdo_sqlite intl zip gd opcache apcu \
 && apt-get update && apt-get install -y --no-install-recommends git unzip curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-playground.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/playground-entrypoint
ENV COMPOSER_ALLOW_SUPERUSER=1 OPCACHE_VALIDATE=1
WORKDIR /app
ENTRYPOINT ["playground-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0 OPCACHE_VALIDATE=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && composer run-script --no-dev post-install-cmd \
 && php bin/console importmap:install \
 && php bin/console asset-map:compile \
 && php bin/console cache:warmup \
 && mkdir -p var/storage && chown -R www-data:www-data var

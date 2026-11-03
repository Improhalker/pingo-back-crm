# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.5.12-apache-bookworm@sha256:6b401f1bfa88fc9d2ae5c3ff2558f18d0c17c9801718ded725095ea29131f34b
ARG COMPOSER_IMAGE=composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS runtime-base
WORKDIR /var/www/html
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev libonig-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath mbstring pcntl pdo_mysql zip; \
    apt-mark manual libzip4 libonig5; \
    apt-get purge -y --auto-remove libzip-dev libonig-dev; \
    rm -rf /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    a2enmod rewrite headers; \
    a2dissite 000-default; \
    printf 'Listen 8080\n' > /etc/apache2/ports.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pingofin.ini
COPY docker/apache.conf /etc/apache2/sites-enabled/pingofin.conf

FROM runtime-base AS build
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader
COPY artisan ./artisan
COPY app ./app
COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY config ./config
COPY database/migrations ./database/migrations
COPY public ./public
COPY routes ./routes
RUN set -eux; \
    mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    composer dump-autoload --no-dev --classmap-authoritative --no-scripts --no-plugins; \
    composer check-platform-reqs --no-dev; \
    APP_ENV=production APP_DEBUG=false php artisan package:discover --no-interaction

FROM runtime-base AS production
COPY --from=build /var/www/html /var/www/html
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/pingofin-entrypoint
COPY docker/preflight.php /usr/local/lib/pingofin-preflight.php
RUN chown root:root /var/www/html && chmod 755 /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache
ENV APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_LOCK_DIR=/tmp/apache2-lock \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid
USER www-data
EXPOSE 8080
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error --max-time 4 http://127.0.0.1:8080/api/v1/health/live || exit 1
ENTRYPOINT ["/usr/local/bin/pingofin-entrypoint"]
CMD ["web"]

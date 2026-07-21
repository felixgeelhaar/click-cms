# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — Composer dependencies
#
# Split out so that changing application code does not reinstall dependencies:
# only composer.json/lock invalidate this layer.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./

# No scripts and no plugins while installing: nothing in the dependency tree
# should be able to execute during an image build.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-plugins \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2 — Runtime
# ---------------------------------------------------------------------------
FROM php:8.3-apache AS runtime

# libzip/libsqlite are needed to build the extensions; the -dev packages are
# dropped again afterwards so they do not ship in the final layer.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite zip; \
    apt-get purge -y --auto-remove libsqlite3-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Production PHP defaults: never render errors to the browser, always log them.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/proc/self/fd/2'; \
        echo 'expose_php=Off'; \
    } > "$PHP_INI_DIR/conf.d/zz-click-cms.ini"

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/health.php ./public/health.php

# content/ and data/ are volume mount points. They are created and owned here so
# the container still starts when no volume is mounted, and www-data can write
# to them either way.
RUN set -eux; \
    mkdir -p content data; \
    chown -R www-data:www-data /var/www/html; \
    find /var/www/html -type d -exec chmod 755 {} +; \
    find /var/www/html -type f -exec chmod 644 {} +

# Apache's master process needs root to bind :80 and then drops to www-data for
# workers, which is why this is not USER www-data.
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health.php") !== false ? 0 : 1);'

CMD ["apache2-foreground"]

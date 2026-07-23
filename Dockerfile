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
# Stage 2 — Admin UI
#
# Built here so the image is self-contained. Without this the admin route falls
# back to proxying an Astro dev server on localhost:4321, which exists only on a
# developer's machine — a container built without this stage has no admin UI.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS admin-ui

WORKDIR /app
COPY admin-ui/package.json admin-ui/package-lock.json* ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund

COPY admin-ui/ ./

# Tests before the build, so a component that misreads the API cannot reach an
# image. Four such defects shipped before this ran: the page list and the
# dashboard both reported a fully live site as having nothing published, and a
# changed content key broke every address the list rendered and made Delete
# remove the wrong language's document. Every one was found by reading code.
RUN npm test
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — Runtime
# ---------------------------------------------------------------------------
FROM php:8.3-apache AS runtime

# gd is required to generate responsive image variants; without it the media
# library can store an upload but not resize it.
#
# The -dev packages are build-time only, but the shared libraries they pull in
# (libpng16, libzip, libjpeg, libwebp) are needed at run time. Purging the
# headers with --auto-remove takes those runtime libraries with them and the
# extensions then silently fail to load, so the runtime libraries are installed
# explicitly first and only the headers are purged, by name.
RUN set -eux; \
    apt-get update; \
    # Runtime libraries, installed explicitly so they are never candidates for
    # auto-removal when the build-time headers go.
    apt-get install -y --no-install-recommends \
        libpng16-16 \
        libjpeg62-turbo \
        libwebp7 \
        libfreetype6 \
        libzip5; \
    # Build-time headers only.
    apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite pdo_mysql zip gd; \
    # Purge the headers by name. Nothing is auto-removed, so the runtime
    # libraries above survive.
    apt-get purge -y \
        libsqlite3-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libwebp-dev; \
    rm -rf /var/lib/apt/lists/*; \
    # Fail the build rather than ship an image whose extensions cannot load.
    php -r 'foreach (["gd","zip","pdo_sqlite","pdo_mysql"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'

# Uploads are limited here as well as in application code: a request larger than
# this is rejected before PHP ever buffers it.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=13M'; \
        echo 'max_file_uploads=10'; \
        echo 'memory_limit=256M'; \
    } > "$PHP_INI_DIR/conf.d/zz-uploads.ini"

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

# Serving the built admin UI as static files under the document root means
# Apache answers /admin directly — the rewrite to index.php only fires for paths
# that do not exist on disk, so PHP never sees these requests.
COPY --from=admin-ui /app/dist ./public/admin

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

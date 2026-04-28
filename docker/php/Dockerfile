# =============================================================================
# test_api — PHP-FPM image (development)
# =============================================================================
#
# We deliberately do NOT bake the application source into the image. The dev
# compose file bind-mounts the workspace at /var/www/html, with `vendor/` on a
# named volume so the host's macOS-built vendor never shadows the linux one.
#
# A separate prod stage will be added in a future phase (multi-stage build,
# composer --no-dev, opcache preloading). For now this image targets local dev.
# =============================================================================

FROM php:8.4-fpm-alpine

# ----- system packages + PHP extensions --------------------------------------
# bash    : nicer entrypoint scripts
# git     : composer occasionally needs it for VCS-sourced packages
# icu-dev : intl ext (locale-aware string ops; cheap to include now so we
#           don't have to rebuild the image when we add it later)
# libzip-dev : zip ext
# oniguruma-dev : mbstring ext (already in core PHP, but the dev headers help)
RUN set -eux; \
    apk add --no-cache bash git icu-dev libzip-dev oniguruma-dev; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pdo_mysql \
        zip; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/*

# ----- composer 2 ------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ----- PHP / OPcache config (dev-friendly) -----------------------------------
# Keep validate_timestamps ON so file edits are picked up without a restart.
# Phase 6 may ship a separate prod ini that turns these off + adds preloading.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=16M'; \
        echo 'post_max_size=16M'; \
    } > /usr/local/etc/php/conf.d/zz-test_api.ini

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

#!/usr/bin/env bash
# =============================================================================
# test_api PHP-FPM entrypoint.
#
# Responsibilities, in order:
#   1) Make sure storage/ + bootstrap/cache/ are writable for php-fpm. The
#      bind-mount preserves host UIDs (mac/linux), which are not www-data,
#      so we relax permissions in dev rather than chown'ing host files.
#   2) Run `composer install` if vendor/ is empty (first boot of the named
#      volume). We do NOT auto-update on every restart — that's slow and
#      surprising.
#   3) exec the original CMD (php-fpm).
# =============================================================================

set -euo pipefail

cd /var/www/html

# (1) Make Laravel's writable dirs writable in dev. 0777 is acceptable here
#     because the bind mount is the developer's working tree; production will
#     bake permissions into the image and not bind-mount source.
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache
chmod -R 0777 storage bootstrap/cache 2>/dev/null || true

# (2) First-run composer install. We check for autoload.php specifically so
#     a partial extraction of vendor/ won't be mistaken for "installed".
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ is empty — running composer install (first boot)"
    composer install --no-interaction --prefer-dist --no-progress
fi

# (3) Hand off to php-fpm (or whatever CMD was overridden to).
exec "$@"

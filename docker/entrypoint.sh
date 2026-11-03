#!/bin/sh
set -eu
umask 027

case "${1:-}" in
    web|worker|scheduler)
        php /usr/local/lib/pingofin-preflight.php
        mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
        # Runtime secrets are never cached during image build.
        php artisan config:cache --no-interaction
        php artisan route:cache --no-interaction
        ;;
esac

case "${1:-}" in
    web)
        exec apache2-foreground
        ;;
    worker)
        # retry_after=90 must remain greater than this 60-second timeout.
        exec php artisan queue:work redis --queue=default,bootstrap-smoke --sleep=3 --tries=3 --timeout=60 --max-time=3600 --no-interaction
        ;;
    scheduler)
        exec php artisan schedule:work --no-interaction
        ;;
    *)
        # Explicit maintenance commands only. No automatic migration or seeding.
        exec "$@"
        ;;
esac

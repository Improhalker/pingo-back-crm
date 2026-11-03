#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
image="${1:?Usage: bash scripts/smoke-production.sh IMAGE}"
tmp="$(mktemp -d)"
export PINGOFIN_IMAGE="$image"
export PINGOFIN_SMOKE_ENV="$tmp/runtime.env"
if command -v cygpath >/dev/null 2>&1; then
    export PINGOFIN_SMOKE_ENV="$(cygpath -m "$PINGOFIN_SMOKE_ENV")"
fi
umask 077
key="$(openssl rand -base64 32 | tr -d '\n')"
db_password="$(openssl rand -hex 24)"
root_password="$(openssl rand -hex 24)"
redis_password="$(openssl rand -hex 24)"
sed \
    -e "s|^APP_KEY=.*|APP_KEY=base64:$key|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|" \
    -e "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=$root_password|" \
    -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$redis_password|" \
    -e 's|^DB_DATABASE=.*|DB_DATABASE=pingofin_smoke|' \
    -e 's|pingofin.com.br|pingofin.test|g' \
    -e 's|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=192.0.2.1|' \
    deploy/production.env.example > "$PINGOFIN_SMOKE_ENV"
compose=(docker compose --project-name pingofin-deploy-smoke --env-file "$PINGOFIN_SMOKE_ENV" -f deploy/compose.smoke.yaml)
cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        "${compose[@]}" logs --tail 80 api worker || true
    fi
    # Only this script's disposable project. No -v, prune or production operations.
    "${compose[@]}" down --timeout 90 >/dev/null 2>&1 || true
    rm -f "$tmp/runtime.env" "$tmp/headers" "$tmp/body"
    rmdir "$tmp"
    exit "$status"
}
trap cleanup EXIT

docker run --rm --entrypoint sh "$image" -ec '
    test "$(id -u)" != 0
    test ! -w . || { echo "Application root must not be writable by the runtime user" >&2; exit 1; }
    test ! -w artisan
    test ! -f .env
    test ! -f bootstrap/cache/config.php
    test ! -d vendor/phpunit
    test ! -d tests
    php -r '\''foreach (["bcmath", "pdo_mysql", "pcntl", "zip", "mbstring", "curl", "xml"] as $ext) { if (!extension_loaded($ext)) { exit(1); } }'\''
'
if docker run --rm -e APP_ENV=production -e APP_DEBUG=false "$image" web > /dev/null 2>&1; then
    echo 'Missing APP_KEY was not rejected' >&2
    exit 1
fi
for invalid in APP_DEBUG=true SESSION_SECURE_COOKIE=false TRUSTED_PROXIES=\* REDIS_QUEUE_RETRY_AFTER=60; do
    if docker run --rm --env-file "$PINGOFIN_SMOKE_ENV" -e "$invalid" "$image" web > /dev/null 2>&1; then
        echo "Invalid production configuration was not rejected: ${invalid%%=*}" >&2
        exit 1
    fi
done

"${compose[@]}" config --quiet
"${compose[@]}" up -d --wait --wait-timeout 180 mysql redis
"${compose[@]}" run --rm --no-deps api php artisan migrate --force --no-interaction
"${compose[@]}" up -d --wait --wait-timeout 120 api
# Some Compose releases reject --wait for a worker whose HTTP healthcheck is disabled.
# Confirm worker readiness through actual queue consumption below.
"${compose[@]}" up -d --no-deps worker
port="$("${compose[@]}" port api 8080)"
base="http://$port"
request() {
    curl --silent --show-error --connect-timeout 5 --max-time 20 \
        -H 'Host: api.pingofin.test' -H 'Accept: application/json' \
        -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "$@"
}
test "$(request "$base/")" = 200
grep -qi "content-type: text/html" "$tmp/headers"
grep -q 'id="welcome-title"' "$tmp/body"
if grep -qi "set-cookie:" "$tmp/headers"; then
    echo "Welcome page must not create a session" >&2
    exit 1
fi
for asset in api-welcome.css pingo.png manrope-latin.woff2; do
    test "$(request "$base/brand/$asset")" = 200
done
test "$(request "$base/api/v1/health/live")" = 200
test "$(request "$base/api/v1/health")" = 200
test "$(request "$base/api/v1/auth/me")" = 401
test "$(request -H 'Origin: https://app.pingofin.test' "$base/sanctum/csrf-cookie")" = 204
grep -qi 'access-control-allow-origin: https://app.pingofin.test' "$tmp/headers"
grep -qi 'access-control-allow-credentials: true' "$tmp/headers"
grep -qi 'set-cookie: pingofin_session=.*secure.*httponly' "$tmp/headers"
test "$(request -H 'Origin: https://attacker.invalid' "$base/sanctum/csrf-cookie")" = 204
if grep -qiE 'access-control-allow-origin: (https://attacker\.invalid|\*)' "$tmp/headers"; then
    echo 'Unexpected CORS authorization for another origin' >&2
    exit 1
fi
for protected in .env composer.json storage/app/private/private.txt docker/preflight.php; do
    code="$(request "$base/$protected")"
    test "$code" = 403 || test "$code" = 404
done
"${compose[@]}" exec -T worker php artisan infra:queue-smoke --production --no-interaction
"${compose[@]}" stop redis
test "$(request "$base/api/v1/health/live")" = 200
test "$(request "$base/api/v1/health")" = 503
"${compose[@]}" up -d --wait --wait-timeout 60 redis
test "$(request "$base/api/v1/health")" = 200
"${compose[@]}" exec -T api php artisan infra:queue-smoke --production --no-interaction
"${compose[@]}" restart api
# Docker may assign another ephemeral host port when the container restarts.
port="$("${compose[@]}" port api 8080)"
base="http://$port"
for attempt in {1..30}; do
    if code="$(request "$base/api/v1/health" 2>/dev/null)" && [ "$code" = 200 ]; then
        echo 'Production image checks passed: HTTP, cookies, CORS, isolation, worker, recovery and restart.'
        exit 0
    fi
    sleep 2
done
echo 'API did not recover after restart' >&2
exit 1

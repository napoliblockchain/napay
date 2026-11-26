#!/bin/sh
# Temporary MySQL fixture only; use compatible Yii vendor for isolated tests.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
vendor=${NAPAY_TEST_VENDOR:-$root/vendor}
[ -f "$vendor/yiisoft/yii2/Yii.php" ] || { echo 'Compatible Yii test vendor required.' >&2; exit 1; }
work=$(mktemp -d)
project="napay-schema-test-$(basename "$work" | tr '[:upper:].' '[:lower:]-')"
export NAPAY_MYSQL_VOLUME="${project}-data"
export NAPAY_MYSQL_ROOT_SECRET_FILE="$work/root-password"
export NAPAY_ENV_FILE="$work/test.env"
export NAPAY_IMPORT_DIR="$work/import"
mkdir -p "$NAPAY_IMPORT_DIR"
export DB_DATABASE=napay_test_schema DB_USERNAME=napay_test
export DB_PASSWORD="$(openssl rand -hex 24)"
umask 077
openssl rand -hex 32 > "$NAPAY_MYSQL_ROOT_SECRET_FILE"
printf 'APP_ENV=test\n' > "$NAPAY_ENV_FILE"
compose() { docker compose -p "$project" --env-file "$NAPAY_ENV_FILE" -f "$root/docker-compose.yml" "$@"; }
cleanup() {
    compose down -v --timeout 10 >/dev/null 2>&1 || true
    docker volume rm "$NAPAY_MYSQL_VOLUME" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
docker volume create "$NAPAY_MYSQL_VOLUME" >/dev/null
compose up -d --wait --wait-timeout 180 mysql
container=$(compose ps -q mysql)
network=$(docker inspect --format '{{range $name, $value := .NetworkSettings.Networks}}{{$name}}{{end}}' "$container")
printf 'NAPAY_TEST_DB_DSN=mysql:host=mysql;dbname=%s\nNAPAY_TEST_DB_USER=%s\nNAPAY_TEST_DB_PASSWORD=%s\nNAPAY_TEST_DB_CONFIRM=isolated-fixture\nNAPAY_TEST_VENDOR=/test-vendor\n' "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD" > "$work/php.env"
docker run --rm --pull never --network "$network" --read-only --tmpfs /tmp \
    --env-file "$work/php.env" -v "$root:/app:ro" -v "$vendor:/test-vendor:ro" \
    --entrypoint php "${NAPAY_TEST_PHP_IMAGE:-napay/php83-db-test:local}" /app/tests/db/v2-schema.php

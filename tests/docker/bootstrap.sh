#!/bin/sh
# Explicit private v2 dataset, read-only original; only disposable DB resources.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
dataset=${NAPAY_TEST_DATASET:?Set a reviewed private v2 dataset directory}
vendor=${NAPAY_TEST_VENDOR:-$root/vendor}
[ -f "$dataset/manifest.json" ] && [ -f "$dataset/napay-v2.sql" ] && [ -f "$vendor/yiisoft/yii2/Yii.php" ] || exit 1
work=$(mktemp -d)
project="napay-bootstrap-test-$(basename "$work" | tr '[:upper:].' '[:lower:]-')"
umask 077
openssl rand -hex 32 > "$work/root-password"
password=$(openssl rand -hex 24)
cat > "$work/test.env" <<EOF
APP_ENV=test
APP_DEBUG=false
DB_DATABASE=napay_test_bootstrap
DB_DSN=mysql:host=mysql;port=3306;dbname=napay_test_bootstrap
DB_USERNAME=napay_test
DB_PASSWORD=$password
NAPAY_MYSQL_VOLUME=$project-data
NAPAY_MYSQL_ROOT_SECRET_FILE=$work/root-password
NAPAY_ENV_FILE=$work/test.env
NAPAY_IMPORT_DIR=$dataset
NAPAY_CONSOLE_RUNTIME_PATH=/tmp/napay-runtime
NAPAY_TEST_DB_CONFIRM=isolated-fixture
NAPAY_DB_WAIT_SECONDS=10
COOKIE_VALIDATION_KEY=fixture-only-not-original
SECRET_HASH_KEY=fixture-only-not-original
ADMIN_EMAIL=fixture@example.invalid
EOF
unset password
cat > "$work/override.yml" <<EOF
services:
  bootstrap:
    volumes:
      - $vendor:/app/vendor:ro
  web:
    image: napay/bootstrap:dev
    restart: "no"
    command: ["php", "-r", "fwrite(STDOUT, 'TEST_WRITER_STARTED'.PHP_EOL);"]
    ports: !reset []
    healthcheck:
      disable: true
EOF
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$work/override.yml" "$@"; }
cleanup() {
    cleanup_code=$?
    trap - EXIT
    if [ "$cleanup_code" -ne 0 ]; then
        compose ps -a >&2 || true
        compose logs --no-color bootstrap web >&2 || true
    fi
    compose down -v --timeout 10 >/dev/null 2>&1 || true
    docker volume rm "$project-data" >/dev/null 2>&1 || true
    rm -rf "$work"
    exit "$cleanup_code"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
docker volume create "$project-data" >/dev/null
compose up -d --no-build web
writer=$(compose ps -a -q web)
for i in $(seq 1 30); do
    [ "$(docker inspect --format '{{.State.Status}}' "$writer")" = exited ] && break
    sleep 1
done
[ "$(docker inspect --format '{{.State.ExitCode}}' "$writer")" = 0 ]
started=$(docker inspect --format '{{.State.StartedAt}}' "$writer")
job=$(compose ps -a -q bootstrap)
finished=$(docker inspect --format '{{.State.FinishedAt}}' "$job")
[ "$started" \> "$finished" ]
printf '%s\n' 'PASS: Compose writer starts only after completed bootstrap.'
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/db/bootstrap.php
# TIP-020: synthetic legacy schema in the same disposable MySQL for the association import
compose exec -T mysql sh -c 'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysql -uroot -e "CREATE DATABASE napay_test_legacy CHARACTER SET utf8mb4; GRANT ALL ON napay_test_legacy.* TO \`napay_test\`@\`%\`"'
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/db/legacy-import.php
compose down -v --timeout 10
docker volume inspect "$project-data" >/dev/null
compose up -d --no-build web
container=$(compose ps -a -q bootstrap)
for i in $(seq 1 30); do
    [ "$(docker inspect --format '{{.State.Status}}' "$container")" = exited ] && break
    sleep 1
done
[ "$(docker inspect --format '{{.State.ExitCode}}' "$container")" = 0 ]
compose logs --no-color bootstrap | grep -q already-complete
printf '%s\n' 'PASS: external DB state survives down -v; recreated bootstrap is a no-op.'
exit 0

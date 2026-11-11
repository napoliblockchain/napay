#!/bin/sh
# Real Napay vendor/Apache, reviewed v2 snapshot, disposable DB, no host ports/egress.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
dataset=${NAPAY_TEST_DATASET:?Set reviewed private v2 dataset directory}
[ -f "$root/vendor/yiisoft/yii2/Yii.php" ] && [ -f "$dataset/manifest.json" ] || exit 1
work=$(mktemp -d)
project="napay-runtime-test-$(basename "$work" | tr '[:upper:].' '[:lower:]-')"
umask 077
openssl rand -hex 32 > "$work/root-password"
password=$(openssl rand -hex 24)
cat > "$work/test.env" <<ENV
APP_ENV=prod
APP_DEBUG=false
DB_DATABASE=napay_test_runtime
DB_DSN=mysql:host=mysql;port=3306;dbname=napay_test_runtime
DB_USERNAME=napay_test
DB_PASSWORD=$password
NAPAY_MYSQL_VOLUME=$project-data
NAPAY_MYSQL_ROOT_SECRET_FILE=$work/root-password
NAPAY_ENV_FILE=$work/test.env
NAPAY_IMPORT_DIR=$dataset
NAPAY_BOOTSTRAP_UID=$(id -u)
NAPAY_BOOTSTRAP_GID=$(id -g)
NAPAY_TEST_DB_CONFIRM=isolated-fixture
NAPAY_DB_WAIT_SECONDS=10
COOKIE_VALIDATION_KEY=fixture-only-not-original
SECRET_HASH_KEY=fixture-only-not-original
ADMIN_EMAIL=fixture@example.invalid
WEBAPP_LINK=https://napay.example.invalid
MAIL_TRANSPORT=file
ENV
unset password
cat > "$work/override.yml" <<YAML
services:
  web:
    restart: "no"
    ports: !reset []
    networks: !override [database]
    volumes:
      - $root:/var/www/html:ro
    tmpfs:
      - /var/www/html/runtime:uid=33,gid=33,mode=0700
      - /var/www/html/web/assets:uid=33,gid=33,mode=0755
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$work/override.yml" "$@"; }
cleanup() {
    code=$?
    trap - EXIT
    # Logs/HTML may contain copied application data: never dump them on failure.
    compose down -v --timeout 10 >/dev/null 2>&1 || true
    docker volume rm "$project-data" >/dev/null 2>&1 || true
    rm -rf "$work"
    exit "$code"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
docker volume create "$project-data" >/dev/null
compose up -d --no-build --wait --wait-timeout 240 web
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/fixture.php
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/users-access.php
compose exec -T web php /var/www/html/tests/application/http.php
compose exec -T web php /var/www/html/tests/application/users-state.php
compose exec -T web php /var/www/html/tests/application/merchants.php
compose exec -T web php /var/www/html/tests/application/password-reset.php
compose exec -T web php /var/www/html/tests/application/activation.php
compose exec -T web php /var/www/html/tests/application/association.php
compose logs --no-color web | docker run --rm -i --network none --entrypoint php php:8.3.36-cli -r '
    $log=stream_get_contents(STDIN);
    if (str_contains($log,"token=") || str_contains($log,"sign=")) exit(1);
    echo "PASS: Apache logs do not contain reset/activation bearer query strings.\n";
'
compose restart web
compose exec -T web php /var/www/html/docker/bootstrap-check.php
compose run --rm --no-deps bootstrap | grep -q already-complete
printf '%s\n' 'PASS: HTTP stack restart preserves DB; bootstrap remains a no-op.'

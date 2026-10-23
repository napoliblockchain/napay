#!/bin/sh
# Integration test: only temporary volumes, no published ports or real credentials.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
work=$(mktemp -d)
project="napay-db-test-$(basename "$work" | tr '[:upper:].' '[:lower:]-')"
export NAPAY_MYSQL_VOLUME="${project}-data"
export NAPAY_MYSQL_ROOT_SECRET_FILE="$work/root-password"
export NAPAY_ENV_FILE="$work/test.env"
export NAPAY_IMPORT_DIR="$work/import"
mkdir -p "$NAPAY_IMPORT_DIR"
export DB_DATABASE=napay DB_USERNAME=napay
export DB_PASSWORD="$(openssl rand -hex 24)"
umask 077
ciphertext=$(printf '%s' fixture-preserved-ciphertext | od -An -tx1 | tr -d ' \n')
openssl rand -hex 32 > "$NAPAY_MYSQL_ROOT_SECRET_FILE"
printf 'APP_ENV=test\n' > "$NAPAY_ENV_FILE"
compose() { docker compose -p "$project" --env-file "$NAPAY_ENV_FILE" -f "$root/docker-compose.yml" "$@"; }
tool() { compose exec -T mysql sh /opt/napay/mysql-tools.sh "$@"; }
cleanup() {
    compose down -v --timeout 10 >/dev/null 2>&1 || true
    docker volume rm "$NAPAY_MYSQL_VOLUME" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

docker volume create "$NAPAY_MYSQL_VOLUME" >/dev/null
compose config --quiet
if DB_PASSWORD= compose config --quiet >"$work/config.out" 2>"$work/config.err"; then
    printf '%s\n' 'FAIL: Compose accepted an empty application password.' >&2; exit 1
fi
printf '%s\n' 'PASS: Compose refuses an empty application password.'
compose up -d --wait --wait-timeout 180 mysql
container=$(compose ps -q mysql)
[ "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$container")" = 0 ]
printf '%s\n' 'PASS: MySQL has no host port bindings.'

if compose exec -T -e MYSQL_DATABASE=invalid-name mysql sh /opt/napay/mysql-tools.sh inventory 2>"$work/refused"; then
    printf '%s\n' 'FAIL: tool accepted an unsafe database identifier.' >&2; exit 1
fi
grep -q 'Database name must contain' "$work/refused"
printf '%s\n' 'PASS: unsafe database identifiers are rejected.'

if tool restore </dev/null 2>"$work/refused"; then
    printf '%s\n' 'FAIL: restore accepted missing confirmation.' >&2; exit 1
fi
grep -q 'requires --confirm-empty-target' "$work/refused"
printf '%s\n' 'PASS: restore requires explicit confirmation.'

cat > "$work/fixture.sql" <<'SQL'
CREATE TABLE migration (version varchar(180) PRIMARY KEY, apply_time int);
INSERT INTO migration VALUES ('m231113_093348_create_users_table', 1700000000);
CREATE TABLE legacy_data (id int PRIMARY KEY, encrypted_value varbinary(255));
INSERT INTO legacy_data VALUES (1, 'fixture-preserved-ciphertext');
SQL
tool restore --confirm-empty-target < "$work/fixture.sql"
tool backup > "$work/backup.sql"
grep -qi "$ciphertext" "$work/backup.sql"
grep -q m231113_093348_create_users_table "$work/backup.sql"
printf '%s\n' 'PASS: import and backup preserve fixture data and migration history.'

if tool restore --confirm-empty-target < "$work/fixture.sql" 2>"$work/refused"; then
    printf '%s\n' 'FAIL: restore accepted nonempty target.' >&2; exit 1
fi
grep -q 'target database is not empty' "$work/refused"
printf '%s\n' 'PASS: restore refuses nonempty target.'

# Even down -v must retain the external volume and data.
compose down -v --timeout 10
docker volume inspect "$NAPAY_MYSQL_VOLUME" >/dev/null
compose up -d --wait --wait-timeout 180 mysql
tool backup > "$work/recreated.sql"
grep -qi "$ciphertext" "$work/recreated.sql"
grep -q m231113_093348_create_users_table "$work/recreated.sql"
printf '%s\n' 'PASS: data survives down -v and container recreation.'

# A second, empty, isolated target exercises backup round-trip.
compose down -v --timeout 10
docker volume rm "$NAPAY_MYSQL_VOLUME" >/dev/null
docker volume create "$NAPAY_MYSQL_VOLUME" >/dev/null
compose up -d --wait --wait-timeout 180 mysql
tool restore --confirm-empty-target < "$work/backup.sql"
tool backup > "$work/restored.sql"
grep -qi "$ciphertext" "$work/restored.sql"
grep -q m231113_093348_create_users_table "$work/restored.sql"
printf '%s\n' 'PASS: backup restores into a fresh volume.'

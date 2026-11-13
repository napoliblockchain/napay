#!/bin/sh
# Real role overlay + full runtime regression on disposable data only.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_ROLE_BOOTSTRAP_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_ROLE_BOOTSTRAP_ROOT:?}"; next }
 /^docker volume create / { found=1; exit }
 { print }
 /docker volume rm "\$project-data"/ {
    print "    docker volume rm \"$project-runtime\" \"$project-fixture-secrets\" >/dev/null 2>&1 || true"
 }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'SETUP'
openssl rand -hex 32 > "$work/migrator-password"
openssl rand -hex 32 > "$work/worker-password"
openssl rand -hex 32 > "$work/wrong-password"
printf 'short\n' > "$work/weak-password"
cp "$work/worker-password" "$work/open-password"; chmod 0644 "$work/open-password"
cat >> "$work/test.env" <<ENV
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$work/migrator-password
NAPAY_DB_WORKER_SECRET_FILE=$work/worker-password
ENV
cat > "$work/roles-test.yml" <<YAML
services:
  web:
    tmpfs: !override
      - /var/www/html/web/assets:uid=33,gid=33,mode=0755
    volumes:
      - runtime_test:/var/www/html/runtime
  bootstrap:
    volumes:
      - $work/wrong-password:/run/secrets/roles_secret_wrong:ro
      - $work/weak-password:/run/secrets/roles_secret_weak:ro
      - $work/open-password:/run/secrets/roles_secret_open:ro
  role-test-driver:
    image: napay/bootstrap:dev
    profiles: [role-fixtures]
    user: "33:33"
    working_dir: /var/www/html
    read_only: true
    cap_drop: [ALL]
    security_opt: [no-new-privileges:true]
    tmpfs: [/tmp]
    env_file: [$work/test.env]
    environment:
      DB_USERNAME: napay_migrator
      DB_PASSWORD: ""
      NAPAY_DB_PASSWORD_FILE: /run/secrets/napay_db_migrator_password
      NAPAY_DB_ROLE_MODE: "true"
      NAPAY_DB_ROLE: bootstrap
    network_mode: service:web
    volumes:
      - $root:/var/www/html:ro
      - runtime_test:/var/www/html/runtime
      - fixture_secrets:/run/secrets:ro

volumes:
  runtime_test:
    external: true
    name: $project-runtime
  fixture_secrets:
    external: true
    name: $project-fixture-secrets
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" -f "$work/override.yml" -f "$work/roles-test.yml" "$@"; }
docker volume create "$project-runtime" >/dev/null
docker volume create "$project-fixture-secrets" >/dev/null
# Separate trusted fixture container, never web: same UID/runtime/network as Apache,
# but only it receives the migrator secret. Preserve 0700/0600 on directories/files.
docker run --rm --network none --read-only --security-opt no-new-privileges:true --user 0:0 --cap-drop ALL --cap-add CHOWN --cap-add DAC_OVERRIDE \
    -v "$work/migrator-password:/input-secret:ro" -v "$project-runtime:/runtime" \
    -v "$project-fixture-secrets:/output" --entrypoint sh php:8.3.36-cli -c '
    umask 077; cp /input-secret /output/napay_db_migrator_password
    chmod 0600 /output/napay_db_migrator_password
    chmod 0700 /output /runtime; chown -R 33:33 /output /runtime
'
SETUP
awk '
 /^docker volume create / { copy=1 }
 !copy { next }
 /^compose run --rm --no-deps bootstrap \| grep -q already-complete$/ {
    print "compose run --rm --no-deps bootstrap > \"$work/bootstrap-repeat.log\" 2>&1"
    print "grep -q already-complete \"$work/bootstrap-repeat.log\""
    print "grep -q \"Napay database roles: restricted.\" \"$work/bootstrap-repeat.log\""; next
 }
 /compose exec -T web php .*\/(password-reset|activation|pos-sessions)\.php$/ {
    sub("compose exec -T web php", "compose run --rm --no-deps --entrypoint php role-test-driver"); print; next
 }
 { print }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*fixture.php$/ {
    print "compose run --rm --no-deps worker-db-check"
    print "compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/roles-bootstrap.php"
 }
' "$root/tests/docker/runtime.sh" >> "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'VERIFY'
compose exec -T web php -r '
foreach (["/run/secrets/mysql_root_password", "/run/secrets/napay_db_migrator_password", "/run/secrets/napay_db_worker_password"] as $file) if (file_exists($file)) exit(1);
if (getenv("NAPAY_WEB_DB_PASSWORD") !== false || getenv("NAPAY_DB_PASSWORD_FILE") !== "") exit(1);
echo "PASS: web has no privileged secrets or password variables.\n";
'
for file in roles_secret_missing roles_secret_weak roles_secret_open roles_secret_wrong; do
    if compose run --rm --no-deps -e "NAPAY_DB_PASSWORD_FILE=/run/secrets/$file" bootstrap > "$work/negative.log" 2>&1; then
        printf '%s\n' 'FAIL: invalid bootstrap secret accepted' >&2; exit 1
    fi
    compose exec -T web php /var/www/html/docker/bootstrap-check.php
    printf '%s\n' 'PASS: bad bootstrap secret rejected without breaking web readiness.'
done
compose run --rm --no-deps worker-db-check
printf '%s\n' 'PASS: worker remains ready after restart and rejected bootstrap attempts.'
VERIFY
sh "$private/runtime.sh"

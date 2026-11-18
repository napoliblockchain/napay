#!/bin/sh
# Real dedicated CLI + real role grants, disposable DB. No BTCPay/network request or shared sandbox.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_CLI_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_CLI_ROOT:?}"; next }
 /^docker volume create / { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'CLI'
openssl rand -hex 32 > "$work/migrator-password"
openssl rand -hex 32 > "$work/worker-password"
openssl rand -hex 32 > "$work/provisioner"
printf '{"current":"k1","keys":{"k1":"%s"}}\n' "$(openssl rand -base64 32)" > "$work/ring"
cat >> "$work/test.env" <<ENV
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$work/migrator-password
NAPAY_DB_WORKER_SECRET_FILE=$work/worker-password
ENV
cat > "$work/cli.yml" <<YAML
services:
  worker-cli:
    image: napay/bootstrap:dev
    profiles: [cli-test]
    user: "$(id -u):$(id -g)"
    working_dir: /app
    entrypoint: [php, yii-btcpay]
    read_only: true
    cap_drop: [ALL]
    security_opt: [no-new-privileges:true]
    tmpfs: [/tmp]
    environment:
      APP_ENV: prod
      APP_DEBUG: "false"
      NAPAY_DB_ROLE_MODE: "true"
      NAPAY_DB_ROLE: worker
      NAPAY_DB_PASSWORD_FILE: /run/secrets/napay_db_worker_password
      DB_DATABASE: napay_test_runtime
      DB_DSN: mysql:host=mysql;port=3306;dbname=napay_test_runtime
      DB_USERNAME: napay_worker
      PAYMENT_PROXY: http://egress:3128
      BTCPAY_PROVISION_ORIGIN: https://btcpay.regtest.invalid
      BTCPAY_PROVISIONER_KEY_FILE: /run/secrets/btcpay_provisioner_key
      BTCPAY_KEK_KEYRING_FILE: /run/secrets/btcpay_kek_keyring
    secrets: [napay_db_worker_password, btcpay_provisioner_key, btcpay_kek_keyring]
    volumes: [$root:/app:ro]
    networks: [database]
secrets:
  btcpay_provisioner_key:
    file: $work/provisioner
  btcpay_kek_keyring:
    file: $work/ring
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" -f "$work/override.yml" -f "$work/cli.yml" "$@"; }
docker volume create "$project-data" >/dev/null
compose up -d --no-build --wait --wait-timeout 240 web
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/fixture.php
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
if docker run --rm --network none --read-only --cap-drop ALL -v "$root/yii-btcpay:/isolated/yii-btcpay:ro" \
    --entrypoint php napay/bootstrap:dev /isolated/yii-btcpay btcpay-provision/run --once > "$work/missing.out" 2>&1; then exit 1; fi
[ "$(cat "$work/missing.out")" = 'BTCPay worker failed.' ]
pass 'Missing bootstrap files produce no native warning, path or trace'
private_output() {
    for secret in root-password migrator-password worker-password provisioner; do
        if grep -Fq -f "$work/$secret" "$work/cli.out"; then echo 'FAIL: CLI leaked a fixture secret.' >&2; exit 1; fi
    done
    if grep -Eq 'Stack trace|SQLSTATE|PDOException|RuntimeException|/app/|/run/secrets/' "$work/cli.out"; then
        echo 'FAIL: CLI exposed internal diagnostics.' >&2; exit 1
    fi
}
compose run --rm --no-deps worker-cli btcpay-provision/run --once > "$work/cli.out" 2>&1
grep -qx idle "$work/cli.out"; private_output
pass 'Dedicated CLI reaches idle with real restricted worker grants and no web keys'
for route in links reconcile; do
    compose run --rm --no-deps -e BTCPAY_PROVISIONER_KEY_FILE=/run/secrets/missing worker-cli "btcpay-provision/$route" --once > "$work/cli.out" 2>&1
    grep -qx idle "$work/cli.out"; private_output
    pass "W3e: $route reaches idle without reading the provisioner key"
done
if compose run --rm --no-deps worker-cli btcpay-provision/links > "$work/cli.out" 2>&1; then exit 1; fi
grep -qx 'Usage: php yii-btcpay btcpay-provision/{run|links|reconcile} --once' "$work/cli.out"; private_output
pass 'W3e: links without --once refused before bootstrap'
if compose run --rm --no-deps worker-cli migrate/up --once > "$work/cli.out" 2>&1; then exit 1; fi
grep -qx 'Usage: php yii-btcpay btcpay-provision/{run|links|reconcile} --once' "$work/cli.out"; private_output
pass 'Unrelated commands refused before bootstrap'
if compose run --rm --no-deps worker-cli btcpay-provision/run > "$work/cli.out" 2>&1; then exit 1; fi
grep -qx 'Usage: php yii-btcpay btcpay-provision/{run|links|reconcile} --once' "$work/cli.out"; private_output
pass 'No daemon or implicit loop without --once'
refuse() {
    if compose run --rm --no-deps -e "$1" worker-cli btcpay-provision/run --once > "$work/cli.out" 2>&1; then exit 1; fi
    grep -qx 'BTCPay worker failed.' "$work/cli.out"; private_output
    pass 'Invalid worker configuration fails without secret values or exception trace'
}
refuse NAPAY_DB_ROLE=bootstrap
refuse APP_DEBUG=true
refuse PAYMENT_PROXY=
refuse BTCPAY_PROVISION_ORIGIN=http://btcpay.regtest.invalid
refuse BTCPAY_PROVISIONER_KEY_FILE=/run/secrets/missing
refuse BTCPAY_KEK_KEYRING_FILE=/run/secrets/missing
refuse DB_USERNAME=napay_not_available
cat > "$work/broad.yml" <<YAML
secrets:
  napay_db_worker_password:
    file: $work/migrator-password
YAML
if docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" \
    -f "$work/override.yml" -f "$work/cli.yml" -f "$work/broad.yml" run --rm --no-deps -e DB_USERNAME=napay_migrator \
    worker-cli btcpay-provision/run --once > "$work/cli.out" 2>&1; then exit 1; fi
grep -qx 'BTCPay worker failed.' "$work/cli.out"; private_output
pass 'An authentic migrator login is rejected by restricted readiness before work'
compose run --rm --no-deps worker-db-check
pass 'Worker remains ready after rejected commands/configurations'
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/btcpay-worker-cli-fixture.php --seed
compose run --rm --no-deps worker-cli btcpay-provision/run --once > "$work/cli.out" 2>&1
grep -qx job-processed "$work/cli.out"; private_output
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/btcpay-worker-cli-fixture.php --verify
pass 'CLI processes an ineligible job with fenced failure, no mapping or remote request'
printf 'PASS %s dedicated worker CLI checks\n' "$checks"
CLI
sh "$private/runtime.sh"

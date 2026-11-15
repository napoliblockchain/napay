#!/bin/sh
# SIP-021 fase 3: pagamenti TTS su stack e chain Besu usa-e-getta (rete interna, nessuna porta host); mai la sandbox condivisa.
# Richiede le immagini locali napay/chain-tools:dev e hyperledger/besu:26.9.0 (start-localhost.sh --build le prepara).
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_TTS_ROOT="$root"
docker image inspect napay/chain-tools:dev hyperledger/besu:26.9.0 >/dev/null
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
# Fail closed if the common fixture boundary changes. Do not run other suites.
awk '
    /^root=/ { print "root=${NAPAY_TTS_ROOT:?}"; next }
    /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
    { print }
    END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
# Chain di test nello stesso progetto Compose: volume e container rimossi da cleanup (down -v).
cat > "$work/chain.yml" <<YAML
services:
  tts-chain-init:
    image: napay/chain-tools:dev
    command: ["node", "init.mjs"]
    environment: { CHAIN_ID: "2118" }
    volumes: [tts_chain:/data]
    networks: [database]
  tts-besu:
    image: hyperledger/besu:26.9.0
    user: "1000:1000"
    command: ["--data-path=/data/db", "--genesis-file=/data/genesis.json", "--node-private-key-file=/data/key", "--rpc-http-enabled",
      "--rpc-http-host=0.0.0.0", "--rpc-http-api=ETH,NET,WEB3", "--host-allowlist=*", "--min-gas-price=0",
      "--discovery-enabled=false", "--sync-mode=FULL", "--sync-min-peers=0"]
    volumes: [tts_chain:/data]
    networks: [database]
    healthcheck:
      test: ["CMD", "bash", "-c", "exec 3<>/dev/tcp/127.0.0.1/8545"]
      interval: 3s
      retries: 40
  tts-chain-tools:
    image: napay/chain-tools:dev
    environment: { CHAIN_RPC_URL: "http://tts-besu:8545", CHAIN_ID: "2118" }
    volumes: [tts_chain:/data, $root/tests/application/tts-pay.mjs:/tools/tts-pay.mjs:ro]
    networks: [database]
volumes:
  tts_chain:
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$work/override.yml" -f "$work/chain.yml" "$@"; }
compose run --rm --no-deps tts-chain-init >/dev/null
compose up -d --no-deps --wait --wait-timeout 180 tts-besu
compose run --rm --no-deps tts-chain-tools node deploy.mjs >/dev/null
token=$(compose run --rm --no-deps tts-chain-tools node -e 'console.log(JSON.parse(require("fs").readFileSync("/data/tts.json","utf8")).address)')
printf 'TTS_RPC_URL=http://tts-besu:8545\nTTS_CHAIN_ID=2118\nTTS_TOKEN_ADDRESS=%s\n' "$token" >> "$work/test.env"
compose up -d --no-build --no-deps --force-recreate --wait --wait-timeout 240 web
compose exec -T web php /var/www/html/tests/application/pos-tts-payments.php setup
compose exec -T web cat /tmp/napay-tts-plan.json \
    | compose run --rm --no-deps -T tts-chain-tools node tts-pay.mjs \
    | compose exec -T web sh -c 'cat > /tmp/napay-tts-paid.json'
compose exec -T web php /var/www/html/tests/application/pos-tts-payments.php verify
! compose exec -T web php /var/www/html/yii tts-payments/sweep >/dev/null 2>&1
printf '%s\n' 'PASS: sweep refuses to run without --once.'
[ "$(compose exec -T web php /var/www/html/yii tts-payments/sweep --once)" = 'checked=2 paid=0 expired=2' ]
printf '%s\n' 'PASS: one-shot sweep closes the two expired unpaid payments.'
compose stop tts-besu >/dev/null
compose exec -T web php /var/www/html/tests/application/pos-tts-payments.php offline
[ "$(compose exec -T web php /var/www/html/yii tts-payments/sweep --once 2>&1)" = 'checked=0 paid=0 expired=0' ]
printf '%s\n' 'PASS: sweep with nothing expired is a no-op even with the chain down.'
# Inspect Apache logs in memory, never dump potentially sensitive failures.
compose logs --no-color web | compose exec -T web php -r '
    $logs = stream_get_contents(STDIN);
    $secret = getenv("NAPAY_TEST_POS_CLIENT_KEY");
    if (!is_string($secret) || $secret === "" || str_contains($logs, $secret) || preg_match("/napos_[A-Za-z0-9_-]{43}/", $logs)) exit(1);
    echo "PASS: Apache logs contain no bearer or client key.\n";
'
compose run --rm --no-deps bootstrap
TESTS
sh "$private/runtime.sh"

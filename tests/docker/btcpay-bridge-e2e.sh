#!/bin/sh
# SIP-030 passo 5, collaudo reale: negozi v1 sul ponte BTCPay contro BTCPay regtest usa-e-getta.
#   Store "v1" di proprietà dell'utente ponte (come in produzione), chiave ponte con canmodifystoresettings;
#   comando btcpay-bridge/sync (utente DB del web, come il cron) via proxy di uscita + TLS btcpay.local:
#   webhook e app POS del terminale, fattura dall'app senza chiave, pagamento e conferma, consegna firmata accettata.
#   Store di un altro utente: la chiave ponte riceve 403 e il collegamento resta fallito (nessun accesso).
# Nessuna porta sull'host, nessun Internet; segreti solo in cartelle 0700 temporanee, mai stampati.
# Uso: NAPAY_TEST_DATASET=... sh tests/docker/btcpay-bridge-e2e.sh  (stack regtest in ../docker/btcpay-regtest)
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
regtest=${NAPAY_BTCPAY_REGTEST_DIR:-$root/../docker/btcpay-regtest}
[ -f "$regtest/local.sh" ] && [ -f "$regtest/tests/fixtures/gateway/Caddyfile" ] || { echo 'FAIL: BTCPay regtest stack not found' >&2; exit 1; }
export NAPAY_CLI_ROOT="$root" NAPAY_E2E_REGTEST="$(CDPATH= cd -- "$regtest" && pwd -P)"
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
cat >> "$private/runtime.sh" <<'E2E'
regtest=${NAPAY_E2E_REGTEST:?}
run=$(openssl rand -hex 4)
p="napay-bridge-$run"
caddy=caddy:2.8.4-alpine@sha256:af32e97399febea808609119bb21544d0265c58a02836576e32a2d082c262c17
client=$(sed -n 's/^ *image: \(nicolasdorier\/nbxplorer:.*\)$/\1/p' "$regtest/compose.yml")
domain=btcpay-merchants.jamprojects.it
export BTCPAY_PROJECT="$p-btcpay" BTCPAY_EDGE_NETWORK="${p}_edge" BTCPAY_LOCAL_STATE="$work/btcpay-state"
export BTCPAY_EDGE_SUBNET="10.251.$(( $(od -An -N1 -tu1 /dev/urandom) % 200 + 20 )).0/24"
mkdir -m 700 "$work/w"
e2e_cleanup() {
    bash "$regtest/local.sh" reset --yes >/dev/null 2>&1 || true
    docker rm -f "$p-gateway" "$p-egress" >/dev/null 2>&1 || true
    for net in client napay; do docker network rm "$p-$net" >/dev/null 2>&1 || true; done
    docker network rm "$BTCPAY_EDGE_NETWORK" >/dev/null 2>&1 || true
    docker image rm "$p-egress:test" >/dev/null 2>&1 || true
}
trap 'rc=$?; set +e; compose down -v --timeout 10 >/dev/null 2>&1 || true; e2e_cleanup; (exit $rc); cleanup' EXIT
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
# API BTCPay per preparazione e verifiche: HTTP diretto sulla rete edge, solo in questo test (Napay passa da proxy + TLS)
api() { # api METHOD PATH [BODY_FILE] [HEADER_FILE]: corpo e header facoltativi (primo admin senza credenziali)
    printf 'url = "http://btcpay-regtest:49392%s"\n' "$2" > "$work/w/request.cfg"
    set -- "$1" "$2" "${3:-}" "${4:-}"
    set -- "$@" -s -o /w/out.json -w '%{http_code}' -X "$1" --max-time 30 -K /w/request.cfg
    [ -z "$4" ] || set -- "$@" -H "@/w/$4"
    [ -z "$3" ] || set -- "$@" -H 'Content-Type: application/json' --data-binary "@/w/$3"
    shift 4
    code=$(docker run --rm --user "$(id -u):$(id -g)" --network "$BTCPAY_EDGE_NETWORK" -v "$work/w:/w" --entrypoint curl "$client" "$@") || code=000
    rm -f "$work/w/request.cfg"
}
json() { python3 -I -c "import json,sys; d=json.load(open(sys.argv[1])); print($1)" "$2"; }
user() { # user EMAIL -> id in $work/w/out.json; password casuale mai stampata
    python3 -I -c "import json,sys,secrets; open(sys.argv[1],'w').write(json.dumps({'email':sys.argv[2],'password':secrets.token_hex(24),'isAdministrator':False}))" "$work/w/user.json" "$1"
    api POST /api/v1/users user.json admin.hdr
}
keyfor() { # keyfor USERID FILE: chiave canmodifystoresettings dell'utente, salvata come header privato
    printf '%s' '{"label":"napay-e2e","permissions":["btcpay.store.canmodifystoresettings"]}' > "$work/w/key.json"
    api POST "/api/v1/users/$1/api-keys" key.json admin.hdr
    [ "$code" = 200 ] || fail "API key ($code)"
    python3 -I -c "import json,sys; k=json.load(open(sys.argv[1]))['apiKey']; open(sys.argv[2],'w').write('Authorization: token '+k+'\\n'); open(sys.argv[3],'w').write(k)" "$work/w/out.json" "$work/w/$2.hdr" "$work/$2"
    rm -f "$work/w/out.json"
}
store() { # store HDR NAME -> remote id in $remote, wallet vpub e cambi fissi (regtest senza Internet)
    python3 -I -c "import json,sys; open(sys.argv[1],'w').write(json.dumps({'name':sys.argv[2],'defaultCurrency':'EUR'}))" "$work/w/store.json" "$2"
    api POST /api/v1/stores store.json "$1"
    [ "$code" = 200 ] || fail "store ($code)"
    remote=$(json "d['id']" "$work/w/out.json")
    python3 -I -c "import json,sys; open(sys.argv[1],'w').write(json.dumps({'enabled':True,'config':sys.argv[2]}))" "$work/w/wallet.json" "$vpub"
    api PUT "/api/v1/stores/$remote/payment-methods/BTC-CHAIN" wallet.json "$1"
    [ "$code" = 200 ] || fail "store wallet ($code)"
    printf '%s' '{"isCustomScript":true,"effectiveScript":"BTC_EUR = 50000;\nBTC_USD = 60000;"}' > "$work/w/rates.json"
    api PUT "/api/v1/stores/$remote/rates/configuration" rates.json "$1"
    [ "$code" = 200 ] || fail "store rates ($code)"
}
vpub=vpub5Zf9o1xg1TVJN3pte4sDD73BMq71dLFTZPgqvBxe872nMEiDtoBo99udYHBuxP3wDbhie79PmTtL8GxYR5ana9FgxdKTp13TvV3kU6UaHrP

# --- 1. BTCPay regtest usa-e-getta: admin, utente ponte con il suo store "v1", altro utente con il suo store
bash "$regtest/local.sh" up >/dev/null
admin_pw=$(openssl rand -hex 24)
python3 -I -c "import json,sys; open(sys.argv[1],'w').write(json.dumps({'email':'e2e-admin@$domain','password':sys.stdin.read().strip(),'isAdministrator':True}))" "$work/w/admin.json" <<EOF
$admin_pw
EOF
printf 'Authorization: Basic %s\n' "$(printf '%s:%s' "e2e-admin@$domain" "$admin_pw" | base64 -w0)" > "$work/w/admin.hdr"
unset admin_pw
api POST /api/v1/users admin.json
[ "$code" = 201 ] || fail "first BTCPay admin ($code)"
user "v1-bridge@$domain"; [ "$code" = 201 ] || fail "bridge user ($code)"
bridgeUser=$(json "d['id']" "$work/w/out.json")
user "v1-other@$domain"; [ "$code" = 201 ] || fail "other user ($code)"
otherUser=$(json "d['id']" "$work/w/out.json")
keyfor "$bridgeUser" bridge
keyfor "$otherUser" other
store bridge.hdr 'Negozio v1'; remoteV1=$remote
store other.hdr 'Negozio di altri'; remoteOther=$remote
pass 'Regtest: v1 store owned by the bridge user, another store owned by someone else, bridge key canmodifystoresettings only'

# --- 2. gateway TLS btcpay.local (CA locale) e proxy di uscita Napay
docker network create --internal "$p-client" >/dev/null
docker network create --internal "$p-napay" >/dev/null
docker create --name "$p-gateway" --network "$BTCPAY_EDGE_NETWORK" -e EDGE_SUBNET="$BTCPAY_EDGE_SUBNET" \
    -v "$regtest/tests/fixtures/gateway/Caddyfile:/etc/caddy/Caddyfile:ro" "$caddy" >/dev/null
docker network connect --alias btcpay.local "$p-client" "$p-gateway"
docker start "$p-gateway" >/dev/null
docker build -q -t "$p-egress:test" "$root/docker/egress" >/dev/null
docker create --name "$p-egress" --network "$p-napay" --network-alias egress --read-only --tmpfs /tmp --cap-drop ALL "$p-egress:test" >/dev/null
docker network connect "$p-client" "$p-egress"
docker start "$p-egress" >/dev/null
for _ in $(seq 1 30); do docker exec "$p-gateway" test -f /data/caddy/pki/authorities/local/root.crt 2>/dev/null && break; sleep 1; done
docker cp "$p-gateway:/data/caddy/pki/authorities/local/root.crt" "$work/ca.crt" >/dev/null
chmod 644 "$work/ca.crt"

# --- 3. Napay con ruoli reali; comando del ponte con l'utente DB del web (come il cron), chiave ponte solo in file privato
openssl rand -hex 32 > "$work/migrator-password"
openssl rand -hex 32 > "$work/worker-password"
openssl rand -hex 32 > "$work/webhook-key"
instance=$(printf %s https://btcpay.local | sha256sum | cut -c1-64)
cat >> "$work/test.env" <<ENV
BTCPAY_PROVISION_INSTANCE=$instance
BTCPAY_STORE_WEBHOOK_KEY=$(cat "$work/webhook-key")
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$work/migrator-password
NAPAY_DB_WORKER_SECRET_FILE=$work/worker-password
ENV
cat > "$work/bridge.env" <<ENV
PAYMENT_PROXY=http://egress:3128
BTCPAY_URL=https://btcpay.local
BTCPAY_PROVISION_ORIGIN=https://btcpay.local
BTCPAY_PROVISION_CA_FILE=/run/btcpay-ca.crt
BTCPAY_STORES_API_KEY=$(cat "$work/bridge")
BTCPAY_STORE_WEBHOOK_URL=https://napay.example.invalid/api/btcpay/v1/store-webhook
ENV
cat > "$work/cli.yml" <<YAML
services:
  bridge-cli:
    image: napay/bootstrap:dev
    profiles: [cli-test]
    user: "$(id -u):$(id -g)"
    working_dir: /app
    entrypoint: [php, yii]
    read_only: true
    cap_drop: [ALL]
    security_opt: [no-new-privileges:true]
    tmpfs: [/tmp, "/app/runtime:uid=$(id -u),gid=$(id -g),mode=0700"]
    env_file: [$work/test.env, $work/bridge.env]
    environment:
      NAPAY_DB_ROLE_MODE: "true"
      NAPAY_DB_ROLE: web
      NAPAY_DB_PASSWORD_FILE: ""
    volumes: [$root:/app:ro, $work/ca.crt:/run/btcpay-ca.crt:ro]
    networks: [database, e2e]
networks:
  e2e:
    external: true
    name: $p-napay
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" -f "$work/override.yml" -f "$work/cli.yml" "$@"; }
docker volume create "$project-data" >/dev/null
compose up -d --no-build --wait --wait-timeout 240 web
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/fixture.php
fixture() { compose run --rm --no-deps -e "BTCPAY_PROVISION_INSTANCE=$instance" --entrypoint php bootstrap /app/tests/application/btcpay-e2e-fixture.php "$@"; }
fixture --bridge-seed "$remoteV1" "$vpub" | tail -1 > "$work/w/seed.json"
fixture --bridge-seed "$remoteOther" "$vpub" | tail -1 > "$work/w/seed-other.json"
storeV1=$(json "d['store']" "$work/w/seed.json"); terminalV1=$(json "d['terminal']" "$work/w/seed.json")
terminalOther=$(json "d['terminal']" "$work/w/seed-other.json")
[ "$(json "d['target_before']" "$work/w/seed.json")" = None ] || fail 'v1 terminal had a BTC target before the bridge app'
pass 'Napay: two v1 stores (bps_storeid) with xpub and an enabled terminal each; no BTC target yet'

# --- 4. comando del ponte (come il cron): webhook e app POS con la chiave ponte, attraverso proxy e TLS
sync() {
    compose run --rm --no-deps bridge-cli btcpay-bridge/sync --once > "$work/cli.out" 2>&1 || { cat "$work/cli.out" >&2; fail 'bridge sync failed'; }
    for secret in bridge webhook-key root-password migrator-password worker-password; do
        if grep -Fq -f "$work/$secret" "$work/cli.out"; then echo 'FAIL: bridge sync leaked a secret.' >&2; exit 1; fi
    done
    if grep -Eq 'Stack trace|SQLSTATE|PDOException|Exception|/app/' "$work/cli.out"; then echo 'FAIL: bridge sync exposed diagnostics.' >&2; exit 1; fi
}
sync
grep -q 'stores=2 .*terminals=2 .*webhooks=1 created=1 ' "$work/cli.out" || fail "first sync: $(cat "$work/cli.out")"
fixture --bridge-state "$terminalV1" | tail -1 > "$work/w/state.json"
appV1=$(json "[x['remote_app_id'] for x in d['pos'] if x['terminal_id']==$terminalV1][0]" "$work/w/state.json")
[ "$(json "[(s['webhook_state'], s['last_error_code']) for s in d['stores']]" "$work/w/state.json")" = "[('synced', None), ('failed', 'merchant_key_denied')]" ] \
    || fail "bridge stores: $(json "d['stores']" "$work/w/state.json")"
[ "$(json "[(x['state'], x['last_error_code'], x['currency']) for x in d['pos']]" "$work/w/state.json")" = "[('synced', None, 'EUR'), ('failed', 'merchant_key_denied', None)]" ] \
    || fail "bridge apps: $(json "d['pos']" "$work/w/state.json")"
[ "$(json "d['target']" "$work/w/state.json")" = "{'remote_store_id': '$remoteV1', 'remote_app_id': '$appV1', 'currency': 'EUR'}" ] || fail 'BTC target of the v1 terminal'
pass 'Bridge sync through proxy and TLS: webhook and POS app on the v1 store; store of another user refused (403), nothing created'
api GET "/api/v1/stores/$remoteV1/apps" "" bridge.hdr
[ "$code" = 200 ] && [ "$(json "[(a['id'], a.get('appType')) for a in d]" "$work/w/out.json")" = "[('$appV1', 'PointOfSale')]" ] || fail "apps on the v1 store ($code)"
api GET "/api/v1/apps/pos/$appV1" "" bridge.hdr
[ "$code" = 200 ] && [ "$(json "(d['currency'], d['showCustomAmount'], d['defaultView'])" "$work/w/out.json")" = "('EUR', True, 'Light')" ] || fail "POS app config ($code)"
api GET "/api/v1/stores/$remoteV1/webhooks" "" bridge.hdr
[ "$code" = 200 ] && [ "$(json "[(h['url'], h['enabled'], sorted(h['authorizedEvents']['specificEvents'])) for h in d]" "$work/w/out.json")" \
    = "[('https://napay.example.invalid/api/btcpay/v1/store-webhook', True, ['InvoiceExpired', 'InvoiceInvalid', 'InvoiceProcessing', 'InvoiceReceivedPayment', 'InvoiceSettled'])]" ] \
    || fail "webhook on the v1 store ($code)"
api GET "/api/v1/stores/$remoteOther/apps" "" other.hdr
[ "$code" = 200 ] && [ "$(json "len(d)" "$work/w/out.json")" = 0 ] || fail "app created on a store of another user ($code)"
pass 'BTCPay: exactly one keypad POS app in the store currency and one Napay webhook on the v1 store; other store untouched'
sync
grep -q 'stores=0 .*terminals=0 webhooks=0 created=0 adopted=0 reconfigured=0 waiting=0 failed=0' "$work/cli.out" || fail "second sync: $(cat "$work/cli.out")"
api GET "/api/v1/stores/$remoteV1/apps" "" bridge.hdr
[ "$(json "len(d)" "$work/w/out.json")" = 1 ] || fail 'second sync created another app'
pass 'Second sync: no new app, no new webhook, nothing to do'

# --- 5. fattura dall'app del ponte (gateway Napay, senza chiave), pagamento e conferma
compose run --rm --no-deps -e "BTCPAY_PROVISION_INSTANCE=$instance" --entrypoint php bridge-cli /app/tests/application/btcpay-pos-gateway.php "$appV1" 1234 > "$work/w/gw.json" 2>"$work/gw.err" \
    || { head -c 400 "$work/w/gw.json" >&2; head -c 400 "$work/gw.err" >&2; fail 'POS gateway run'; }
[ "$(json "(d['created']['outcome'], d['status']['store_id'], d['status']['currency'], d['status']['fiat_digits'], d['status']['status'], d['status']['rate_cents'])" "$work/w/gw.json")" \
    = "('created', '$remoteV1', 'EUR', '1234', 'new', 5000000)" ] || fail "invoice on the bridge app: $(cat "$work/w/gw.json")"
pass 'Invoice from the bridge POS app via the Napay gateway: v1 store, EUR, rate read from the public status'
gwInvoice=$(json "d['created']['invoice_id']" "$work/w/gw.json")
gwTx=$(bash "$regtest/local.sh" pay "bitcoin:$(json "d['status']['address']" "$work/w/gw.json")?amount=$(json "'%d.%08d' % divmod(d['status']['due_satoshis'], 100000000)" "$work/w/gw.json")" | tr -d '\r')
bash "$regtest/local.sh" mine 1 >/dev/null
for _ in 1 2 3 4 5 6 7 8 9 10; do
    compose run --rm --no-deps -e "BTCPAY_PROVISION_INSTANCE=$instance" --entrypoint php bridge-cli /app/tests/application/btcpay-pos-gateway.php tx "$gwInvoice" > "$work/w/gwtx.json" 2>/dev/null || true
    [ "$(json "d['status']['status'] if d.get('status') else ''" "$work/w/gwtx.json" 2>/dev/null)" = settled ] && break
    sleep 2
done
[ "$(json "(d['txids'], d['status']['status'], d['status']['confirmations'])" "$work/w/gwtx.json")" = "(['$gwTx'], 'settled', 1)" ] || fail "payment: $(cat "$work/w/gwtx.json")"
pass 'Regtest customer pays the invoice of the v1 store; settled after one block with its txid'

# --- 6. consegna firmata come BTCPay: accettata per il negozio v1 sul ponte, rifiutata con firma o segreto sbagliati
[ "$(fixture --deliver "$storeV1" "$remoteV1" good BridgeDelivery01 | tail -1)" = '200 recorded' ] || fail 'signed delivery for the bridge store not recorded'
[ "$(fixture --deliver "$storeV1" "$remoteV1" badsig BridgeDelivery02 | tail -1)" = '401 invalid_signature' ] || fail 'bad signature accepted'
[ "$(fixture --deliver "$storeV1" "$remoteV1" otherstore BridgeDelivery03 | tail -1)" = '401 invalid_signature' ] || fail 'secret of another store accepted'
pass 'Webhook endpoint recognises the v1 store on the bridge: signed delivery recorded, wrong signature or store secret refused'
printf 'PASS %s BTCPay bridge e2e checks (regtest, no real money)\n' "$checks"
E2E
sh "$private/runtime.sh"

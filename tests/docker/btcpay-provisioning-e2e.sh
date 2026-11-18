#!/bin/sh
# SIP-022 collaudo reale (B2-B4): worker Napay contro BTCPay regtest, tutto usa-e-getta.
#   BTCPay regtest (istanza propria) -- edge -- gateway TLS btcpay.local -- client -- egress Napay -- worker CLI (ruolo worker)
# Due merchant: utente BTCPay e chiave per ciascuno, store del negozio, app POS del terminale; ripetizione senza duplicati.
# Nessuna porta sull'host, nessun Internet; segreti solo in cartelle 0700 temporanee, mai stampati.
# Uso: NAPAY_TEST_DATASET=... sh tests/docker/btcpay-provisioning-e2e.sh  (stack regtest in ../docker/btcpay-regtest)
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
p="napay-e2e-$run"
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
# stack Napay giù prima delle reti esterne: senza la rete e2e il compose non sarebbe più valido e down fallirebbe
trap 'rc=$?; set +e; compose down -v --timeout 10 >/dev/null 2>&1 || true; e2e_cleanup; (exit $rc); cleanup' EXIT
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# --- API BTCPay per le verifiche: HTTP diretto sulla rete edge, solo in questo test (il worker passa da proxy + TLS)
# risposta in $work/w/out.json, credenziali e URL in file privati, mai negli argomenti di processo
api() {
    printf 'url = "http://btcpay-regtest:49392%s"\n' "$2" > "$work/w/request.cfg"
    set -- "$1" "$2" "${3:-}" "${4:-}"
    if [ -n "$3" ] && [ -n "$4" ]; then
        code=$(docker run --rm --user "$(id -u):$(id -g)" --network "$BTCPAY_EDGE_NETWORK" -v "$work/w:/w" --entrypoint curl "$client" \
            -s -o /w/out.json -w '%{http_code}' -X "$1" --max-time 30 -K /w/request.cfg -H "@/w/$4" -H 'Content-Type: application/json' --data-binary "@/w/$3") || code=000
    elif [ -z "$3" ] && [ -z "$4" ]; then
        # richiesta anonima (pagine pubbliche della fattura)
        code=$(docker run --rm --user "$(id -u):$(id -g)" --network "$BTCPAY_EDGE_NETWORK" -v "$work/w:/w" --entrypoint curl "$client" \
            -s -o /w/out.json -w '%{http_code}' -X "$1" --max-time 30 -K /w/request.cfg) || code=000
    elif [ -n "$4" ]; then
        code=$(docker run --rm --user "$(id -u):$(id -g)" --network "$BTCPAY_EDGE_NETWORK" -v "$work/w:/w" --entrypoint curl "$client" \
            -s -o /w/out.json -w '%{http_code}' -X "$1" --max-time 30 -K /w/request.cfg -H "@/w/$4") || code=000
    else
        code=$(docker run --rm --user "$(id -u):$(id -g)" --network "$BTCPAY_EDGE_NETWORK" -v "$work/w:/w" --entrypoint curl "$client" \
            -s -o /w/out.json -w '%{http_code}' -X "$1" --max-time 30 -K /w/request.cfg -H 'Content-Type: application/json' --data-binary "@/w/$3") || code=000
    fi
    rm -f "$work/w/request.cfg"
}
json() { python3 -I -c "import json,sys; d=json.load(open(sys.argv[1])); print($1)" "$2"; }

# --- 1. BTCPay regtest usa-e-getta, primo admin e chiave del provisioner (solo utenti)
bash "$regtest/local.sh" up >/dev/null
admin_pw=$(openssl rand -hex 24)
python3 -I -c "import json,sys; open(sys.argv[1],'w').write(json.dumps({'email':'e2e-admin@$domain','password':sys.stdin.read().strip(),'isAdministrator':True}))" "$work/w/admin.json" <<EOF
$admin_pw
EOF
printf 'Authorization: Basic %s\n' "$(printf '%s:%s' "e2e-admin@$domain" "$admin_pw" | base64 -w0)" > "$work/w/admin.hdr"
unset admin_pw
api POST /api/v1/users admin.json
[ "$code" = 201 ] || fail "first BTCPay admin ($code)"
printf '%s' '{"label":"napay-e2e-provisioner","permissions":["btcpay.server.cancreateuser","btcpay.server.canviewusers"]}' > "$work/w/prov.json"
api POST /api/v1/api-keys prov.json admin.hdr
[ "$code" = 200 ] || fail "provisioner key ($code)"
python3 -I -c "import json,sys; open(sys.argv[2],'w').write(json.load(open(sys.argv[1]))['apiKey'])" "$work/w/out.json" "$work/provisioner"
rm -f "$work/w/out.json"
pass 'Disposable BTCPay regtest with provisioner key limited to user creation/lookup'

# --- 2. gateway TLS btcpay.local (CA locale) e proxy di uscita Napay, come nella topologia approvata
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
pass 'Gateway btcpay.local with local CA, Napay egress proxy on client network only'

# --- 3. Napay: DB usa-e-getta con ruoli reali, worker CLI dedicato
openssl rand -hex 32 > "$work/migrator-password"
openssl rand -hex 32 > "$work/worker-password"
printf '{"current":"k1","keys":{"k1":"%s"}}\n' "$(openssl rand -base64 32)" > "$work/ring"
openssl rand -hex 32 > "$work/webhook-key"
cat >> "$work/test.env" <<ENV
BTCPAY_PROVISION_INSTANCE=$(printf %s https://btcpay.local | sha256sum | cut -c1-64)
BTCPAY_STORE_WEBHOOK_KEY=$(cat "$work/webhook-key")
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
      BTCPAY_PROVISION_ORIGIN: https://btcpay.local
      BTCPAY_PROVISION_CA_FILE: /run/btcpay-ca.crt
      BTCPAY_PROVISIONER_KEY_FILE: /run/secrets/btcpay_provisioner_key
      BTCPAY_KEK_KEYRING_FILE: /run/secrets/btcpay_kek_keyring
      BTCPAY_STORE_WEBHOOK_URL: https://napay.example.invalid/api/btcpay/v1/store-webhook
      BTCPAY_STORE_WEBHOOK_KEY_FILE: /run/secrets/btcpay_store_webhook_key
    secrets: [napay_db_worker_password, btcpay_provisioner_key, btcpay_kek_keyring, btcpay_store_webhook_key]
    volumes: [$root:/app:ro, $work/ca.crt:/run/btcpay-ca.crt:ro]
    networks: [database, e2e]
networks:
  e2e:
    external: true
    name: $p-napay
secrets:
  btcpay_provisioner_key:
    file: $work/provisioner
  btcpay_kek_keyring:
    file: $work/ring
  btcpay_store_webhook_key:
    file: $work/webhook-key
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" -f "$work/override.yml" -f "$work/cli.yml" "$@"; }
docker volume create "$project-data" >/dev/null
compose up -d --no-build --wait --wait-timeout 240 web
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/fixture.php
instance=$(printf %s https://btcpay.local | sha256sum | cut -c1-64)
fixture() { compose run --rm --no-deps -e "BTCPAY_PROVISION_INSTANCE=$instance" --entrypoint php bootstrap /app/tests/application/btcpay-e2e-fixture.php "$@"; }
fixture --seed > "$work/w/seed.json"
pass 'Two qualified merchants, one store and one terminal each, jobs enqueued as the web does'

# --- 4. worker: comandi one-shot finché non resta lavoro (attese brevi per Retry-After/backoff)
private_output() {
    for secret in root-password migrator-password worker-password provisioner webhook-key; do
        if grep -Fq -f "$work/$secret" "$work/cli.out"; then echo 'FAIL: worker leaked a secret.' >&2; exit 1; fi
    done
    if grep -Eq 'Stack trace|SQLSTATE|PDOException|RuntimeException|/app/|/run/secrets/' "$work/cli.out"; then echo 'FAIL: worker exposed diagnostics.' >&2; exit 1; fi
}
state() { fixture --state > "$work/w/state.json"; }
# drain COMANDO TIPI: ripete il comando one-shot finché i lavori di quei tipi non sono tutti in uno stato finale
drain() {
    deadline=$(( $(date +%s) + 300 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        compose run --rm --no-deps worker-cli "btcpay-provision/$1" --once > "$work/cli.out" 2>&1 || { private_output; cat "$work/cli.out" >&2; fail "worker $1 failed"; }
        private_output
        grep -qx idle "$work/cli.out" || continue
        state
        [ "$(json "sum(1 for j in d['jobs'] if j['kind'] in '$2'.split(',') and j['state'] not in ('done','failed','deferred','reconcile_required'))" "$work/w/state.json")" = 0 ] && return 0
        sleep 3
    done
    fail "worker $1 did not finish: $(json "[(j['kind'], j['state'], j['last_error_code']) for j in d['jobs']]" "$work/w/state.json")"
}
drain run merchant_account
state
[ "$(json "sorted((a['state'], a['technical_email'].endswith('@$domain') and a['technical_email'].startswith('m-'), bool(a['remote_user_id'])) for a in d['accounts'])" "$work/w/state.json")" \
    = "[('synced', True, True), ('synced', True, True)]" ] || fail "accounts: $(json "[(a['state']) for a in d['accounts']]" "$work/w/state.json")"
[ "$(json "len({a['remote_user_id'] for a in d['accounts']})" "$work/w/state.json")" = 2 ] || fail 'merchants share a BTCPay user'
pass 'Each merchant gets its own BTCPay user (technical email) and a verified key'
drain links store_link,pos_link,store_update
state
[ "$(json "sorted((s['state'], bool(s['remote_store_id'])) for s in d['stores'])" "$work/w/state.json")" = "[('synced', True), ('synced', True)]" ] \
    || fail "store links: $(json "[(s['state']) for s in d['stores']]" "$work/w/state.json")"
[ "$(json "sorted((x['state'], bool(x['remote_app_id'])) for x in d['pos'])" "$work/w/state.json")" = "[('synced', True), ('synced', True)]" ] \
    || fail "pos links: $(json "[(x['state']) for x in d['pos']]" "$work/w/state.json")"
[ "$(json "sum(1 for j in d['jobs'] if j['state'] != 'done')" "$work/w/state.json")" = 0 ] || fail "jobs not all done: $(json "[(j['kind'], j['state'], j['last_error_code']) for j in d['jobs'] if j['state'] != 'done']" "$work/w/state.json")"
[ "$(json "d['status']" "$work/w/state.json")" = "['linked', 'linked', 'linked', 'linked', 'linked', 'linked']" ] || fail "backoffice status: $(json "d['status']" "$work/w/state.json")"
pass 'Store and POS app linked for both merchants, every job done, backoffice shows linked'

# --- 5. verifica sul BTCPay reale (admin): utenti, proprietà degli store, app POS, isolamento
cp "$work/w/state.json" "$work/w/final.json"
# chiavi di sola verifica create dall'admin per ciascun utente merchant (l'admin non è membro degli store dei merchant):
# mai le chiavi di Napay, che restano cifrate nel DB
printf '%s' '{"label":"e2e-verify","permissions":["btcpay.store.canmodifystoresettings"]}' > "$work/w/verify.json"
for m in 0 1; do
    user=$(json "d['accounts'][$m]['remote_user_id']" "$work/w/final.json")
    api POST "/api/v1/users/$user/api-keys" verify.json admin.hdr
    [ "$code" = 200 ] || fail "verification key for merchant $m ($code)"
    python3 -I -c "import json,sys; open(sys.argv[2],'w').write('Authorization: token '+json.load(open(sys.argv[1]))['apiKey']+'\\n')" "$work/w/out.json" "$work/w/verify-$m.hdr"
    rm -f "$work/w/out.json"
done
for m in 0 1; do
    email=$(json "d['accounts'][$m]['technical_email']" "$work/w/final.json")
    user=$(json "d['accounts'][$m]['remote_user_id']" "$work/w/final.json")
    store=$(json "[s['remote_store_id'] for s in d['stores'] if s['merchant_id']==d['accounts'][$m]['merchant_id']][0]" "$work/w/final.json")
    app=$(json "[x['remote_app_id'] for x in d['pos'] if x['merchant_id']==d['accounts'][$m]['merchant_id']][0]" "$work/w/final.json")
    other=$(json "d['accounts'][1-$m]['remote_user_id']" "$work/w/final.json")
    api GET "/api/v1/users/$email" "" admin.hdr
    [ "$code" = 200 ] && [ "$(json "d['id']" "$work/w/out.json")" = "$user" ] && [ "$(json "'ServerAdmin' in d.get('roles', [])" "$work/w/out.json")" = False ] \
        || fail "BTCPay user of merchant $m ($code)"
    api GET "/api/v1/stores/$store/users" "" admin.hdr
    [ "$code" = 200 ] || fail "store users of merchant $m ($code)"
    [ "$(json "sorted(u.get('userId') or u.get('id') for u in d)" "$work/w/out.json")" = "['$user']" ] || fail "store $m members are not exactly its merchant"
    [ "$(json "'$other' in [u.get('userId') or u.get('id') for u in d]" "$work/w/out.json")" = False ] || fail 'store shared across merchants'
    api GET "/api/v1/stores/$store/apps" "" "verify-$m.hdr"
    [ "$code" = 200 ] && [ "$(json "[(a['id'], a.get('appType')) for a in d]" "$work/w/out.json")" = "[('$app', 'PointOfSale')]" ] \
        || fail "POS app of merchant $m ($code)"
    api GET "/api/v1/stores/$store" "" "verify-$((1 - m)).hdr"
    [ "$code" = 403 ] || fail "merchant $((1 - m)) can read the store of merchant $m ($code)"
done
pass 'BTCPay: merchant users not admin, each store owned only by its merchant, exactly one POS app'
pass 'Isolation: a merchant key gets 403 on the other merchant store'

# --- 5b. impostazioni dello store (SIP-022 passo 3): applicate dal worker con la chiave del merchant, poi una modifica
storeA=$(json "d['A']['store']" "$work/w/seed.json")
iA=$(json "[a['merchant_id'] for a in d['accounts']].index($(json "d['A']['merchant']" "$work/w/seed.json"))" "$work/w/final.json")
remoteA=$(json "[s['remote_store_id'] for s in d['stores'] if s['store_id']==$storeA][0]" "$work/w/final.json")
api GET "/api/v1/stores/$remoteA" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "(d['name'], d['defaultCurrency'], d['speedPolicy'], d['invoiceExpiration'], d['networkFeeMode'])" "$work/w/out.json")" = "('E2E negozio A', 'EUR', 'MediumSpeed', 900, 'Always')" ] \
    || fail "store settings not applied ($code)"
pass 'Fixed store settings and the plain shop name (shown at checkout) applied to BTCPay by the worker'
api GET "/api/v1/stores/$remoteA/rates/configuration/primary" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "(d['isCustomScript'], d['preferredSource'])" "$work/w/out.json")" = "(False, 'kraken')" ] \
    || fail "store rates not applied ($code)"
pass 'SIP-033: store rates (fixed source) applied by the worker, no manual setting on BTCPay'
fixture --edit-store "$storeA"
drain links store_update
api GET "/api/v1/stores/$remoteA" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "(d['name'], d['defaultCurrency'])" "$work/w/out.json")" = "('E2E negozio rinominato', 'USD')" ] || fail "store edit not applied ($code)"
state; cp "$work/w/state.json" "$work/w/final.json"
pass 'Name and currency changed in Napay reach BTCPay through a store_update job (no web call)'
appA=$(json "[x['remote_app_id'] for x in d['pos'] if x['store_id']==$storeA][0]" "$work/w/final.json")
api GET "/api/v1/apps/pos/$appA" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "(d['currency'], d['showCustomAmount'], d['defaultView'])" "$work/w/out.json")" = "('USD', True, 'Light')" ] || fail "POS app not configured ($code)"
pass 'SIP-033: the POS app follows the shop currency and accepts custom amounts on the keypad view'
# SIP-033: fattura dall'app POS senza chiave (POST anonimo), stato pubblico con indirizzo della xpub del merchant.
posVpub=vpub5Zf9o1xg1TVJN3pte4sDD73BMq71dLFTZPgqvBxe872nMEiDtoBo99udYHBuxP3wDbhie79PmTtL8GxYR5ana9FgxdKTp13TvV3kU6UaHrP
fixture --set-wallet "$storeA" "$posVpub"
drain links store_wallet
posAddresses=$(fixture --preview "$posVpub" regtest | tail -1 | python3 -I -c "import json,sys; print(json.load(sys.stdin))")
# Regtest senza Internet: cambi fissi impostati per la sola prova con la chiave di verifica del merchant.
printf '%s' '{"isCustomScript":true,"effectiveScript":"BTC_EUR = 50000;\nBTC_USD = 60000;"}' > "$work/w/rates.json"
api PUT "/api/v1/stores/$remoteA/rates/configuration" rates.json "verify-$iA.hdr"
[ "$code" = 200 ] || fail "test rates ($code)"
printf 'url = "http://btcpay-regtest:49392/apps/%s/pos"\n' "$appA" > "$work/w/request.cfg"
printf 'amount=12.34' > "$work/w/pos-form.txt"
code=$(docker run --rm --user "$(id -u):$(id -g)" --network "$BTCPAY_EDGE_NETWORK" -v "$work/w:/w" --entrypoint curl "$client" \
    -s -o /dev/null -D /w/pos-head.txt -w '%{http_code}' -X POST --max-time 30 -K /w/request.cfg -H 'Content-Type: application/x-www-form-urlencoded' --data-binary @/w/pos-form.txt) || code=000
invoice=$(sed -n 's#^[Ll]ocation: */i/\([A-Za-z0-9]*\).*#\1#p' "$work/w/pos-head.txt" | tr -d '\r')
[ "$code" = 302 ] && [ -n "$invoice" ] || fail "anonymous POS invoice ($code)"
api GET "/i/$invoice/BTC-CHAIN/status"
[ "$code" = 200 ] && [ "$(json "(d['invoiceId'], d['status'], d['invoiceCurrency'], '12' in d['orderAmountFiat'] and '34' in d['orderAmountFiat'], d['address'] in $posAddresses, d['invoiceBitcoinUrl'].startswith('bitcoin:' + d['address'] + '?amount='))" "$work/w/out.json")" = "('$invoice', 'New', 'USD', True, True, True)" ] \
    || fail "public invoice status ($code)"
pass 'SIP-033: POS app invoice created without any key; public status pays to an address of the merchant xpub (BIP21 for the QR)'
# SIP-033 passo 3: il gateway di Napay (proxy + TLS con CA locale) crea la fattura e ne legge lo stato pubblico
compose run --rm --no-deps -e "BTCPAY_PROVISION_INSTANCE=$instance" --entrypoint php worker-cli /app/tests/application/btcpay-pos-gateway.php "$appA" 1234 > "$work/w/gw.json" 2>"$work/gw.err" \
    || { head -c 400 "$work/w/gw.json" >&2; head -c 400 "$work/gw.err" >&2; fail 'POS gateway run'; }
[ "$(json "(d['instance_ok'], d['created']['outcome'], d['status']['invoice_id'] == d['created']['invoice_id'], d['status']['order_id'] == d['order'], d['status']['store_id'], d['status']['currency'], d['status']['fiat_digits'], d['status']['status'], d['status']['address'] in $posAddresses, d['status']['due_satoshis'] > 0, d['missing'])" "$work/w/gw.json")" \
    = "(True, 'created', True, True, '$remoteA', 'USD', '1234', 'new', True, True, {'outcome': 'rejected', 'code': 'app_missing'})" ] \
    || fail "POS gateway: $(cat "$work/w/gw.json")"
pass 'SIP-033: Napay gateway via proxy and TLS creates the invoice with its orderId and reads the public status (address of the merchant xpub); unknown app refused'

# --- 5c. webhook dello store (SIP-022 passo 4): registrato dal worker, consegne verificate da Napay per negozio
api GET "/api/v1/stores/$remoteA/webhooks" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "[(h['url'], h['enabled'], sorted(h['authorizedEvents']['specificEvents'])) for h in d]" "$work/w/out.json")" \
    = "[('https://napay.example.invalid/api/btcpay/v1/store-webhook', True, ['InvoiceExpired', 'InvoiceInvalid', 'InvoiceProcessing', 'InvoiceReceivedPayment', 'InvoiceSettled'])]" ] \
    || fail "store webhook not registered ($code)"
pass 'Store webhook registered on BTCPay by the worker: Napay URL, invoice events only, exactly one'
[ "$(fixture --deliver "$storeA" "$remoteA" good E2eDelivery01 | tail -1)" = '200 recorded' ] || fail 'signed delivery not recorded'
[ "$(fixture --deliver "$storeA" "$remoteA" good E2eDelivery01 | tail -1)" = '200 duplicate' ] || fail 'duplicate delivery recorded twice'
[ "$(fixture --deliver "$storeA" "$remoteA" badsig E2eDelivery02 | tail -1)" = '401 invalid_signature' ] || fail 'bad signature accepted'
[ "$(fixture --deliver "$storeA" "$remoteA" otherstore E2eDelivery03 | tail -1)" = '401 invalid_signature' ] || fail 'secret of another store accepted'
state
[ "$(json "[(e['store_id'], e['event_type'], e['result']) for e in d['events']]" "$work/w/state.json")" = "[($storeA, 'InvoiceSettled', 'recorded')]" ] || fail 'webhook events'
cp "$work/w/state.json" "$work/w/final.json"
pass 'Store webhook endpoint: signed delivery recorded once for its shop; bad signature or another store secret refused'

# --- 6. ripetizione: nessun lavoro, nessuna nuova risorsa
for route in run links reconcile; do
    compose run --rm --no-deps worker-cli "btcpay-provision/$route" --once > "$work/cli.out" 2>&1
    grep -qx idle "$work/cli.out"; private_output
done
state
[ "$(python3 -I -c 'import json,sys; a=json.load(open(sys.argv[1])); b=json.load(open(sys.argv[2])); print(a==b)' "$work/w/final.json" "$work/w/state.json")" = True ] || fail 'state changed on rerun'
[ "$(json "len(d['pos']) == 2 and len({x['remote_app_id'] for x in d['pos']}) == 2 and len({s['remote_store_id'] for s in d['stores']}) == 2" "$work/w/state.json")" = True ] \
    || fail 'duplicate or shared remote resources after rerun'
pass 'Rerun of run/links/reconcile is idle: no new job, mapping or remote resource'

# --- 7. riprova dell'admin (SIP-022 B5): adotta per marker, ricrea solo dopo una lista completa a vuoto, attende la quiete
storeA=$(json "d['A']['store']" "$work/w/seed.json"); termB=$(json "d['B']['terminal']" "$work/w/seed.json")
storeB=$(json "d['B']['store']" "$work/w/seed.json")
iA=$(json "[a['merchant_id'] for a in d['accounts']].index($(json "d['A']['merchant']" "$work/w/seed.json"))" "$work/w/final.json")
iB=$((1 - iA))
# negozio C appena creato: lo store remoto ha ancora il nome con il marker (caso reale di creazione incerta)
storeC=$(fixture --new-store | tail -1)
drain links store_link,pos_link,store_update
state
remoteC=$(json "[s['remote_store_id'] for s in d['stores'] if s['store_id']==$storeC and s['state']=='synced'][0]" "$work/w/state.json")
remoteB=$(json "[s['remote_store_id'] for s in d['stores'] if s['store_id']==$storeB][0]" "$work/w/final.json")
appB=$(json "[x['remote_app_id'] for x in d['pos'] if x['terminal_id']==$termB][0]" "$work/w/final.json")
r=$(fixture --retry store_link "$storeC" 2>&1 | tail -1)
[ "$r" = refused ] || fail "retry accepted on a linked store: $r"
pass 'Retry refused while the link is fine'
fixture --break store_link "$storeC"
state
[ "$(json "'error' in d['status']" "$work/w/state.json")" = True ] || fail 'broken link not shown as error'
[ "$(fixture --retry store_link "$storeC")" = queued ] && [ "$(fixture --retry store_link "$storeC")" = refused ] || fail 'store retry request'
drain links store_link,pos_link,store_update
state
[ "$(json "[(s['state'], s['remote_store_id']) for s in d['stores'] if s['store_id']==$storeC]" "$work/w/state.json")" = "[('synced', '$remoteC')]" ] || fail 'store not adopted'
api GET /api/v1/stores "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "len(d)" "$work/w/out.json")" = 2 ] || fail "duplicate store after retry ($code)"
pass 'Admin retry adopts the existing BTCPay store by its marker: same ID, no duplicate'

api DELETE "/api/v1/apps/$appB" "" "verify-$iB.hdr"
[ "$code" = 200 ] || fail "remove POS app for the test ($code)"
fixture --break pos_link "$termB"
[ "$(fixture --retry pos_link "$termB")" = queued ] || fail 'pos retry request'
drain links store_link,pos_link,store_update
state
newApp=$(json "[x['remote_app_id'] for x in d['pos'] if x['terminal_id']==$termB and x['state']=='synced'][0]" "$work/w/state.json")
[ -n "$newApp" ] && [ "$newApp" != "$appB" ] || fail 'POS app not recreated'
api GET "/api/v1/stores/$remoteB/apps" "" "verify-$iB.hdr"
[ "$code" = 200 ] && [ "$(json "[a['id'] for a in d]" "$work/w/out.json")" = "['$newApp']" ] || fail "POS apps after recreate ($code)"
[ "$(json "sorted(j['last_error_code'] for j in d['jobs'] if j['kind']=='pos_link' and j['entity_id']==$termB and j['state']=='failed')" "$work/w/state.json")" = "['store_rejected']" ] \
    || fail 'previous job not kept as failed'
pass 'Admin retry recreates a POS app missing from a complete lookup, exactly one app'

api DELETE "/api/v1/apps/$newApp" "" "verify-$iB.hdr"
[ "$code" = 200 ] || fail "remove POS app again ($code)"
fixture --stuck pos_link "$termB"
[ "$(fixture --retry pos_link "$termB")" = queued ] || fail 'retry of an uncertain link'
compose run --rm --no-deps worker-cli btcpay-provision/links --once > "$work/cli.out" 2>&1
grep -qx job-processed "$work/cli.out"; private_output
state
[ "$(json "[(j['state'], j['last_error_code']) for j in d['jobs'] if j['kind']=='pos_link' and j['entity_id']==$termB][-1]" "$work/w/state.json")" = "('retry_wait', 'admin_retry_wait')" ] \
    && [ "$(json "[x['state'] for x in d['pos'] if x['terminal_id']==$termB]" "$work/w/state.json")" = "['reconcile_required']" ] || fail 'retry did not wait for the quiet period'
api GET "/api/v1/stores/$remoteB/apps" "" "verify-$iB.hdr"
[ "$code" = 200 ] && [ "$(json "len(d)" "$work/w/out.json")" = 0 ] || fail "POS app created during the quiet period ($code)"
pass 'After a recent uncertain POST the retry only waits (no creation before the quiet period)'

# --- 8. riprova di un account merchant (R2): utente ritrovato per email e adottato solo con la prova della password
merchantA=$(json "d['A']['merchant']" "$work/w/seed.json")
userA=$(json "d['accounts'][$iA]['remote_user_id']" "$work/w/final.json")
emailA=$(json "d['accounts'][$iA]['technical_email']" "$work/w/final.json")
fixture --lose-user "$merchantA"
state
[ "$(json "[a['state'] for a in d['accounts'] if a['merchant_id']==$merchantA]" "$work/w/state.json")" = "['reconcile_required']" ] || fail 'account not in reconciliation'
[ "$(fixture --retry merchant_account "$merchantA")" = queued ] || fail 'account retry request'
drain run merchant_account
state
[ "$(json "[(a['state'], a['remote_user_id']) for a in d['accounts'] if a['merchant_id']==$merchantA]" "$work/w/state.json")" = "[('synced', '$userA')]" ] || fail 'account not adopted'
[ "$(json "max(c['generation'] for c in d['credentials'] if c['merchant_id']==$merchantA and c['state']=='current')" "$work/w/state.json")" = 2 ] || fail 'no new current key'
api GET "/api/v1/users/$emailA" "" admin.hdr
[ "$code" = 200 ] && [ "$(json "d['id']" "$work/w/out.json")" = "$userA" ] || fail "BTCPay user changed ($code)"
pass 'Admin retry of a merchant account: same BTCPay user found by email, ownership proven by a new key (generation 2)'

# --- 9. negozio eliminato (SIP-022 passo 3b): app POS rimosse, store rinominato come archiviato, mai cancellato
storeA=$(json "d['A']['store']" "$work/w/seed.json")
remoteA=$(json "[s['remote_store_id'] for s in d['stores'] if s['store_id']==$storeA][0]" "$work/w/state.json")
iA=$(json "[a['merchant_id'] for a in d['accounts']].index($(json "d['A']['merchant']" "$work/w/seed.json"))" "$work/w/state.json")
api GET "/api/v1/stores/$remoteA/apps" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "len(d)" "$work/w/out.json")" = 1 ] || fail "store A should have one POS app before the archive ($code)"
fixture --archive-store "$storeA"
drain links store_archive
api GET "/api/v1/stores/$remoteA/apps" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "len(d)" "$work/w/out.json")" = 0 ] || fail "POS app still on the archived store ($code)"
api GET "/api/v1/stores/$remoteA" "" "verify-$iA.hdr"
[ "$code" = 200 ] && [ "$(json "d['name'].endswith(' - archiviato') and len(d['name']) <= 50" "$work/w/out.json")" = True ] || fail "store not renamed as archived ($code)"
state
[ "$(json "[(s['state']) for s in d['stores'] if s['store_id']==$storeA] + [x['state'] for x in d['pos'] if x['store_id']==$storeA]" "$work/w/state.json")" = "['suspended', 'suspended']" ] || fail 'mappings not closed'
pass 'Deleted shop: POS app removed from BTCPay, store kept and renamed as archived, mappings closed'

# --- 10. wallet BTC del negozio (xpub del merchant, decisione A): indirizzi di Napay = indirizzi di BTCPay
vpub=vpub5Zf9o1xg1TVJN3pte4sDD73BMq71dLFTZPgqvBxe872nMEiDtoBo99udYHBuxP3wDbhie79PmTtL8GxYR5ana9FgxdKTp13TvV3kU6UaHrP
storeB=$(json "d['B']['store']" "$work/w/seed.json")
remoteB=$(json "[s['remote_store_id'] for s in d['stores'] if s['store_id']==$storeB][0]" "$work/w/state.json")
iB=$(json "[a['merchant_id'] for a in d['accounts']].index($(json "d['B']['merchant']" "$work/w/seed.json"))" "$work/w/state.json")
userB=$(json "d['accounts'][$iB]['remote_user_id']" "$work/w/state.json")
printf '%s' '{"label":"e2e-wallet-view","permissions":["btcpay.store.canviewwallet","btcpay.store.canviewstoresettings"]}' > "$work/w/wkey.json"
api POST "/api/v1/users/$userB/api-keys" wkey.json admin.hdr
[ "$code" = 200 ] || fail "wallet view key ($code)"
python3 -I -c "import json,sys; open(sys.argv[2],'w').write('Authorization: token '+json.load(open(sys.argv[1]))['apiKey']+'\\n')" "$work/w/out.json" "$work/w/wallet-$iB.hdr"
rm -f "$work/w/out.json"
fixture --set-wallet "$storeB" "$vpub"
drain links store_wallet
api GET "/api/v1/stores/$remoteB/payment-methods/BTC-CHAIN" "" "wallet-$iB.hdr"
[ "$code" = 200 ] && [ "$(json "d.get('enabled')" "$work/w/out.json")" = True ] || fail "on-chain payment method not enabled ($code)"
api GET "/api/v1/stores/$remoteB/payment-methods/BTC-CHAIN/wallet/preview?count=5" "" "wallet-$iB.hdr"
[ "$code" = 200 ] || fail "BTCPay address preview ($code)"
remote=$(json "[a['address'] for a in d['addresses']][:5]" "$work/w/out.json")
napay=$(fixture --preview "$vpub" regtest | tail -1 | python3 -I -c "import json,sys; print(json.load(sys.stdin))")
[ "$remote" = "$napay" ] || fail "addresses differ: BTCPay $remote, Napay $napay"
[ "$(json "'bcrt1qnynpu9auq7msewe4t5lwkv042x6vvp6wxcdmtx' in [a['address'] for a in d['addresses']]" "$work/w/out.json")" = True ] || fail 'expected address missing'
pass 'Merchant vpub applied to BTCPay by the worker: the 5 addresses shown by Napay are exactly the ones BTCPay generates'
fixture --set-wallet "$storeB" ""
drain links store_wallet
api GET "/api/v1/stores/$remoteB/payment-methods/BTC-CHAIN" "" "wallet-$iB.hdr"
[ "$code" = 404 ] || fail "on-chain payment method still present after removal ($code)"
pass 'Wallet removed: BTC payment method gone from the BTCPay store'
printf 'PASS %s BTCPay provisioning e2e checks (regtest, no real money)\n' "$checks"
E2E
sh "$private/runtime.sh"

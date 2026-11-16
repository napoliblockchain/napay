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
      BTCPAY_PROVISION_ORIGIN: https://btcpay.local
      BTCPAY_PROVISION_CA_FILE: /run/btcpay-ca.crt
      BTCPAY_PROVISIONER_KEY_FILE: /run/secrets/btcpay_provisioner_key
      BTCPAY_KEK_KEYRING_FILE: /run/secrets/btcpay_kek_keyring
    secrets: [napay_db_worker_password, btcpay_provisioner_key, btcpay_kek_keyring]
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
YAML
compose() { docker compose -p "$project" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" -f "$work/override.yml" -f "$work/cli.yml" "$@"; }
docker volume create "$project-data" >/dev/null
compose up -d --no-build --wait --wait-timeout 240 web
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/fixture.php
instance=$(printf %s https://btcpay.local | sha256sum | cut -c1-64)
fixture() { compose run --rm --no-deps -e "BTCPAY_PROVISION_INSTANCE=$instance" --entrypoint php bootstrap /app/tests/application/btcpay-e2e-fixture.php "$1"; }
fixture --seed > "$work/w/seed.json"
pass 'Two qualified merchants, one store and one terminal each, jobs enqueued as the web does'

# --- 4. worker: comandi one-shot finché non resta lavoro (attese brevi per Retry-After/backoff)
private_output() {
    for secret in root-password migrator-password worker-password provisioner; do
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
drain links store_link,pos_link
state
[ "$(json "sorted((s['state'], bool(s['remote_store_id'])) for s in d['stores'])" "$work/w/state.json")" = "[('synced', True), ('synced', True)]" ] \
    || fail "store links: $(json "[(s['state']) for s in d['stores']]" "$work/w/state.json")"
[ "$(json "sorted((x['state'], bool(x['remote_app_id'])) for x in d['pos'])" "$work/w/state.json")" = "[('synced', True), ('synced', True)]" ] \
    || fail "pos links: $(json "[(x['state']) for x in d['pos']]" "$work/w/state.json")"
[ "$(json "sum(1 for j in d['jobs'] if j['state'] != 'done')" "$work/w/state.json")" = 0 ] || fail 'jobs not all done'
pass 'Store and POS app linked for both merchants, every job done'

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
printf 'PASS %s BTCPay provisioning e2e checks (regtest, no real money)\n' "$checks"
E2E
sh "$private/runtime.sh"

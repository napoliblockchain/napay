#!/usr/bin/env bash
# SIP-036 A3: docker/deploy.sh --check e overlay di produzione. Compose e PHP reali per la validazione; ogni operazione
# Docker persistente (build, up, volume, network, exec) è intercettata e fa fallire il test.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
work=$(mktemp -d)
trap 'code=$?; if (( code != 0 )); then cat "$work/output" >&2 2>/dev/null || true; fi; rm -rf "$work"' EXIT
trap 'exit 1' HUP INT TERM
real_docker=$(command -v docker)
mkdir "$work/bin" "$work/import" "$work/backup"
chmod 700 "$work/backup"
cat > "$work/bin/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
    compose)
        for arg in "$@"; do
            if [[ $arg == config ]]; then exec "$DEPLOY_REAL_DOCKER" "$@"; fi
        done ;;
    run) exec "$DEPLOY_REAL_DOCKER" "$@" ;;
esac
touch "$DEPLOY_PERSISTENT_CALL"
echo 'FAIL: preflight attempted a persistent Docker operation.' >&2
exit 1
SH
chmod 700 "$work/bin/docker"
for name in root migrator worker; do openssl rand -hex 32 > "$work/$name"; done
printf '{}\n' > "$work/import/manifest.json"
: > "$work/import/napay-v2.sql"
cat > "$work/base.env" <<ENV
APP_ENV=prod
APP_DEBUG=false
DB_DATABASE=napay_deploy_test
DB_DSN=mysql:host=mysql;port=3306;dbname=napay_deploy_test
DB_USERNAME=napay_web
DB_PASSWORD=$(openssl rand -hex 32)
NAPAY_MYSQL_VOLUME=napay_deploy_preflight_never_created
NAPAY_MYSQL_ROOT_SECRET_FILE=$work/root
NAPAY_IMPORT_DIR=$work/import
NAPAY_BOOTSTRAP_UID=$(id -u)
NAPAY_BOOTSTRAP_GID=$(id -g)
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$work/migrator
NAPAY_DB_WORKER_SECRET_FILE=$work/worker
NAPAY_GATEWAY_NETWORK=napay_gateway_test
NAPAY_TRUSTED_PROXIES=10.250.0.0/24
NAPAY_BACKUP_DIR=$work/backup
ENV
cp "$work/base.env" "$work/test.env"
chmod 600 "$work/test.env" "$work/base.env"
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
run_deploy() {
    env -i PATH="$work/bin:$PATH" HOME="$HOME" DEPLOY_REAL_DOCKER="$real_docker" DEPLOY_PERSISTENT_CALL="$work/persistent" \
        bash "$root/docker/deploy.sh" "${1:-$work/test.env}" --check > "$work/output" 2>&1
}
reject() {
    if run_deploy "${2:-$work/test.env}"; then echo "FAIL: $1" >&2; exit 1; fi
    [[ ! -e $work/persistent ]] || exit 1
    pass "$1"
}
change() { sed "s|^$1=.*|$1=$2|" "$work/base.env" > "$work/test.env"; }

run_deploy
grep -q 'Napay deploy preflight OK (commit [0-9a-f]\{12\})' "$work/output" && [[ ! -e $work/persistent ]]
pass 'Valid production configuration: preflight only, no build, start or backup'

# modello compose effettivo di produzione (stessi file di deploy.sh)
tag=$(git -C "$root" rev-parse --short=12 HEAD)
NAPAY_ENV_FILE="$work/test.env" NAPAY_IMAGE_TAG=$tag "$real_docker" compose --project-directory "$root" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" \
    -f "$root/docker-compose.prod.yml" config --format json > "$work/model.json"
check_model() { php_expr=$1; docker run --rm -i --network none --read-only --entrypoint php php:8.3.36-cli -r '
    $m = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $s = $m["services"];
    exit(('"$php_expr"') ? 0 : 1);' < "$work/model.json"; }
check_model '!isset($s["web"]["ports"]) || $s["web"]["ports"] === []' && pass 'Web publishes no host port'
check_model 'array_keys($s["web"]["networks"]) == ["database","egress","gateway"] && $s["web"]["networks"]["gateway"]["aliases"] === ["napay-web"]' \
    && pass 'Web only on database, egress and the gateway network (alias napay-web)'
check_model '$m["networks"]["gateway"]["external"] === true && $m["networks"]["gateway"]["name"] === "napay_gateway_test" && $m["networks"]["egress"]["internal"] === true' \
    && pass 'Gateway network external by configured name; egress network internal'
check_model 'array_keys($s["cron"]["networks"]) == ["database","egress"] && array_keys($s["egress"]["networks"]) == ["egress","internet"]' \
    && pass 'Only the egress proxy reaches the internet; cron only database and proxy'
check_model 'count(array_filter($s["web"]["volumes"], fn($v) => $v["target"] === "/var/www/html" && ($v["read_only"] ?? false) === true)) === 1
    && count(array_filter($s["cron"]["volumes"], fn($v) => $v["target"] === "/var/www/html" && ($v["read_only"] ?? false) === true)) === 1' \
    && pass 'Application code mounted read-only in web and cron'
check_model '$s["web"]["image"] === "napay/napay:'"$tag"'" && $s["cron"]["image"] === "napay/napay:'"$tag"'" && $s["web"]["environment"]["PAYMENT_PROXY"] === "http://egress:3128"
    && $s["web"]["environment"]["TRUSTED_PROXIES"] === "10.250.0.0/24"' \
    && pass 'Images tagged with the release commit; payment proxy and trusted gateway set'

# con i segreti del worker BTCPay: overlay del worker incluso, uscita solo dal proxy, cron con i webhook del ponte
for name in provisioner keyring webhook-key; do openssl rand -hex 32 > "$work/$name"; done
cat >> "$work/test.env" <<WENV
BTCPAY_PROVISION_INSTANCE=$(printf %s https://btcpay.example.invalid | sha256sum | cut -c1-64)
BTCPAY_PROVISION_ORIGIN=https://btcpay.example.invalid
BTCPAY_STORE_WEBHOOK_KEY=$(openssl rand -hex 32)
BTCPAY_STORE_WEBHOOK_URL=https://napay.example.invalid/api/btcpay/v1/store-webhook
BTCPAY_PROVISIONER_KEY_SECRET_FILE=$work/provisioner
BTCPAY_KEK_KEYRING_SECRET_FILE=$work/keyring
BTCPAY_STORE_WEBHOOK_KEY_SECRET_FILE=$work/webhook-key
WENV
run_deploy
grep -q 'Napay deploy preflight OK' "$work/output" && [[ ! -e $work/persistent ]] || exit 1
NAPAY_ENV_FILE="$work/test.env" NAPAY_IMAGE_TAG=$tag "$real_docker" compose --project-directory "$root" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" \
    -f "$root/docker-compose.prod.yml" -f "$root/docker-compose.btcpay-worker.yml" config --format json > "$work/model.json"
check_model 'array_keys($s["btcpay-worker"]["networks"]) == ["database","egress"] && $s["btcpay-worker"]["environment"]["PAYMENT_PROXY"] === "http://egress:3128"
    && $s["cron"]["environment"]["BTCPAY_STORE_WEBHOOK_URL"] === "https://napay.example.invalid/api/btcpay/v1/store-webhook" && !isset($s["btcpay-worker"]["ports"])' \
    && pass 'With worker secrets: BTCPay worker included, only database and egress proxy; cron gets the bridge webhook settings'
cp "$work/base.env" "$work/test.env"
# posta SMTP: inoltro dedicato incluso solo con MAIL_TRANSPORT=smtp, host e porta validati
printf 'MAIL_TRANSPORT=smtp\nMAIL_SMTP_HOST=authsmtp.securemail.pro\nMAIL_SMTP_PORT=465\nMAIL_SMTP_SCHEME=smtps\nMAIL_SMTP_USERNAME=fixture@example.invalid\nMAIL_SMTP_PASSWORD=%s\n' "$(openssl rand -hex 16)" >> "$work/test.env"
run_deploy
grep -q 'Napay deploy preflight OK' "$work/output" && [[ ! -e $work/persistent ]] || exit 1
NAPAY_ENV_FILE="$work/test.env" NAPAY_IMAGE_TAG=$tag "$real_docker" compose --project-directory "$root" --env-file "$work/test.env" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" \
    -f "$root/docker-compose.prod.yml" -f "$root/docker-compose.mail.yml" config --format json > "$work/model.json"
check_model '$s["smtp-egress"]["networks"]["egress"]["aliases"] === ["authsmtp.securemail.pro"] && array_keys($s["smtp-egress"]["networks"]) == ["egress","internet"]
    && !isset($s["smtp-egress"]["ports"]) && $s["smtp-egress"]["environment"]["NAPAY_SMTP_UPSTREAM"] === "authsmtp.securemail.pro"
    && array_keys($s["web"]["networks"]) == ["database","egress","gateway"]' \
    && pass 'With SMTP: dedicated relay aliased as the mail server on the egress network; web still without internet'
for bad in 'MAIL_SMTP_HOST=bad_host' 'MAIL_SMTP_PORT=25' 'NAPAY_SMTP_RESOLVER=any'; do
    sed "/^${bad%%=*}=/d" "$work/test.env" > "$work/bad.env"; printf '%s\n' "$bad" >> "$work/bad.env"; chmod 600 "$work/bad.env"
    if run_deploy "$work/bad.env"; then echo "FAIL: accepted $bad" >&2; exit 1; fi
done
pass 'Invalid mail server, port (only 465/587) or resolver refused'
cp "$work/base.env" "$work/test.env"
change NAPAY_GATEWAY_NETWORK ''; reject 'Missing gateway network refused'
change NAPAY_GATEWAY_NETWORK 'bad name;x'; reject 'Invalid gateway network name refused'
change NAPAY_TRUSTED_PROXIES ''; reject 'Missing trusted proxy CIDR refused'
change NAPAY_TRUSTED_PROXIES '0.0.0.0'; reject 'Trusted proxy without prefix refused'
change NAPAY_TRUSTED_PROXIES '10.250.0.0/24,any'; reject 'Malformed trusted proxy list refused'
change NAPAY_BACKUP_DIR "$work/missing"; reject 'Missing backup directory refused'
change NAPAY_BACKUP_DIR "$root/runtime"; reject 'Backup directory inside the repository refused'
cp "$work/base.env" "$work/test.env"
chmod 755 "$work/backup"; reject 'Readable-by-others backup directory refused'; chmod 700 "$work/backup"
chmod 644 "$work/test.env"; reject 'Readable-by-others environment refused'; chmod 600 "$work/test.env"
reject 'Relative environment refused' relative.env
change NAPAY_DB_WORKER_SECRET_FILE "$work/root"; reject 'Role checks of up.sh still enforced'
cp "$work/base.env" "$work/test.env"
# overlay passati direttamente a up.sh: solo file docker-compose.<nome>.yml del repository
up_overlay() {
    env -i PATH="$work/bin:$PATH" HOME="$HOME" DEPLOY_REAL_DOCKER="$real_docker" DEPLOY_PERSISTENT_CALL="$work/persistent" \
        NAPAY_COMPOSE_OVERLAYS="$1" NAPAY_IMAGE_TAG=test bash "$root/docker/up.sh" "$work/test.env" --check > "$work/output" 2>&1
}
for bad in '../docker-compose.prod.yml' 'docker-compose.roles.yml' 'docker-compose.missing.yml' '/etc/docker-compose.x.yml' 'docker/local/compose.yml'; do
    if up_overlay "$bad"; then echo "FAIL: overlay accepted: $bad" >&2; exit 1; fi
    grep -q 'Invalid compose overlay' "$work/output" || exit 1
done
pass 'up.sh accepts only repository docker-compose.<name>.yml overlays (no path, roles file or missing file)'
[[ ! -e $work/persistent ]] || exit 1
printf 'PASS %s Napay deploy preflight checks\n' "$checks"

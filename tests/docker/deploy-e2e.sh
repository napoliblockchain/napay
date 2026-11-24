#!/usr/bin/env bash
# SIP-036 A3 collaudo: docker/deploy.sh reale su risorse usa-e-getta (progetto, rete gateway, volume DB, immagini del
# commit): prima installazione dal dataset v2 rivisto, web raggiungibile solo dalla rete del gateway, secondo rilascio
# con backup del database prima delle migration. Mai la sandbox condivisa; nessuna porta sull'host.
# Uso: NAPAY_TEST_DATASET=... bash tests/docker/deploy-e2e.sh
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
dataset=${NAPAY_TEST_DATASET:?Set reviewed private v2 dataset directory}
[[ -z $(git -C "$root" status --porcelain --untracked-files=no) ]] || { echo 'FAIL: commit first (deploy releases only a commit).' >&2; exit 1; }
tag=$(git -C "$root" rev-parse --short=12 HEAD)
work=$(mktemp -d)
run=$(openssl rand -hex 4)
export COMPOSE_PROJECT_NAME="napay-deploy-$run"
gateway="napay-deploy-gw-$run"
volume="napay-deploy-db-$run"
subnet="10.249.$(( $(od -An -N1 -tu1 /dev/urandom) % 200 + 20 )).0/24"
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
files=(-f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml" -f "$root/docker-compose.prod.yml")
compose() { NAPAY_ENV_FILE="$work/napay.env" NAPAY_IMAGE_TAG=$tag docker compose --project-directory "$root" --env-file "$work/napay.env" "${files[@]}" "$@"; }
cleanup() {
    code=$?
    trap - EXIT
    compose down -v --timeout 10 >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    docker network rm "$gateway" >/dev/null 2>&1 || true
    docker image rm "napay/napay:$tag" "napay/egress:$tag" >/dev/null 2>&1 || true
    docker rm -f "napay-restore-$run" "napay-wallet-db-$run" >/dev/null 2>&1 || true
    rm -rf "$work"
    exit "$code"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

mkdir -m 700 "$work/import" "$work/backup"
cp "$dataset/manifest.json" "$dataset/napay-v2.sql" "$work/import/"
for name in root migrator worker; do openssl rand -hex 32 > "$work/$name"; done
cat > "$work/napay.env" <<ENV
APP_ENV=prod
APP_DEBUG=false
DB_DATABASE=napay_deploy_e2e
DB_DSN=mysql:host=mysql;port=3306;dbname=napay_deploy_e2e
DB_USERNAME=napay_web
DB_PASSWORD=$(openssl rand -hex 32)
NAPAY_MYSQL_VOLUME=$volume
NAPAY_MYSQL_ROOT_SECRET_FILE=$work/root
NAPAY_IMPORT_DIR=$work/import
NAPAY_BOOTSTRAP_UID=$(id -u)
NAPAY_BOOTSTRAP_GID=$(id -g)
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$work/migrator
NAPAY_DB_WORKER_SECRET_FILE=$work/worker
NAPAY_DB_WAIT_SECONDS=60
COOKIE_VALIDATION_KEY=$(openssl rand -hex 32)
SECRET_HASH_KEY=$(openssl rand -hex 32)
ADMIN_EMAIL=admin@example.invalid
WEBAPP_LINK=https://napay.example.invalid
MAIL_TRANSPORT=file
NAPAY_GATEWAY_NETWORK=$gateway
NAPAY_TRUSTED_PROXIES=$subnet
NAPAY_BACKUP_DIR=$work/backup
ENV
chmod 600 "$work/napay.env"
docker network create --internal --subnet "$subnet" "$gateway" >/dev/null
probe() { docker run --rm --network "$gateway" --entrypoint curl "napay/napay:$tag" -s -o /dev/null -w '%{http_code}' --max-time 10 "http://napay-web$1" || true; }

bash "$root/docker/deploy.sh" "$work/napay.env" > "$work/first.log" 2>&1 || { tail -n 30 "$work/first.log" >&2; fail 'first deploy'; }
grep -q 'Prima installazione: nessun database da salvare.' "$work/first.log" && grep -q "Napay deploy OK: commit $tag" "$work/first.log" || fail 'first deploy output'
[[ -z $(ls -A "$work/backup") ]] || fail 'backup on first install'
pass 'First deploy from the reviewed v2 dataset: no backup, release of the current commit'
[[ $(compose ps --format '{{.Service}} {{.Health}}' web) == 'web healthy' ]] || fail 'web not healthy'
[[ $(probe /site/login) == 200 ]] || fail 'login page through the gateway network'
pass 'Web healthy and reachable as napay-web on the gateway network'
[[ -z $(docker inspect --format '{{range $p, $b := .NetworkSettings.Ports}}{{if $b}}{{$p}}{{end}}{{end}}' "$(compose ps -q web)") ]] || fail 'web publishes a host port'
[[ $(docker inspect --format '{{.Config.Image}}' "$(compose ps -q web)") == "napay/napay:$tag" ]] || fail 'web image tag'
pass 'No host port; web runs the image of the release commit'
compose exec -T web sh -c 'touch /var/www/html/config/x 2>/dev/null' && fail 'code writable in web' || true
pass 'Application code read-only inside the web container'

bash "$root/docker/deploy.sh" "$work/napay.env" > "$work/second.log" 2>&1 || { tail -n 30 "$work/second.log" >&2; fail 'second deploy'; }
backup=$(ls "$work/backup"/napay-*-"$tag".sql.gz 2>/dev/null | head -n 1)
[[ -n $backup && $(stat -c '%a' "$backup") == 600 ]] || fail 'backup file'
gzip -dc "$backup" | grep -q 'CREATE TABLE `users`' || fail 'backup content'
grep -q "Backup: $backup" "$work/second.log" && grep -q "Napay deploy OK: commit $tag" "$work/second.log" || fail 'second deploy output'
[[ $(probe /site/login) == 200 ]] || fail 'login page after second deploy'
pass 'Second deploy: private gzip backup of the database (0600) before migrations, service back up'
# --- backup giornaliero (SIP-036 parte C), ripristino reale e rotazione
bash "$root/docker/backup.sh" "$work/napay.env" > "$work/backup.log" 2>&1 || { cat "$work/backup.log" >&2; fail 'daily backup'; }
daily=$(ls "$work/backup"/napay-*-daily.sql.gz 2>/dev/null | head -n 1)
[[ -n $daily && $(stat -c '%a' "$daily") == 600 ]] && gzip -t "$daily" && [[ -z $(ls "$work/backup"/*.partial 2>/dev/null) ]] || fail 'daily backup file'
pass 'Daily backup: complete gzip file, 0600, no partial file left'
sql() { compose exec -T mysql sh -c 'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" mysql -uroot -N -B -e "$1"' sh "$1"; }
source_tables=$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='napay_deploy_e2e'")
source_users=$(sql 'SELECT COUNT(*) FROM napay_deploy_e2e.users')
openssl rand -hex 16 > "$work/restore-pw"; chmod 644 "$work/restore-pw"
docker run -d --name "napay-restore-$run" --network none -e MYSQL_ROOT_PASSWORD_FILE=/pw -e MYSQL_DATABASE=restored -v "$work/restore-pw:/pw:ro" mysql:8.4 >/dev/null
rsql() { docker exec -i "napay-restore-$run" sh -c 'MYSQL_PWD="$(cat /pw)" mysql -uroot -N -B "$@"' sh "$@"; }
for _ in $(seq 1 60); do rsql -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done
gzip -dc "$daily" | rsql restored || fail 'restore'
[[ $(rsql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='restored'") == "$source_tables" && $(rsql -e 'SELECT COUNT(*) FROM restored.users') == "$source_users" && $source_users -gt 0 ]] \
    || fail 'restored content differs'
pass "Daily backup restores into an empty MySQL: same $source_tables tables and $source_users users"
printf x | gzip > "$work/backup/napay-20000101T000000Z-daily.sql.gz"; printf x | gzip > "$work/backup/napay-20000101T000000Z-oldrelease.sql.gz"
touch -d '30 days ago' "$work/backup/napay-20000101T000000Z-daily.sql.gz" "$work/backup/napay-20000101T000000Z-oldrelease.sql.gz"
bash "$root/docker/backup.sh" "$work/napay.env" > "$work/backup.log" 2>&1 || fail 'second daily backup'
[[ ! -e $work/backup/napay-20000101T000000Z-daily.sql.gz && -e $work/backup/napay-20000101T000000Z-oldrelease.sql.gz && $(ls "$work/backup"/napay-*-daily.sql.gz | wc -l) == 2 ]] \
    || fail 'rotation'
pass 'Rotation removes only daily backups older than the retention; release backups stay'
# script dell'orchestratore (quando napay è dentro napay-project): Napay + database del wallet
if [[ -f $root/../backup-daily.sh ]]; then
    docker run -d --name "napay-wallet-db-$run" --network none -e MYSQL_ROOT_PASSWORD="$(openssl rand -hex 16)" -e MYSQL_DATABASE=wallet_e2e mysql:8.0 >/dev/null
    for _ in $(seq 1 60); do docker exec "napay-wallet-db-$run" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE TABLE IF NOT EXISTS wallet_e2e.mp_wallets (id INT PRIMARY KEY)"' >/dev/null 2>&1 && break; sleep 2; done
    WALLET_DB_CONTAINER="napay-wallet-db-$run" bash "$root/../backup-daily.sh" "$work/napay.env" > "$work/daily.log" 2>&1 || { cat "$work/daily.log" >&2; fail 'orchestrator daily backup'; }
    wallet=$(ls "$work/backup"/wallet-*-daily.sql.gz | head -n 1)
    [[ $(stat -c '%a' "$wallet") == 600 ]] && gzip -dc "$wallet" | grep -q 'CREATE TABLE `mp_wallets`' && [[ $(ls "$work/backup"/napay-*-daily.sql.gz | wc -l) == 3 ]] \
        || fail 'orchestrator daily backup content'
    pass 'Orchestrator backup-daily.sh: Napay and wallet databases, both 0600'
fi
for log in first second; do
    for secret in root migrator worker; do grep -Fq -f "$work/$secret" "$work/$log.log" && fail "secret in $log deploy output"; done
done
pass 'Deploy output contains no database secret'
printf 'PASS %s Napay deploy e2e checks\n' "$checks"

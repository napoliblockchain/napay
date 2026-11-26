#!/usr/bin/env bash
# SIP-036 A5: dataset base per il primo avvio in produzione, dove non esiste napay_v2 (2026-11-24: solo il database v1
# `npay`). Dal dataset v2 rivisto ricava lo stesso schema con i soli storico delle migration e livelli di privilegio:
# nessun utente, merchant, log, consenso o impostazione (le impostazioni del prototipo possono contenere chiavi). Tutti
# i dati arrivano poi dall'import della v1 (LEGACY_DB_DSN). MySQL usa-e-getta senza rete; output napay-v2.sql +
# manifest.json (formato del bootstrap). Uso: bash docker/base-dataset.sh <dataset-rivisto> <cartella-output-vuota>
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
[[ $# -eq 2 && -f $1/napay-v2.sql && -d $2 && -z $(ls -A "$2") ]] || { echo 'uso: base-dataset.sh <dataset-rivisto> <cartella-output-vuota>' >&2; exit 1; }
source=$(cd -- "$1" && pwd -P); out=$(cd -- "$2" && pwd -P)
run=$(openssl rand -hex 4); net="napay-base-dataset-$run"; db="napay-base-dataset-db-$run"
work=$(mktemp -d)
cleanup() { docker rm -f "$db" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
openssl rand -hex 16 > "$work/pw"; chmod 644 "$work/pw"
docker network create --internal "$net" >/dev/null
docker run -d --name "$db" --network "$net" --network-alias mysql -e MYSQL_ROOT_PASSWORD_FILE=/pw -e MYSQL_DATABASE=napay_v2 \
    -v "$work/pw:/pw:ro" mysql:8.4 >/dev/null
sql() { docker exec -i "$db" sh -c 'MYSQL_PWD="$(cat /pw)" mysql -uroot -N -B napay_v2'; }
for _ in $(seq 1 60); do echo 'SELECT 1' | sql >/dev/null 2>&1 && break; sleep 2; done
sql < "$source/napay-v2.sql"
# solo riferimenti tecnici: storico migration e livelli di privilegio
sql <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM logs; DELETE FROM merchants; DELETE FROM np_comuni_italiani; DELETE FROM settings; DELETE FROM stores;
DELETE FROM storesettings; DELETE FROM user_consensus; DELETE FROM users; DELETE FROM webhooks;
SET FOREIGN_KEY_CHECKS=1;
SQL
docker exec "$db" sh -c 'MYSQL_PWD="$(cat /pw)" exec mysqldump -uroot --single-transaction --quick --routines --events --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --skip-dump-date napay_v2' \
    | sed -E 's/ AUTO_INCREMENT=[0-9]+//' > "$out/napay-v2.sql"
docker run --rm --network "$net" --user "$(id -u):$(id -g)" --read-only -v "$root:/app:ro" -v "$out:/export" -v "$work/pw:/pw:ro" -e NAPAY_SOURCE_DSN='mysql:host=mysql;port=3306;dbname=napay_v2' \
    --entrypoint sh napay/bootstrap:dev -c 'NAPAY_SOURCE_PASSWORD="$(cat /pw)" exec php /app/docker/export-v2-manifest.php'
chmod 600 "$out/napay-v2.sql" "$out/manifest.json"
echo "Dataset base pronto in $out"

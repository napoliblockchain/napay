#!/usr/bin/env bash
# SIP-036 parte C: backup giornaliero del database Napay sul filesystem della VPS (primo livello; la copia fuori macchina
# si preleva da NAPAY_BACKUP_DIR). Uso: bash docker/backup.sh /percorso/privato/napay.env
# Stesso dump del rilascio (mysql-tools.sh backup, credenziali solo dentro il container MySQL), gzip, 0600, scritto prima
# in un file temporaneo e rinominato solo se completo. Rotazione: si cancellano solo i backup giornalieri
# (napay-*-daily.sql.gz) più vecchi di NAPAY_BACKUP_KEEP_DAYS (default 14); quelli dei rilasci restano.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
source "$root/docker/private-inputs.sh"
fail() { printf 'ERRORE: %s\n' "$1" >&2; exit 1; }
[[ $# -eq 1 ]] || fail 'uso: docker/backup.sh env-file'
env_file=$1
napay_private_input "$env_file" file || exit 1
env_value() { sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed "s/^\"\(.*\)\"$/\1/; s/^'\(.*\)'$/\1/"; }
backup_dir=$(env_value NAPAY_BACKUP_DIR)
napay_private_input "$backup_dir" directory || fail 'NAPAY_BACKUP_DIR mancante o non privata (0700, fuori dal repository).'
keep=$(env_value NAPAY_BACKUP_KEEP_DAYS); keep=${keep:-14}
[[ $keep =~ ^[1-9][0-9]{0,2}$ ]] || fail 'NAPAY_BACKUP_KEEP_DAYS non valido (1-999).'
export NAPAY_ENV_FILE=$env_file
compose=(docker compose --project-directory "$root" --env-file "$env_file" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml")
[[ -n $("${compose[@]}" ps -q --status running mysql 2>/dev/null) ]] || fail 'database Napay non in esecuzione.'

stamp=$(date -u +%Y%m%dT%H%M%SZ)
final="$backup_dir/napay-$stamp-daily.sql.gz"
partial="$final.partial"
trap 'rm -f "$partial"' EXIT
(set -o noclobber; "${compose[@]}" exec -T mysql sh /opt/napay/mysql-tools.sh backup | gzip -9 > "$partial") || fail 'backup del database non riuscito.'
gzip -t "$partial" && [[ $(gzip -dc "$partial" | head -c 4096 | wc -c) -gt 0 ]] || fail 'backup vuoto o danneggiato.'
chmod 600 "$partial"
mv -n "$partial" "$final"
[[ -f $final ]] || fail 'backup non salvato.'
find "$backup_dir" -maxdepth 1 -type f -name 'napay-*-daily.sql.gz' -mtime +"$keep" -delete
echo "Backup Napay: $final"

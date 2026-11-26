#!/usr/bin/env bash
# SIP-036 A3: rilascio di Napay in produzione (VPS A), sopra docker/up.sh (ruoli DB, ripristino napay_v2, import v1).
# Uso: bash docker/deploy.sh /percorso/privato/napay.env [--check]
#   --check  valida file env, segreti, overlay e modello compose senza costruire, avviare o fare backup.
# Il rilascio è il commit corrente (working tree pulito): immagini con tag del commit, overlay di produzione, worker
# BTCPay se i suoi segreti sono configurati. Se il database esiste già, prima del rilascio un backup completo
# (mysql-tools.sh backup, gzip, 0600) in NAPAY_BACKUP_DIR: le migration non si annullano, il ritorno alla versione
# precedente è il ripristino di quel backup con il commit precedente.
# Variabili in più rispetto a up.sh: NAPAY_GATEWAY_NETWORK (rete del gateway dell'orchestratore), NAPAY_TRUSTED_PROXIES
# (CIDR di quella rete), NAPAY_BACKUP_DIR (cartella privata 0700 fuori dal repository). Con MAIL_TRANSPORT=smtp anche
# l'inoltro della posta (docker-compose.mail.yml; facoltative NAPAY_SMTP_RESOLVER, NAPAY_SMTP_UPSTREAM). Facoltative
# NAPAY_WALLET_MAIL_VOLUME e NAPAY_WALLET_URL: email del wallet salvate su file visibili nella pagina "Posta in uscita".
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
source "$root/docker/private-inputs.sh"
fail() { printf 'ERRORE: %s\n' "$1" >&2; exit 1; }

[[ $# -ge 1 && $# -le 2 && ( ${2:-} == '' || ${2:-} == --check ) ]] || fail 'uso: docker/deploy.sh env-file [--check]'
env_file=$1
napay_private_input "$env_file" file || exit 1
# valori letti senza eseguire il file (nessun source)
env_value() { sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed "s/^\"\(.*\)\"$/\1/; s/^'\(.*\)'$/\1/"; }

[[ -z $(git -C "$root" status --porcelain --untracked-files=no) ]] || fail 'working tree con modifiche: si rilascia solo un commit.'
tag=$(git -C "$root" rev-parse --short=12 HEAD)
gateway=$(env_value NAPAY_GATEWAY_NETWORK)
[[ $gateway =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$ ]] || fail 'NAPAY_GATEWAY_NETWORK mancante o non valida.'
cidr='(25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})(\.(25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})){3}/([0-9]|[12][0-9]|3[0-2])'
[[ $(env_value NAPAY_TRUSTED_PROXIES) =~ ^$cidr(,$cidr)*$ ]] || fail 'NAPAY_TRUSTED_PROXIES mancante o non valida (CIDR della rete del gateway).'
backup_dir=$(env_value NAPAY_BACKUP_DIR)
napay_private_input "$backup_dir" directory || fail 'NAPAY_BACKUP_DIR mancante o non privata (0700, fuori dal repository).'
overlays=docker-compose.prod.yml
if [[ -n $(env_value BTCPAY_PROVISIONER_KEY_SECRET_FILE) ]]; then overlays+=:docker-compose.btcpay-worker.yml; fi
# posta SMTP: inoltro TCP dedicato verso il solo server di posta (docker-compose.mail.yml)
if [[ $(env_value MAIL_TRANSPORT) == smtp ]]; then
    [[ $(env_value MAIL_SMTP_HOST) =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || fail 'MAIL_SMTP_HOST mancante o non valido.'
    # 2525: porta alternativa dei relay (es. Brevo) quando il provider della VPS blocca 465/587; TLS sempre obbligatorio
    port=$(env_value MAIL_SMTP_PORT); [[ -z $port || $port =~ ^(465|587|2525)$ ]] || fail 'MAIL_SMTP_PORT ammessa: 465, 587 o 2525.'
    for name in NAPAY_SMTP_RESOLVER NAPAY_SMTP_UPSTREAM; do
        value=$(env_value $name)
        case $name in
            NAPAY_SMTP_RESOLVER) [[ -z $value || $value =~ ^[0-9.]{7,15}(\ [0-9.]{7,15}){0,3}$ ]] || fail 'NAPAY_SMTP_RESOLVER non valido (IPv4 separati da spazi).' ;;
            NAPAY_SMTP_UPSTREAM) [[ -z $value || $value =~ ^[a-z0-9]([a-z0-9.-]{0,252})$ ]] || fail 'NAPAY_SMTP_UPSTREAM non valido.' ;;
        esac
    done
    overlays+=:docker-compose.mail.yml
fi
# posta del wallet su file: cartella in sola lettura per la pagina admin "Posta in uscita" (docker-compose.wallet-mail.yml)
wallet_mail=$(env_value NAPAY_WALLET_MAIL_VOLUME)
if [[ -n $wallet_mail ]]; then
    [[ $wallet_mail =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$ ]] || fail 'NAPAY_WALLET_MAIL_VOLUME non valido.'
    [[ $(env_value NAPAY_WALLET_URL) =~ ^https://([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}/?$ ]] \
        || fail 'NAPAY_WALLET_URL mancante o non valido (https://dominio del wallet).'
    overlays+=:docker-compose.wallet-mail.yml
fi
export NAPAY_IMAGE_TAG=$tag NAPAY_COMPOSE_OVERLAYS=$overlays NAPAY_ENV_FILE=$env_file

if [[ ${2:-} == --check ]]; then
    bash "$root/docker/up.sh" "$env_file" --check
    echo "Napay deploy preflight OK (commit $tag); nothing built, started or backed up."
    exit 0
fi

docker network inspect "$gateway" >/dev/null 2>&1 || fail 'rete del gateway assente: avviare prima l'"'"'orchestratore.'
[[ -z $wallet_mail ]] || docker volume inspect "$wallet_mail" >/dev/null 2>&1 \
    || fail 'volume della posta del wallet assente: rilasciare prima il wallet (deploy-wallet.sh).'
bash "$root/docker/up.sh" "$env_file" --check
compose=(docker compose --project-directory "$root" --env-file "$env_file" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml")
IFS=: read -r -a files <<< "$overlays"
for file in "${files[@]}"; do compose+=(-f "$root/$file"); done
# Un clone pulito non ha vendor/ né runtime/ (esclusi da git) e il codice è montato in sola lettura: dipendenze
# del composer.lock del commit installate con l'immagine del rilascio (composer audit compreso), punto di montaggio
# di runtime/ creato qui. Leggibili dai container anche con umask 077.
"${compose[@]}" build web >/dev/null || fail 'immagine web non costruita.'
NAPAY_DEPENDENCIES_IMAGE="napay/napay:$tag" bash "$root/docker/dependencies.sh" >/dev/null \
    || fail 'dipendenze PHP non installate (composer install/audit): nessun rilascio.'
install -d -m 0755 "$root/runtime"
volume=$(env_value NAPAY_MYSQL_VOLUME)
if [[ -n $volume ]] && docker volume inspect "$volume" >/dev/null 2>&1; then
    "${compose[@]}" up -d --wait --wait-timeout 300 mysql >/dev/null
    backup="$backup_dir/napay-$(date -u +%Y%m%dT%H%M%SZ)-$tag.sql.gz"
    (set -o noclobber; "${compose[@]}" exec -T mysql sh /opt/napay/mysql-tools.sh backup | gzip -9 > "$backup") \
        || { rm -f "$backup"; fail 'backup del database non riuscito: nessun rilascio.'; }
    [[ $(gzip -dc "$backup" | head -c 4096 | wc -c) -gt 0 ]] || { rm -f "$backup"; fail 'backup vuoto: nessun rilascio.'; }
    chmod 600 "$backup"
    echo "Backup: $backup"
else
    echo 'Prima installazione: nessun database da salvare.'
fi
bash "$root/docker/up.sh" "$env_file"
# esito del bootstrap e dell'import v1 (solo conteggi, mai dati)
"${compose[@]}" logs --no-color bootstrap 2>/dev/null | grep -Eo 'Napay (bootstrap|legacy import): .*' | tail -n 2 || true
echo "Napay deploy OK: commit $tag."

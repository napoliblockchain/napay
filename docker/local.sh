#!/usr/bin/env bash
# Persistent browser sandbox, separate from the operational Napay stack.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
source "$root/docker/private-inputs.sh"
source "$root/docker/local-roles.sh"
# cartella privata (0700) fuori dal repository: NAPAY_LOCAL_STATE, altrimenti la posizione storica in $HOME
state="${NAPAY_LOCAL_STATE:-${HOME}/.local/share/napay/browser-local}"
napay_private_location "$state"
action=${1:-status}
[[ $# -le 1 && $action =~ ^(up|init|check|prepare-roles|status|stop|mail)$ ]] || { echo 'Usage: bash docker/local.sh {up|init|check|prepare-roles|status|stop|mail}' >&2; exit 1; }
[[ ! -L $state ]] || exit 1
mkdir -p "$state"
napay_private_input "$state" directory "$(id -u)"
napay_local_ignore
[[ ! -e $state/control.lock && ! -L $state/control.lock ]] || napay_private_input "$state/control.lock" file "$(id -u)"
exec 9>>"$state/control.lock"
flock -n 9 || { echo 'Another local sandbox operation is in progress.' >&2; exit 1; }
[[ ! -L $state/local.env ]] || { echo 'Symlink environment refused.' >&2; exit 1; }
if [[ $action =~ ^(up|init)$ && ! -e $state/local.env ]]; then
    dataset=$(realpath -e -- "${NAPAY_IMPORT_DIR:?Set reviewed v2 dataset directory}")
    [[ $dataset != *$'\n'* ]] || exit 1
    # Do not silently replace credentials after a partial/previous installation.
    for input in root-password browser-password migrator-password worker-password; do [[ ! -e $state/$input && ! -L $state/$input ]] || exit 1; done
    (set -o noclobber; openssl rand -hex 32 > "$state/root-password")
    (set -o noclobber; openssl rand -hex 24 > "$state/browser-password")
    napay_local_role_files
    cat > "$state/local.env" <<ENV
APP_ENV=prod
APP_DEBUG=false
HTTPS_ONLY=true
DB_DATABASE=napay_browser_local
DB_DSN=mysql:host=mysql;port=3306;dbname=napay_browser_local
DB_USERNAME=napay_browser
DB_PASSWORD=$(openssl rand -hex 24)
NAPAY_MYSQL_VOLUME=napay_browser_local_mysql_data
NAPAY_MYSQL_ROOT_SECRET_FILE=$state/root-password
NAPAY_ENV_FILE=$state/local.env
NAPAY_IMPORT_DIR=$dataset
NAPAY_BOOTSTRAP_UID=$(id -u)
NAPAY_BOOTSTRAP_GID=$(id -g)
NAPAY_LOCAL_BROWSER=isolated-copy
COOKIE_VALIDATION_KEY=$(openssl rand -hex 32)
SECRET_HASH_KEY=$(openssl rand -hex 32)
ADMIN_EMAIL=browser-admin@example.invalid
WEBAPP_LINK=https://napay.local
APP_WEBSITE=https://napay.local
WEBAPP_SOCIETY=Napay local test
MAIL_TRANSPORT=file
ENV
    napay_local_role_env >> "$state/local.env"
fi
for input in local.env root-password browser-password; do
    napay_private_input "$state/$input" file "$(id -u)"
done
export NAPAY_ENV_FILE="$state/local.env"
compose=(docker compose -p napay-browser-local --project-directory "$root" --env-file "$state/local.env" -f "$root/docker-compose.yml" -f "$root/docker/local/compose.yml")
role_mode=0
if napay_local_role_mode; then role_mode=1; else code=$?; [[ $code == 1 ]] || exit 1; fi
if [[ $action == prepare-roles && $role_mode == 0 ]]; then
    napay_local_prepare_roles
    role_mode=1
fi
if [[ $role_mode == 1 ]]; then compose+=(-f "$root/docker-compose.roles.yml"); fi
# SIP-022 B5: worker BTCPay verso il regtest locale, solo se la chiave del provisioner è nella cartella privata.
# Il portachiavi di cifratura delle chiavi merchant nasce qui una sola volta (mai sovrascritto).
if [[ $role_mode == 1 && ( -e $state/btcpay-provisioner-key || -L $state/btcpay-provisioner-key ) ]]; then
    napay_private_input "$state/btcpay-provisioner-key" file "$(id -u)"
    [[ -e $state/btcpay-kek-keyring || -L $state/btcpay-kek-keyring ]] \
        || (set -o noclobber; printf '{"current":"k1","keys":{"k1":"%s"}}\n' "$(openssl rand -base64 32)" > "$state/btcpay-kek-keyring")
    napay_private_input "$state/btcpay-kek-keyring" file "$(id -u)"
    napay_private_input "$state/caddy-root.crt" file "$(id -u)"
    export BTCPAY_PROVISION_ORIGIN=https://btcpay.local
    BTCPAY_PROVISION_INSTANCE=$(printf %s "$BTCPAY_PROVISION_ORIGIN" | sha256sum | cut -c1-64)
    export BTCPAY_PROVISION_INSTANCE BTCPAY_PROVISIONER_KEY_SECRET_FILE=$state/btcpay-provisioner-key
    export BTCPAY_KEK_KEYRING_SECRET_FILE=$state/btcpay-kek-keyring NAPAY_LOCAL_BTCPAY_CA=$state/caddy-root.crt
    compose+=(-f "$root/docker-compose.btcpay-worker.yml" -f "$root/docker/local/btcpay-worker.yml")
fi
if [[ $action =~ ^(up|init|check|prepare-roles)$ ]]; then
    [[ $role_mode == 1 ]] || { echo 'Run prepare-roles once before starting this existing sandbox.' >&2; exit 1; }
    bash "$root/docker/up.sh" "$state/local.env" --check
    "${compose[@]}" --profile db-check config --format json | docker run --rm -i --network none --read-only \
        -v "$root/tests/docker/local-config.php:/check.php:ro" --entrypoint php php:8.3.36-cli /check.php
fi
case "$action" in
    init|check|prepare-roles) echo 'Local role configuration ready; no services started.' ;;
    up)
        # rete interna condivisa con napay-gateway (vedi start-localhost.sh dell'orchestratore)
        docker network inspect napay_local_browser >/dev/null 2>&1 \
            || docker network create --internal --label napay.service=browser-local napay_local_browser >/dev/null
        if ! docker volume inspect napay_browser_local_mysql_data >/dev/null 2>&1; then
            docker volume create --label napay.service=browser-local napay_browser_local_mysql_data >/dev/null
        fi
        # web fermo durante il bootstrap: dopo un upgrade dello schema riparte con un healthcheck nuovo
        # (altrimenti --wait vede ancora "unhealthy" dal periodo prima dell'upgrade e si interrompe)
        "${compose[@]}" stop web >/dev/null 2>&1
        # --remove-orphans: rimuove il vecchio gateway del sandbox (porte 18121/18444)
        "${compose[@]}" up -d --build --wait --wait-timeout 3600 --remove-orphans
        "${compose[@]}" run --rm -T --no-deps --entrypoint php bootstrap /app/docker/local/create-user.php < "$state/browser-password"
        # HTTPS e CA locale (caddy-root.crt) da napay-gateway: avvio completo con start-localhost.sh
        printf 'URL: https://napay.local\nEmail: browser-admin@example.invalid\nPassword file: %s/browser-password\n' "$state"
        ;;
    status) "${compose[@]}" ps ;;
    stop) "${compose[@]}" stop ;;
    mail)
        mkdir -p "$state/mail-review"
        chmod 700 "$state/mail-review"
        "${compose[@]}" cp web:/var/www/html/runtime/mail/. "$state/mail-review/"
        # accanto a ogni .eml un .txt leggibile: testo decodificato, link interi (container senza rete)
        docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true --user "$(id -u):$(id -g)" \
            -v "$state/mail-review:/mail" -v "$root/docker/local/mail-text.php:/mail-text.php:ro" --entrypoint php php:8.3.36-cli /mail-text.php /mail
        find "$state/mail-review" -type f -exec chmod 600 {} +
        printf 'Private mail: %s/mail-review/\n' "$state"
        ;;
esac

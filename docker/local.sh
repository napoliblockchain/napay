#!/usr/bin/env bash
# Persistent browser sandbox, separate from the operational Napay stack.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
state="${HOME}/.local/share/napay/browser-local"
action=${1:-status}
[[ $# -le 1 && $action =~ ^(up|status|stop|mail)$ ]] || { echo 'Usage: bash docker/local.sh {up|status|stop|mail}' >&2; exit 1; }
[[ ! -L $state ]] || exit 1
mkdir -p "$state"
[[ $(stat -c '%u' "$state") == "$(id -u)" ]] || exit 1
chmod 700 "$state"
exec 9>"$state/control.lock"
flock -n 9 || { echo 'Another local sandbox operation is in progress.' >&2; exit 1; }
if [[ $action == up && ! -e $state/local.env ]]; then
    dataset=$(realpath -e -- "${NAPAY_IMPORT_DIR:?Set reviewed v2 dataset directory}")
    [[ $dataset != *$'\n'* ]] || exit 1
    # Do not silently replace credentials after a partial/previous installation.
    [[ ! -e $state/root-password && ! -e $state/browser-password ]] || exit 1
    openssl rand -hex 32 > "$state/root-password"
    openssl rand -hex 24 > "$state/browser-password"
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
WEBAPP_LINK=https://localhost:18444
APP_WEBSITE=https://localhost:18444
WEBAPP_SOCIETY=Napay local test
MAIL_TRANSPORT=file
ENV
fi
for input in local.env root-password browser-password; do
    [[ -f $state/$input && ! -L $state/$input && $(stat -c '%u' "$state/$input") == "$(id -u)" ]] || exit 1
    mode=$(stat -c '%a' "$state/$input")
    (( (8#$mode & 077) == 0 )) || exit 1
done
export NAPAY_ENV_FILE="$state/local.env"
compose=(docker compose -p napay-browser-local --project-directory "$root" --env-file "$state/local.env" -f "$root/docker-compose.yml" -f "$root/docker/local/compose.yml")
case "$action" in
    up)
        bash "$root/docker/up.sh" "$state/local.env" --check
        "${compose[@]}" config --quiet
        "${compose[@]}" config --format json | docker run --rm -i --network none --read-only \
            -v "$root/tests/docker/local-config.php:/check.php:ro" --entrypoint php php:8.3.36-cli /check.php
        if ! docker volume inspect napay_browser_local_mysql_data >/dev/null 2>&1; then
            docker volume create --label napay.service=browser-local napay_browser_local_mysql_data >/dev/null
        fi
        "${compose[@]}" up -d --build --wait --wait-timeout 3600
        "${compose[@]}" run --rm -T --no-deps --entrypoint php bootstrap /app/docker/local/create-user.php < "$state/browser-password"
        for attempt in {1..30}; do
            if "${compose[@]}" exec -T gateway test -f /data/caddy/pki/authorities/local/root.crt; then break; fi
            sleep 1
        done
        "${compose[@]}" cp gateway:/data/caddy/pki/authorities/local/root.crt "$state/caddy-root.crt"
        chmod 600 "$state/caddy-root.crt"
        curl --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 --cacert "$state/caddy-root.crt" --output /dev/null https://localhost:18444/site/login
        printf 'URL: https://localhost:18444\nUser: browser-admin\nPassword file: %s/browser-password\nLocal CA: %s/caddy-root.crt\n' "$state" "$state"
        ;;
    status) "${compose[@]}" ps ;;
    stop) "${compose[@]}" stop ;;
    mail)
        mkdir -p "$state/mail-review"
        chmod 700 "$state/mail-review"
        "${compose[@]}" cp web:/var/www/html/runtime/mail/. "$state/mail-review/"
        find "$state/mail-review" -type f -exec chmod 600 {} +
        printf 'Private mail: %s/mail-review/\n' "$state"
        ;;
esac

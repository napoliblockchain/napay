#!/usr/bin/env bash
# Private state helpers; role preparation runs with the local.sh lock held.
napay_local_ignore() {
    local name
    [[ ! -e $state/.gitignore && ! -L $state/.gitignore ]] || napay_private_input "$state/.gitignore" file "$(id -u)" || return 1
    for name in local.env root-password browser-password migrator-password worker-password control.lock mail-review '.local.env.*'; do
        if git -C "$state" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
            && [[ -n $(git -C "$state" ls-files -- "$name") ]]; then
            echo 'Private sandbox input is tracked by Git; remove it securely before continuing.' >&2; return 1
        fi
        grep -qxF "/$name" "$state/.gitignore" 2>/dev/null || printf '\n/%s\n' "$name" >> "$state/.gitignore"
    done
}
napay_local_role_files() {
    (set -o noclobber; openssl rand -hex 32 > "$state/migrator-password")
    (set -o noclobber; openssl rand -hex 32 > "$state/worker-password")
}
napay_local_role_env() {
    cat <<ENV
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$state/migrator-password
NAPAY_DB_WORKER_SECRET_FILE=$state/worker-password
ENV
}
napay_local_role_mode() {
    local count
    count=$(grep -Ec '^(NAPAY_DB_MIGRATOR_USERNAME|NAPAY_DB_WORKER_USERNAME|NAPAY_DB_MIGRATOR_SECRET_FILE|NAPAY_DB_WORKER_SECRET_FILE)=' "$state/local.env" || true)
    if [[ $count == 4 ]]; then return 0; fi
    if [[ $count != 0 || -e $state/migrator-password || -L $state/migrator-password || -e $state/worker-password || -L $state/worker-password ]]; then
        echo 'Partial role configuration: restore original private files; do not regenerate credentials.' >&2; return 2
    fi
    return 1
}
napay_local_prepare_roles() {
    # A lost secret must never cause rotation or new credentials for an existing role.
    "${compose[@]}" exec -T mysql sh /opt/napay/mysql-tools.sh role-accounts-free
    napay_local_role_files
    local staged
    staged=$(mktemp "$state/.local.env.XXXXXXXX")
    cat "$state/local.env" > "$staged"
    printf '\n' >> "$staged"
    napay_local_role_env >> "$staged"
    mv -- "$staged" "$state/local.env"
}

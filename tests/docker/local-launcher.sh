#!/usr/bin/env bash
# Never call persistent Docker operations: only Compose config/PHP validation are real.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
work=$(mktemp -d)
trap 'code=$?; if (( code != 0 )); then cat "$work/output" >&2 2>/dev/null || true; fi; rm -rf "$work"' EXIT
trap 'exit 1' HUP INT TERM
real_docker=$(command -v docker)
mkdir "$work/bin" "$work/import"
printf '{}\n' > "$work/import/manifest.json"; : > "$work/import/napay-v2.sql"
cat > "$work/bin/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
    run) exec "$LAUNCHER_REAL_DOCKER" "$@" ;;
    network|volume) [[ ${2:-} == inspect ]] && exit 0 ;;
    compose)
        for arg in "$@"; do
            case "$arg" in
                config) exec "$LAUNCHER_REAL_DOCKER" "$@" ;;
                exec)
                    [[ ${*: -1} == role-accounts-free ]] || exit 1
                    printf 'probe\n' >> "$LAUNCHER_CALLS"
                    [[ ! -e $LAUNCHER_REFUSE_PROBE ]]; exit ;;
                stop|up)
                    printf '%s\n' "$arg" >> "$LAUNCHER_CALLS"; exit 0 ;;
                run)
                    [[ ${*: -1} == /app/docker/local/create-user.php ]] || exit 1
                    cat >/dev/null; printf 'identity\n' >> "$LAUNCHER_CALLS"; exit 0 ;;
            esac
        done ;;
esac
echo 'Unexpected Docker call refused by test.' >&2
exit 1
SH
chmod 700 "$work/bin/docker"
checks=0
run_local() {
    env -i PATH="$work/bin:$PATH" HOME="$HOME" \
        NAPAY_LOCAL_STATE="$state" NAPAY_IMPORT_DIR="$work/import" \
        LAUNCHER_REAL_DOCKER="$real_docker" LAUNCHER_CALLS="$work/calls" LAUNCHER_REFUSE_PROBE="$work/refuse" \
        bash "$root/docker/local.sh" "$1" > "$work/output" 2>&1
}
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
reject() { if run_local "$2"; then echo "FAIL: $1" >&2; exit 1; fi; pass "$1"; }
fingerprint() { sha256sum "$state/local.env" "$state/"*-password; }
legacy() {
    mkdir "$1"
    cp "$fresh/local.env" "$1/local.env"
    sed -i "s|$fresh|$1|g" "$1/local.env"
    sed -i '/^NAPAY_DB_\(MIGRATOR\|WORKER\)_/d' "$1/local.env"
    cp "$fresh/root-password" "$1/root-password"; cp "$fresh/browser-password" "$1/browser-password"
}
state="$work/fresh"; fresh=$state
run_local up
[[ $(grep -c '^up$' "$work/calls") == 1 ]]
for file in local.env root-password browser-password migrator-password worker-password .gitignore control.lock; do
    [[ -f $state/$file && $(stat -c '%a' "$state/$file") == 600 ]]
done
[[ $(stat -c '%a' "$state") == 700 ]]
pass 'Fresh installation generates four private credentials and role configuration'
fingerprint > "$work/before"
run_local up; fingerprint > "$work/after"; cmp "$work/before" "$work/after"
pass 'Second installation leaves all credentials unchanged'
cp "$work/calls" "$work/calls-before"
run_local check; cmp "$work/calls-before" "$work/calls"
pass 'Local check performs no service or database operation'
run_local prepare-roles; cmp "$work/calls-before" "$work/calls"
pass 'Prepared installation is idempotent, with no new secrets or SQL probe'
state="$work/legacy"; legacy "$state"
reject 'Legacy up requires explicit preparation' up
[[ ! -e $state/migrator-password && ! -e $state/worker-password ]]
sha256sum "$state/root-password" "$state/browser-password" > "$work/old-before"
run_local prepare-roles
grep -q '^probe$' "$work/calls"
sha256sum "$state/root-password" "$state/browser-password" > "$work/old-after"; cmp "$work/old-before" "$work/old-after"
pass 'Explicit preparation probes existing roles and preserves old root/login credentials'
fingerprint > "$work/before"; run_local prepare-roles; fingerprint > "$work/after"; cmp "$work/before" "$work/after"
pass 'Repeated explicit preparation never rotates secrets'
state="$work/existing-roles"; legacy "$state"; touch "$work/refuse"
reject 'Existing DB roles prevent replacement secret generation' prepare-roles
[[ ! -e $state/migrator-password && ! -e $state/worker-password ]]
! grep -q '^NAPAY_DB_WORKER_' "$state/local.env"
rm "$work/refuse"
state="$work/partial"; legacy "$state"; openssl rand -hex 32 > "$state/worker-password"
fingerprint > "$work/before"; reject 'Partial secret files block preparation' prepare-roles
fingerprint > "$work/after"; cmp "$work/before" "$work/after"
state="$work/missing"; cp -a "$fresh" "$state"; sed -i "s|$fresh|$state|g" "$state/local.env"; rm "$state/worker-password"
reject 'Prepared state with lost secret refuses regeneration' prepare-roles
[[ ! -e $state/worker-password ]]
state="$work/open-state"; mkdir -m 755 "$state"; reject 'Public state directory refused, not silently chmodded' status
ln -s "$fresh" "$work/state-link"; state="$work/state-link"; reject 'Symlink state refused' check
state="$work/locked"; legacy "$state"; ln -s "$work/calls" "$state/control.lock"
cp "$work/calls" "$work/calls-before"; reject 'Symlink control lock refused without truncation' status
cmp "$work/calls-before" "$work/calls"
state="$work/tracked"; legacy "$state"; git -C "$state" init -q; git -C "$state" add local.env
reject 'Git-tracked private input refused' prepare-roles
state="$work/dangling"; mkdir "$state"; ln -s "$work/must-not-exist" "$state/local.env"
reject 'Dangling environment symlink refused before generating credentials' up
[[ ! -e $work/must-not-exist && ! -e $state/root-password ]]
state="$work/interrupted"; mkdir "$state"; openssl rand -hex 32 > "$state/root-password"
sha256sum "$state/root-password" > "$work/before"; reject 'Interrupted first installation never overwrites root secret' up
sha256sum "$state/root-password" > "$work/after"; cmp "$work/before" "$work/after"
[[ ! -e $state/browser-password && ! -e $state/migrator-password ]]
state="$work/ignored"; legacy "$state"; git -C "$state" init -q; run_local prepare-roles
for file in local.env root-password browser-password migrator-password worker-password control.lock; do
    git -C "$state" check-ignore -q "$file"
done
pass 'Local Git exclusions cover private files before credentials are generated'
printf 'PASS %s local role launcher checks\n' "$checks"

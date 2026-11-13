#!/usr/bin/env bash
# Real Compose/PHP preflight; intercept every persistent Docker operation.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
work=$(mktemp -d)
trap 'code=$?; if (( code != 0 )); then cat "$work/output" >&2 2>/dev/null || true; fi; rm -rf "$work"' EXIT
trap 'exit 1' HUP INT TERM
real_docker=$(command -v docker)
mkdir "$work/bin" "$work/import"
cat > "$work/bin/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
    compose)
        for arg in "$@"; do
            if [[ $arg == config ]]; then exec "$LAUNCHER_REAL_DOCKER" "$@"; fi
        done ;;
    run) exec "$LAUNCHER_REAL_DOCKER" "$@" ;;
esac
touch "$LAUNCHER_PERSISTENT_CALL"
echo 'FAIL: preflight attempted a persistent Docker operation.' >&2
exit 1
SH
chmod 700 "$work/bin/docker"
for name in root migrator worker; do openssl rand -hex 32 > "$work/$name"; done
printf '{}\n' > "$work/import/manifest.json"
: > "$work/import/napay-v2.sql"
cat > "$work/base.env" <<ENV
APP_ENV=test
APP_DEBUG=false
DB_DATABASE=napay_launcher_test
DB_DSN=mysql:host=mysql;port=3306;dbname=napay_launcher_test
DB_USERNAME=napay_web
DB_PASSWORD=$(openssl rand -hex 32)
NAPAY_MYSQL_VOLUME=napay_launcher_preflight_never_created
NAPAY_MYSQL_ROOT_SECRET_FILE=$work/root
NAPAY_IMPORT_DIR=$work/import
NAPAY_BOOTSTRAP_UID=$(id -u)
NAPAY_BOOTSTRAP_GID=$(id -g)
NAPAY_DB_MIGRATOR_USERNAME=napay_migrator
NAPAY_DB_WORKER_USERNAME=napay_worker
NAPAY_DB_MIGRATOR_SECRET_FILE=$work/migrator
NAPAY_DB_WORKER_SECRET_FILE=$work/worker
ENV
cp "$work/base.env" "$work/test.env"
checks=0
run_check() {
    env -i PATH="$work/bin:$PATH" HOME="$HOME" \
        LAUNCHER_REAL_DOCKER="$real_docker" LAUNCHER_PERSISTENT_CALL="$work/persistent" \
        bash "$root/docker/up.sh" "${1:-$work/test.env}" --check > "$work/output" 2>&1
}
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
reject() {
    if run_check "${2:-$work/test.env}"; then echo "FAIL: $1" >&2; exit 1; fi
    [[ ! -e $work/persistent ]] || exit 1
    pass "$1"
}
change() { sed "s|^$1=.*|$1=$2|" "$work/base.env" > "$work/test.env"; }
run_check
grep -q 'Napay role preflight OK' "$work/output"
pass 'Valid external role configuration, no persistent operations'
reject 'Relative environment refused' relative.env
reject 'Missing environment refused' "$work/missing.env"
chmod 644 "$work/test.env"; reject 'Readable-by-others environment refused'; chmod 600 "$work/test.env"
ln -s "$work/test.env" "$work/link.env"; reject 'Symlink environment refused' "$work/link.env"
ln -s "$work" "$work/alias"; reject 'Symlink parent refused' "$work/alias/test.env"
reject 'Noncanonical path refused' "$work/import/../test.env"
change NAPAY_DB_WORKER_SECRET_FILE "$work/missing"; reject 'Missing worker secret refused'
change NAPAY_DB_WORKER_SECRET_FILE "$work/link.env"; reject 'Symlink worker secret refused'
change NAPAY_DB_WORKER_SECRET_FILE "$root/composer.json"; reject 'In-repository secret refused'
cp "$work/base.env" "$work/test.env"
chmod 644 "$work/worker"; reject 'Readable-by-others worker secret refused'; chmod 600 "$work/worker"
chmod 755 "$work/import"; reject 'Readable-by-others dataset refused'; chmod 700 "$work/import"
chmod 644 "$work/import/napay-v2.sql"; reject 'Readable-by-others SQL refused'; chmod 600 "$work/import/napay-v2.sql"
change NAPAY_DB_WORKER_SECRET_FILE "$work/root"; reject 'Shared role secret file refused'
cp "$work/worker" "$work/original-worker"; cp "$work/root" "$work/worker"
cp "$work/base.env" "$work/test.env"; reject 'Reused role password refused'; cp "$work/original-worker" "$work/worker"
printf 'short\n' > "$work/worker"; reject 'Short role password refused'; cp "$work/original-worker" "$work/worker"
printf '%s\n%s\n' invalid multiline > "$work/worker"; reject 'Multiline role password refused'; cp "$work/original-worker" "$work/worker"
change DB_PASSWORD ''; reject 'Empty web password refused'
change DB_PASSWORD short; reject 'Short web password refused'
change DB_USERNAME root; reject 'Privileged web account refused'
change NAPAY_DB_WORKER_USERNAME napay_web; reject 'Shared account identity refused'
change NAPAY_BOOTSTRAP_UID "$(( $(id -u) + 1 ))"; reject 'Bootstrap owner mismatch refused'
cp "$work/base.env" "$work/test.env"
printf 'NAPAY_STACK_WAIT_SECONDS=0\n' >> "$work/test.env"; reject 'Invalid wait budget refused'
[[ ! -e $work/persistent ]] || exit 1
printf 'PASS %s role launcher preflight checks\n' "$checks"

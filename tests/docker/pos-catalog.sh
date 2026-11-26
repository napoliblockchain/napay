#!/bin/sh
# Reuse the disposable runtime setup, not the shared browser sandbox or its DB.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_C1_ROOT="$root"
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
# Fail closed if the common harness no longer exposes this fixture boundary.
awk '
    /^root=/ { print "root=${NAPAY_C1_ROOT:?}"; next }
    /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
    { print }
    END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/pos-catalog-schema.php
compose exec -T web php /var/www/html/tests/application/pos-sessions.php
compose run --rm --no-deps bootstrap
TESTS
sh "$private/runtime.sh"

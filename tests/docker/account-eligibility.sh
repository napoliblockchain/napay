#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_IDENTITY_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_IDENTITY_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/account-eligibility.php
compose run --rm --no-deps bootstrap
TESTS
sh "$private/runtime.sh"

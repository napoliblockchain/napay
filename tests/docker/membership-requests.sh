#!/bin/sh
# SIP-030 punto 1: richieste di iscrizione (lista, approvazione, rifiuto, pulizia) su DB runtime usa e getta, mail su file.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_MEMBERSHIP_REQUESTS_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_MEMBERSHIP_REQUESTS_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/membership-requests.php
# comando one-shot: senza --once non fa nulla
if compose exec -T web php /var/www/html/yii membership/purge-unverified >/dev/null 2>&1; then echo 'FAIL: purge without --once'; exit 1; fi
compose exec -T web php /var/www/html/yii membership/purge-unverified --once | grep -qx 'deleted=0'
printf '%s\n' 'PASS: purge command runs only with --once and is idempotent.'
TESTS
sh "$private/runtime.sh"

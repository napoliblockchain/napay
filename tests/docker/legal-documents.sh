#!/bin/sh
# SIP-040: documenti legali versionati (pagine pubbliche, admin, consensi). DB runtime usa e getta.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_LEGAL_DOCUMENTS_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_LEGAL_DOCUMENTS_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/legal-documents.php
# RV3: prima bozza da due processi/connessioni sovrapposti
compose exec -T web php /var/www/html/tests/application/legal-documents-race.php prepare
compose exec -T web php /var/www/html/tests/application/legal-documents-race.php a > "$work/race-a.log" & race_a=$!
compose exec -T web php /var/www/html/tests/application/legal-documents-race.php b > "$work/race-b.log" & race_b=$!
wait "$race_a"; wait "$race_b"
cat "$work/race-a.log" "$work/race-b.log" | compose exec -T web php /var/www/html/tests/application/legal-documents-race.php verify
TESTS
sh "$private/runtime.sh"

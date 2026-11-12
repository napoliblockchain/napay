#!/bin/sh
# C4: backoffice POS su stack runtime usa e getta (setup di runtime.sh fino alla fixture comune).
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_C4_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_C4_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/pos-management.php
compose exec -T web php /var/www/html/tests/application/pos-invitations.php
compose logs --no-color web | compose exec -T web php -r '
    $logs=stream_get_contents(STDIN);
    $key=getenv("NAPAY_TEST_POS_CLIENT_KEY");
    if (!is_string($key) || $key==="" || str_contains($logs,$key) || preg_match("/napos_[A-Za-z0-9_-]{43}/",$logs) || str_contains($logs,"token=")) exit(1);
    echo "PASS: C4 Apache logs contain no bearer or client key.\n";
'
compose run --rm --no-deps bootstrap | grep -q already-complete
printf '%s\n' 'PASS: bootstrap remains a no-op after C4 checks.'
TESTS
sh "$private/runtime.sh"

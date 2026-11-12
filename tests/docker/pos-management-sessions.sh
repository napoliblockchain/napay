#!/bin/sh
# Real C4/API D3 integration on a fresh disposable runtime project only.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_PMS_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_PMS_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/pos-management-sessions.php
compose logs --no-color web | compose exec -T web php -r '
    $logs=stream_get_contents(STDIN);
    foreach ([getenv("NAPAY_TEST_POS_CLIENT_KEY"),getenv("NAPAY_TEST_POS_CLIENT_KEY_2")] as $key) {
        if (!is_string($key) || $key==="" || str_contains($logs,$key)) exit(1);
    }
    if (preg_match("/napos_[A-Za-z0-9_-]{43}/",$logs)) exit(1);
    echo "PASS: integrated C4/API Apache logs contain no bearer or client key.\n";
'
compose run --rm --no-deps bootstrap
TESTS
sh "$private/runtime.sh"

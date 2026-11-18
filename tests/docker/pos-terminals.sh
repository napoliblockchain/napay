#!/bin/sh
# Disposable C2 HTTP/DB stack only; reuse setup, never the shared browser sandbox.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_C2_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
# Fail closed if the common fixture boundary changes. Do not run other suites.
awk '
    /^root=/ { print "root=${NAPAY_C2_ROOT:?}"; next }
    /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
    { print }
    END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/pos-terminals.php
# Inspect Apache logs in memory, never dump potentially sensitive failures.
compose logs --no-color web | compose exec -T web php -r '
    $logs = stream_get_contents(STDIN);
    foreach ([getenv("NAPAY_TEST_POS_CLIENT_KEY"), getenv("NAPAY_TEST_POS_CLIENT_KEY_2")] as $secret) {
        if (!is_string($secret) || $secret === "" || str_contains($logs, $secret)) exit(1);
    }
    if (preg_match("/napos_[A-Za-z0-9_-]{43}/", $logs)) exit(1);
    echo "PASS: Apache logs contain no bearer or client key.\n";
'
compose run --rm --no-deps bootstrap
TESTS
sh "$private/runtime.sh"

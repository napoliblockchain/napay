#!/bin/sh
# SIP-033 passo 3: pagamenti BTC del POS su stack usa-e-getta (nessuna porta host, nessuna rete verso BTCPay); mai la sandbox condivisa.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_BTC_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
# Fail closed if the common fixture boundary changes. Do not run other suites.
awk '
    /^root=/ { print "root=${NAPAY_BTC_ROOT:?}"; next }
    /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
    { print }
    END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/pos-btc-payments.php
! compose exec -T web php /var/www/html/yii btc-payments/sweep >/dev/null 2>&1
printf '%s\n' 'PASS: BTC sweep refuses to run without --once.'
[ "$(compose exec -T web php /var/www/html/yii btc-payments/sweep --once)" = 'checked=0 settled=0 closed=0' ]
printf '%s\n' 'PASS: BTC sweep with nothing expired is a no-op without BTCPay.'
compose logs --no-color web | compose exec -T web php -r '
    $logs = stream_get_contents(STDIN);
    $secret = getenv("NAPAY_TEST_POS_CLIENT_KEY");
    if (!is_string($secret) || $secret === "" || str_contains($logs, $secret) || preg_match("/napos_[A-Za-z0-9_-]{43}/", $logs)) exit(1);
    echo "PASS: Apache logs contain no bearer or client key.\n";
'
compose run --rm --no-deps bootstrap
TESTS
sh "$private/runtime.sh"

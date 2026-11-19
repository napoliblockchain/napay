#!/bin/sh
# SIP-030 passo 5: negozi v1 sul ponte BTCPay (webhook e app POS dei terminali) su stack usa-e-getta; mai la sandbox condivisa.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_BRIDGE_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
    /^root=/ { print "root=${NAPAY_BRIDGE_ROOT:?}"; next }
    /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
    { print }
    END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/btcpay-bridge.php
! compose exec -T web php /var/www/html/yii btcpay-bridge/sync >/dev/null 2>&1
printf '%s\n' 'PASS: Bridge sync refuses to run without --once.'
[ "$(compose exec -T web php /var/www/html/yii btcpay-bridge/sync --once)" = 'disabled' ]
printf '%s\n' 'PASS: Bridge sync without the bridge key is disabled, no network.'
compose run --rm --no-deps bootstrap | grep -q already-complete
printf '%s\n' 'PASS: bootstrap remains a no-op after bridge probes.'
TESTS
sh "$private/runtime.sh"

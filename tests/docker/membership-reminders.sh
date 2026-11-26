#!/bin/sh
# SIP-030 punto 2: promemoria della quota (automatico, manuale, scelta del socio) su DB runtime usa e getta, mail su file.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_MEMBERSHIP_REMINDERS_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_MEMBERSHIP_REMINDERS_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/membership-reminders.php
# comandi one-shot del container cron: senza --once non fanno nulla
for command in membership/send-reminders membership/purge-unverified; do
    if compose exec -T web php /var/www/html/yii $command >/dev/null 2>&1; then echo "FAIL: $command without --once"; exit 1; fi
done
compose exec -T web php /var/www/html/yii membership/send-reminders --once | grep -qx 'sent=0'
printf '%s\n' 'PASS: reminder command runs only with --once and is idempotent.'
TESTS
sh "$private/runtime.sh"

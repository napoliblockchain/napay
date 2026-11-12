#!/bin/sh
# R2 application regressions in a disposable restored database.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_EMAIL_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_EMAIL_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
compose exec -T web php /var/www/html/tests/application/email-identity.php
compose exec -T web php /var/www/html/tests/application/password-reset.php
compose exec -T web php /var/www/html/tests/application/activation.php
compose exec -T web php /var/www/html/tests/application/account-eligibility.php
compose run --rm --no-deps bootstrap | grep -q already-complete
printf '%s\n' 'PASS: bootstrap remains a no-op after R2 email regressions.'
TESTS
sh "$private/runtime.sh"

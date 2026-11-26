#!/bin/sh
# SIP-033: BTC payment constraints and actual role enforcement, disposable MySQL only.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_ROLES_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_ROLES_ROOT:?}"; next }
 /^compose up -d --no-build --wait / { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
cat >> "$work/override.yml" <<YAML
  bootstrap:
    volumes:
      - type: bind
        source: $work/root-password
        target: /run/secrets/db-role-test-root
        read_only: true
        bind:
          create_host_path: false
YAML
compose up -d --no-build --wait --wait-timeout 240 web
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/fixture.php
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/pos-btc-schema.php
compose run --rm --no-deps --entrypoint php bootstrap /app/tests/application/database-roles.php
compose exec -T web sh -c 'test ! -e /run/secrets/db-role-test-root'
printf '%s\n' 'PASS: role administration secret never mounted on web.'
compose run --rm --no-deps bootstrap | grep -q already-complete
printf '%s\n' 'PASS: bootstrap remains a no-op after role tests.'
TESTS
sh "$private/runtime.sh"

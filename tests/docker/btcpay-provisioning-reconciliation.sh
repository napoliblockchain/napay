#!/bin/sh
# Reuse the actual narrow-worker fixture setup; no shared resources or HTTP.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_RECONCILIATION_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_RECONCILIATION_ROOT:?}"; next }
 { sub("tests/application/btcpay-provisioning-jobs.php", "tests/application/btcpay-provisioning-reconciliation.php"); print }
' "$root/tests/docker/btcpay-provisioning-jobs.sh" > "$private/runner.sh"
sh "$private/runner.sh"

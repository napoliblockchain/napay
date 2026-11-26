#!/bin/sh
# SIP-022 B1 W4b: outbox dell'attivazione merchant con l'overlay ruoli reale (utente web ristretto), solo stack usa-e-getta.
# Copia di roles-bootstrap.sh (suite stores) con il test outbox-attivazione e il digest pubblico dell'istanza nel container web.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_W4B_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
instance=$(openssl rand -hex 32 | tr -d '\n' | openssl dgst -sha256 -r | cut -c1-64)
awk -v instance="$instance" '
 /^root=/ { print "root=${NAPAY_W4B_ROOT:?}"; next }
 { gsub(/stores-without-btcpay\.php/, "btcpay-provisioning-outbox-activation.php") }
 { print }
 /^  web:$/ && !done { print "    environment:"; print "      BTCPAY_PROVISION_INSTANCE: \"" instance "\""; done=1 }
 END { if (!done) exit 1 }
' "$root/tests/docker/roles-bootstrap.sh" > "$private/roles.sh"
grep -q 'btcpay-provisioning-outbox-activation.php' "$private/roles.sh"
NAPAY_ROLE_TEST_SUITE=stores sh "$private/roles.sh"

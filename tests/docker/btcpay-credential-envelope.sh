#!/bin/sh
# SIP-022 B1a: test puri della busta credenziali sul runtime Napay esistente (PHP 8.3.36 con sodium).
# Nessuna rete, filesystem in sola lettura, nessun DB, ambiente o segreto esterno.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
image=${NAPAY_TEST_IMAGE:-napay/napay:dev}
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root:/app:ro" -w /app --entrypoint php "$image" -l components/btcpay/provisioning/CredentialEnvelope.php >/dev/null
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root:/app:ro" -w /app --entrypoint php "$image" tests/application/btcpay-credential-envelope.php

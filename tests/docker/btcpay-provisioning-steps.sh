#!/bin/sh
# SIP-022 B1 W2: test puri dei passi remoti con client HTTP finto, sul runtime Napay (PHP 8.3.36).
# Nessuna rete, filesystem in sola lettura, nessun DB, ambiente o segreto esterno.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
image=${NAPAY_TEST_IMAGE:-napay/napay:dev}
for file in StepOutcome MerchantAccountStep StoreLinkStep PosLinkStep; do
    docker run --rm -u "$(id -u):$(id -g)" --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
        -v "$root:/app:ro" -w /app --entrypoint php "$image" -l "components/btcpay/provisioning/steps/$file.php" >/dev/null
done
docker run --rm -u "$(id -u):$(id -g)" --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root:/app:ro" -w /app --entrypoint php "$image" tests/application/btcpay-provisioning-steps.php

#!/bin/sh
# Flash come toast: test puro del widget sul runtime Napay (PHP 8.3.36). Nessuna rete, sorgenti in sola lettura.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
image=${NAPAY_TEST_IMAGE:-napay/napay:dev}
docker run --rm -u "$(id -u):$(id -g)" --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=16m --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root:/app:ro" -w /app --entrypoint php "$image" tests/application/flash-toast.php

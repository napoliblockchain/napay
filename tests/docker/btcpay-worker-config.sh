#!/bin/sh
# PHP/CLI only, no network, DB, real secrets or application runtime changes.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
umask 077
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
trap 'exit 1' HUP INT TERM
docker run --rm --network none --read-only --user "$(id -u):$(id -g)" --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /tmp:mode=1777 -v "$root:/app:ro" -v "$work:/run/secrets" -w /app \
    --entrypoint php napay/napay:dev tests/application/btcpay-worker-config.php

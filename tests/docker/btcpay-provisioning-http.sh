#!/bin/sh
# SIP-022 B1: client HTTP del provisioning contro controfigure locali (server HTTPS e proxy CONNECT nello stesso
# container). Nessuna rete, filesystem in sola lettura, CA e certificati usa-e-getta, credenziali fittizie.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
image=${NAPAY_TEST_IMAGE:-napay/napay:dev}
umask 077
certs=$(mktemp -d)
trap 'rm -rf "$certs"' EXIT
trap 'exit 1' HUP INT TERM
issue() { # nome-CA nome-certificato DNS...
    [ -f "$certs/$1.key" ] || openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=napay test $1" \
        -keyout "$certs/$1.key" -out "$certs/$1.pem" >/dev/null 2>&1
    ca=$1; name=$2; shift 2
    san=$(printf 'DNS:%s,' "$@"); san=${san%,}
    openssl req -newkey rsa:2048 -nodes -subj "/CN=$1" -keyout "$certs/$name.key" -out "$certs/$name.csr" >/dev/null 2>&1
    printf 'subjectAltName=%s\n' "$san" > "$certs/$name.ext"
    openssl x509 -req -in "$certs/$name.csr" -CA "$certs/$ca.pem" -CAkey "$certs/$ca.key" -CAcreateserial -days 1 \
        -extfile "$certs/$name.ext" -out "$certs/$name.pem" >/dev/null 2>&1
}
issue ca server btcpay.test slow.test
issue rogue-ca rogue rogue.test
# Cartella 0700 e file 0600: il container gira con uid/gid dell'host, senza allargare permessi né DAC_OVERRIDE.
chmod 0700 "$certs"; chmod 0600 "$certs"/*
docker run --rm -u "$(id -u):$(id -g)" --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=64m --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root:/app:ro" -v "$certs:/certs:ro" -w /app --entrypoint php "$image" -l components/btcpay/provisioning/ProvisioningHttpClient.php >/dev/null
docker run --rm -u "$(id -u):$(id -g)" --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=64m --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root:/app:ro" -v "$certs:/certs:ro" -w /app --entrypoint php "$image" tests/application/btcpay-provisioning-http.php

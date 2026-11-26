#!/usr/bin/env bash
# SIP-036: inoltro SMTP di produzione (docker-compose.mail.yml) su reti usa-e-getta senza internet. Server TLS finto con
# certificato di una CA di test per il nome del server di posta; client (immagine napay) solo sulla rete egress come web e
# cron: raggiunge il server per nome con certificato verificato attraverso l'inoltro, nient'altro.
set -euo pipefail
umask 077
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
client_image=${SMTP_TEST_CLIENT_IMAGE:-napay/napay:dev}
run=$(openssl rand -hex 4)
work=$(mktemp -d)
project="napay-smtp-test-$run"
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
compose() { docker compose -p "$project" --project-directory "$root" --env-file "$work/test.env" -f "$root/docker-compose.mail.yml" -f "$work/test.yml" "$@"; }
cleanup() { code=$?; trap - EXIT; compose down -v --timeout 5 >/dev/null 2>&1 || true; rm -rf "$work"; exit "$code"; }
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

# CA e certificato di test per il nome del server di posta
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=napay-smtp-test-ca' -keyout "$work/ca.key" -out "$work/ca.crt" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj '/CN=smtp.example.test' -keyout "$work/srv.key" -out "$work/srv.csr" 2>/dev/null
printf 'subjectAltName=DNS:smtp.example.test\n' > "$work/san.ext"
openssl x509 -req -in "$work/srv.csr" -CA "$work/ca.crt" -CAkey "$work/ca.key" -CAcreateserial -days 1 -extfile "$work/san.ext" -out "$work/srv.crt" 2>/dev/null
chmod 644 "$work/ca.crt" "$work/srv.crt" "$work/srv.key"
cat > "$work/stub.conf" <<'NGINX'
events {}
http { server { listen 465 ssl; ssl_certificate /stub/srv.crt; ssl_certificate_key /stub/srv.key; location / { return 200 "smtp-stub"; } } }
NGINX
chmod 644 "$work/stub.conf"
cat > "$work/test.env" <<ENV
MAIL_SMTP_HOST=smtp.example.test
MAIL_SMTP_PORT=465
NAPAY_SMTP_RESOLVER=127.0.0.11
NAPAY_SMTP_UPSTREAM=smtp-stub-$run
ENV
cat > "$work/test.yml" <<YAML
services:
  smtp-stub:
    image: nginx:1.31.0-alpine
    command: [nginx, -g, "daemon off;", -c, /stub/stub.conf]
    volumes: ["$work:/stub:ro"]
    networks:
      internet:
        aliases: [smtp-stub-$run]
  client:
    image: $client_image
    entrypoint: [sleep, "600"]
    volumes: ["$work/ca.crt:/ca.crt:ro"]
    networks: [egress]
networks:
  egress:
    internal: true
  # nel test anche la rete "internet" è interna: nessuna uscita reale
  internet:
    internal: true
YAML

compose up -d --wait --wait-timeout 60 smtp-egress smtp-stub client >/dev/null
call() { compose exec -T client curl -s --max-time 10 --cacert /ca.crt -o /dev/stdout -w ' %{http_code}' "$@" 2>/dev/null || true; }
[[ $(call https://smtp.example.test:465/) == 'smtp-stub 200' ]] || fail 'mail server through the relay'
pass 'Client on the egress network reaches the mail server by name through the relay, certificate verified end to end'
[[ $(compose exec -T client curl -s --max-time 10 -o /dev/null -w '%{http_code}' --cacert /ca.crt --resolve "wrong.example.test:465:$(compose exec -T client getent hosts smtp.example.test | cut -d' ' -f1)" https://wrong.example.test:465/ 2>/dev/null || true) != 200 ]] \
    || fail 'TLS name not verified'
pass 'A different TLS name is refused: the relay never terminates or rewrites TLS'
[[ $(call https://smtp-stub-$run:465/) != 'smtp-stub 200' ]] || fail 'stub reachable directly'
[[ $(compose exec -T client sh -c 'getent hosts api-m.paypal.com example.com' 2>/dev/null | wc -l) == 0 ]] || fail 'other names resolvable'
pass 'From the egress network the mail server is reachable only through the relay; no other destination'
[[ -z $(docker inspect --format '{{range $p, $b := .NetworkSettings.Ports}}{{if $b}}{{$p}}{{end}}{{end}}' "$(compose ps -q smtp-egress)") ]] \
    && [[ $(docker inspect --format '{{.Config.User}} {{.HostConfig.ReadonlyRootfs}} {{.HostConfig.CapDrop}}' "$(compose ps -q smtp-egress)") == '101:101 true [ALL]' ]] || fail 'relay hardening'
pass 'Relay without host ports, non-root, read-only root, no capabilities'
printf 'PASS %s SMTP egress checks\n' "$checks"

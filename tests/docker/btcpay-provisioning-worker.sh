#!/bin/sh
# SIP-022 B1 W3b: esecutore merchant_account su un progetto runtime usa-e-getta; mai sandbox condivisa.
# Utente worker usa-e-getta con la matrice GRANT di B1b (tests/docker/btcpay-provisioning-schema.sh).
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
export NAPAY_B1C_ROOT="$root"
umask 077
private=$(mktemp -d)
trap 'rm -rf "$private"' EXIT
trap 'exit 1' HUP INT TERM
awk '
 /^root=/ { print "root=${NAPAY_B1C_ROOT:?}"; next }
 /^compose run --rm --no-deps --entrypoint php bootstrap .*users-access.php$/ { found=1; exit }
 { print }
 END { if (!found) exit 1 }
' "$root/tests/docker/runtime.sh" > "$private/runtime.sh"
cat >> "$private/runtime.sh" <<'TESTS'
worker_pw=$(openssl rand -hex 24)
# Password solo su stdin (printf è un builtin): mai negli argomenti dei processi.
{
printf "CREATE USER 'napay_b1b_worker'@'%%' IDENTIFIED BY '%s';\n" "$worker_pw"
cat <<'SQL'
GRANT SELECT, INSERT, UPDATE ON btcpay_merchant_accounts TO 'napay_b1b_worker'@'%';
GRANT SELECT, INSERT, UPDATE ON btcpay_merchant_credentials TO 'napay_b1b_worker'@'%';
GRANT SELECT, INSERT, UPDATE ON btcpay_store_links TO 'napay_b1b_worker'@'%';
GRANT SELECT, INSERT, UPDATE ON btcpay_pos_links TO 'napay_b1b_worker'@'%';
GRANT SELECT, INSERT, UPDATE ON btcpay_provisioning_jobs TO 'napay_b1b_worker'@'%';
GRANT SELECT (id, email, is_active, privilege_id) ON users TO 'napay_b1b_worker'@'%';
GRANT SELECT (id, level) ON privileges TO 'napay_b1b_worker'@'%';
GRANT SELECT (user_id, is_corporate, denomination) ON member_profiles TO 'napay_b1b_worker'@'%';
GRANT SELECT (user_id) ON membership_payments TO 'napay_b1b_worker'@'%';
GRANT SELECT (user_id) ON pos_operators TO 'napay_b1b_worker'@'%';
GRANT SELECT (id, user_id) ON merchants TO 'napay_b1b_worker'@'%';
GRANT SELECT (id, merchant_id) ON stores TO 'napay_b1b_worker'@'%';
GRANT SELECT (id, store_id, merchant_id) ON pos_terminals TO 'napay_b1b_worker'@'%';
GRANT INSERT ON logs TO 'napay_b1b_worker'@'%';
SQL
} | compose exec -T mysql sh -c '
    set -eu; umask 077
    o=$(mktemp); trap "rm -f \"\$o\"" EXIT
    printf "[client]\nuser=root\npassword=\"%s\"\nprotocol=socket\n" "$(cat "$MYSQL_ROOT_PASSWORD_FILE")" > "$o"
    mysql --defaults-extra-file="$o" --batch "$MYSQL_DATABASE"
'
printf '%s\n' "$worker_pw" | compose exec -T web php /var/www/html/tests/application/btcpay-provisioning-worker.php
unset worker_pw
compose run --rm --no-deps bootstrap | grep -q already-complete
printf '%s\n' 'PASS: bootstrap remains a no-op after W3b probes.'
TESTS
sh "$private/runtime.sh"

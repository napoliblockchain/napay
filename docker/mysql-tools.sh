#!/bin/sh
# Run only inside the dedicated MySQL container, through docker compose exec -T.
set -eu
umask 077

fail() { printf '%s\n' "$1" >&2; exit 1; }
action=${1:-}
case "$action" in
    health|inventory|backup) [ "$#" -eq 1 ] || fail 'Unexpected arguments.' ;;
    restore) [ "$#" -eq 2 ] && [ "$2" = '--confirm-empty-target' ] || fail 'Restore requires --confirm-empty-target.' ;;
    *) fail 'Usage: mysql-tools.sh health|inventory|backup|restore --confirm-empty-target' ;;
esac

database=${MYSQL_DATABASE:?Missing MYSQL_DATABASE}
case "$database" in ''|*[!a-zA-Z0-9_]*) fail 'Database name must contain only ASCII letters, digits, underscore.' ;; esac
secret=${MYSQL_ROOT_PASSWORD_FILE:?Missing MYSQL_ROOT_PASSWORD_FILE}
[ -r "$secret" ] || fail 'Root secret unavailable.'
password=$(cat "$secret")
[ -n "$password" ] || fail 'Empty root secret.'
# A quoted MySQL option file avoids credentials in process arguments.
case "$password" in *'
'*) fail 'Root secret must be single-line.' ;; esac
options=$(mktemp)
trap 'rm -f "$options"' EXIT HUP INT TERM
escaped=$(printf '%s' "$password" | sed 's/\\/\\\\/g; s/"/\\"/g')
printf '[client]\nuser=root\npassword="%s"\nprotocol=socket\n' "$escaped" > "$options"
unset password escaped
query() { mysql --defaults-extra-file="$options" --batch --skip-column-names "$database" -e "$1"; }

case "$action" in
    health)
        # The image's initialization server is socket-only: wait for final server.
        mysql --defaults-extra-file="$options" --protocol=TCP --host=127.0.0.1 \
            --batch --skip-column-names "$database" -e 'SELECT 1' >/dev/null
        ;;
    inventory)
        query 'SELECT VERSION(), @@version_comment, @@sql_mode, @@character_set_server, @@collation_server;'
        query "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='$database' ORDER BY TABLE_NAME;"
        ;;
    backup)
        mysqldump --defaults-extra-file="$options" --single-transaction --quick \
            --routines --events --triggers --hex-blob --no-tablespaces \
            --set-gtid-purged=OFF --column-statistics=0 "$database"
        ;;
    restore)
        # Include views, routines and events: a target with any object is not empty.
        count=$(query "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$database') + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$database') + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$database');")
        [ "$count" = 0 ] || fail 'Restore refused: target database is not empty.'
        mysql --defaults-extra-file="$options" --binary-mode=1 "$database"
        ;;
esac

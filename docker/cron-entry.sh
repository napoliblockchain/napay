#!/usr/bin/env bash
# Container cron di Napay: comandi one-shot (--once) a orari fissi. Crontab costruito dalle variabili d'ambiente
# (CRON_ENABLE_<JOB>=0|1, CRON_<JOB>_SCHEDULE); job eseguiti come www-data, mai sovrapposti (flock), un log per job.
set -euo pipefail

ROOT=/var/www/html
CRON_TZ="${CRON_TZ:-Europe/Rome}"
LOCKS="$ROOT/runtime/locks"
LOGS="$ROOT/runtime/logs"
ENV_FILE=/run/napay-cron/env
TMP_CRON=/run/napay-cron/crontab

for command_name in cron crontab flock php; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "[cron] missing command: $command_name (rebuild the napay image)" >&2; exit 127; }
done

php "$ROOT/docker/bootstrap-check.php"
install -d -o 33 -g 33 -m 0700 "$ROOT/runtime" "$LOCKS" "$LOGS"
install -d -o 0 -g 33 -m 0750 /run/napay-cron

# il demone cron non eredita l'ambiente del container: file leggibile solo da www-data, in tmpfs, mai nei log
umask 077
: > "$ENV_FILE"
while IFS='=' read -r -d '' name value; do
    case "$name" in HOSTNAME|HOME|PWD|SHLVL|_|TERM|CRON_*) continue ;; esac
    [[ "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    printf 'export %s=%q\n' "$name" "$value" >> "$ENV_FILE"
done < <(env -0)
chown 33:33 "$ENV_FILE"
chmod 0400 "$ENV_FILE"

{
    echo "SHELL=/bin/bash"
    echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
    echo "CRON_TZ=$CRON_TZ"
} > "$TMP_CRON"

JOBS=()
register_job() {
    local enabled="$1" schedule="$2" command="$3" label="$4"
    if [[ "$enabled" != "1" ]]; then
        echo "[cron] disabled: $label"
        return
    fi
    echo "$schedule . $ENV_FILE && cd $ROOT && flock -n $LOCKS/$label.lock $command >> $LOGS/cron-$label.log 2>&1" >> "$TMP_CRON"
    install -o 33 -g 33 -m 0600 /dev/null "$LOGS/cron-$label.log"
    JOBS+=("$LOGS/cron-$label.log")
    echo "[cron] enabled: $label ($schedule)"
}

# SIP-030: primo promemoria della quota, 30 giorni prima della scadenza
register_job "${CRON_ENABLE_FEE_REMINDERS:-1}" "${CRON_FEE_REMINDERS_SCHEDULE:-0 9 * * *}" \
    "php yii membership/send-reminders --once" fee-reminders
# SIP-030: account registrati dal sito e mai verificati da oltre 7 giorni
register_job "${CRON_ENABLE_PURGE_UNVERIFIED:-1}" "${CRON_PURGE_UNVERIFIED_SCHEDULE:-30 3 * * *}" \
    "php yii membership/purge-unverified --once" purge-unverified
# SIP-021: richieste di pagamento TTS scadute, verificate sulla chain e chiuse
register_job "${CRON_ENABLE_TTS_SWEEP:-1}" "${CRON_TTS_SWEEP_SCHEDULE:-*/5 * * * *}" \
    "php yii tts-payments/sweep --once" tts-sweep
# SIP-033: pagamenti BTC del POS oltre la scadenza (stato dal server BTCPay, creazioni incerte chiuse)
register_job "${CRON_ENABLE_BTC_SWEEP:-1}" "${CRON_BTC_SWEEP_SCHEDULE:-*/5 * * * *}" \
    "php yii btc-payments/sweep --once" btc-sweep

crontab -u www-data "$TMP_CRON"
echo "[cron] ${#JOBS[@]} job(s) loaded (TZ=$CRON_TZ)."

cleanup() {
    [[ -n "${CRON_PID:-}" ]] && kill "$CRON_PID" 2>/dev/null || true
    [[ -n "${TAIL_PID:-}" ]] && kill "$TAIL_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

cron -f &
CRON_PID=$!
if [[ ${#JOBS[@]} -gt 0 ]]; then
    tail -n 0 -F "${JOBS[@]}" 2>/dev/null &
    TAIL_PID=$!
    wait -n "$CRON_PID" "$TAIL_PID"
else
    wait "$CRON_PID"
fi

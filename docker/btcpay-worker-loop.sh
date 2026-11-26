#!/bin/sh
# SIP-022 B5: worker di provisioning BTCPay. Nessun daemon PHP: a ogni passata i tre comandi one-shot del CLI dedicato
# (yii-btcpay), ciascuno in un processo nuovo, ripetuti finché trovano lavoro (massimo 25 per passata), poi pausa.
# Output solo conteggi; gli errori del CLI sono già generici (nessun segreto, percorso o trace).
#   BTCPAY_WORKER_INTERVAL  secondi tra le passate (5..3600, default 30)
#   BTCPAY_WORKER_PASSES    numero di passate, 0 = senza fine (solo per i test)
set -u
interval=${BTCPAY_WORKER_INTERVAL:-30}
passes=${BTCPAY_WORKER_PASSES:-0}
case "$interval$passes" in *[!0-9]*|'') echo 'BTCPay worker: invalid configuration.' >&2; exit 1 ;; esac
if [ "$interval" -lt 5 ] || [ "$interval" -gt 3600 ] || [ "$passes" -gt 1000 ]; then echo 'BTCPay worker: invalid configuration.' >&2; exit 1; fi
cli=${NAPAY_BTCPAY_CLI:-/app/yii-btcpay}
done_passes=0
while :; do
    for route in run links reconcile; do
        count=0
        while [ "$count" -lt 25 ]; do
            if ! out=$(php "$cli" "btcpay-provision/$route" --once 2>&1); then
                echo "BTCPay worker: $route failed." >&2
                break
            fi
            [ "$out" = job-processed ] || break
            count=$((count + 1))
        done
        [ "$count" -eq 0 ] || echo "BTCPay worker: $route processed $count"
    done
    done_passes=$((done_passes + 1))
    if [ "$passes" -gt 0 ] && [ "$done_passes" -ge "$passes" ]; then exit 0; fi
    sleep "$interval"
done

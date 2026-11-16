#!/bin/sh
# SIP-022 B5: ciclo del worker BTCPay con un CLI finto (nessun PHP, DB o rete). Verifica conteggi, limite per passata,
# errori generici, configurazione rifiutata e uscita dopo le passate richieste.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
loop="$root/docker/btcpay-worker-loop.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
checks=0
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
# php finto: "php CLI route --once"; risposte per comando da file FAKE_DIR/<route> (una riga consumata per chiamata, poi idle)
mkdir "$work/bin"
cat > "$work/bin/php" <<'PHP'
#!/bin/sh
route=${2#btcpay-provision/}
echo "$route $3" >> "$FAKE_DIR/calls"
[ -s "$FAKE_DIR/$route" ] || { echo idle; exit 0; }
line=$(head -n 1 "$FAKE_DIR/$route"); sed -i 1d "$FAKE_DIR/$route"
case "$line" in fail) echo 'BTCPay worker failed.' >&2; exit 1 ;; *) echo "$line" ;; esac
PHP
chmod 700 "$work/bin/php"
run_loop() { PATH="$work/bin:$PATH" FAKE_DIR="$work" NAPAY_BTCPAY_CLI=/fake/yii-btcpay BTCPAY_WORKER_INTERVAL=5 BTCPAY_WORKER_PASSES=1 "$@" sh "$loop"; }

printf 'job-processed\njob-processed\njob-processed\n' > "$work/run"
printf 'fail\n' > "$work/reconcile"
run_loop env > "$work/out" 2> "$work/err"
[ "$(cat "$work/out")" = 'BTCPay worker: run processed 3' ] && [ "$(cat "$work/err")" = 'BTCPay worker: reconcile failed.' ]
pass 'Counts processed jobs, reports a failed command generically and continues'
[ "$(cat "$work/calls")" = "$(printf 'run --once\nrun --once\nrun --once\nrun --once\nlinks --once\nreconcile --once')" ]
pass 'Only the three one-shot commands, always with --once, run until idle'

: > "$work/calls"
i=0; while [ $i -lt 30 ]; do echo job-processed; i=$((i + 1)); done > "$work/links"
run_loop env > "$work/out" 2>&1
[ "$(cat "$work/out")" = 'BTCPay worker: links processed 25' ] && [ "$(grep -c '^links' "$work/calls")" = 25 ] && [ "$(wc -l < "$work/links")" = 5 ]
pass 'At most 25 jobs per command per pass; the rest waits for the next pass'

for bad in 'BTCPAY_WORKER_INTERVAL=4' 'BTCPAY_WORKER_INTERVAL=3601' 'BTCPAY_WORKER_INTERVAL=x' 'BTCPAY_WORKER_PASSES=-1'; do
    : > "$work/calls"
    if run_loop env "$bad" > "$work/out" 2>&1; then exit 1; fi
    [ "$(cat "$work/out")" = 'BTCPay worker: invalid configuration.' ] && [ ! -s "$work/calls" ]
done
pass 'Invalid interval or passes refused before any command'

: > "$work/calls"; : > "$work/links"
run_loop env BTCPAY_WORKER_PASSES=2 > "$work/out" 2>&1
[ "$(wc -l < "$work/calls")" = 6 ] && [ ! -s "$work/out" ]
pass 'Idle passes print nothing and stop after the requested number of passes'
printf 'PASS %s BTCPay worker loop checks\n' "$checks"

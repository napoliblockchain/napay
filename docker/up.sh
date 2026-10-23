#!/usr/bin/env bash
# Validate private inputs before creating the persistent volume or starting services.
set -euo pipefail
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
[[ $# -le 2 && ( ${2:-} == '' || ${2:-} == --check ) ]] || { echo 'Usage: docker/up.sh [env-file] [--check]' >&2; exit 1; }
env_file=$(realpath -e -- "${1:-$root/.env}")
export NAPAY_ENV_FILE="$env_file"
compose=(docker compose --project-directory "$root" --env-file "$env_file" -f "$root/docker-compose.yml")
# The full Compose JSON contains secrets: never print it or write it to a log.
paths=$("${compose[@]}" config --format json | docker run --rm -i --network none --read-only --entrypoint php php:8.3.36-cli -r '
    $c=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    foreach([$c["secrets"]["mysql_root_password"]["file"],$c["services"]["bootstrap"]["volumes"],$c["volumes"]["mysql_data"]["name"],$c["services"]["bootstrap"]["user"],$c["services"]["bootstrap"]["environment"]["NAPAY_STACK_WAIT_SECONDS"]??"3600"] as $i=>$v){
        if($i===1){$v=array_values(array_filter($v,fn($m)=>$m["target"]==="/import"))[0]["source"];}
        if(!is_string($v)||strpbrk($v,"\r\n")!==false)exit(1);
        echo $v,"\n";
    }')
mapfile -t fields <<< "$paths"
[[ ${#fields[@]} == 5 && ${fields[2]} =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ && ${fields[3]} =~ ^([0-9]+):([0-9]+)$ ]] || exit 1
job_uid=${fields[3]%%:*}
secret=$(realpath -e -- "${fields[0]}")
dataset=$(realpath -e -- "${fields[1]}")
for path in "$secret" "$dataset"; do
    [[ $path != "$root" && $path != "$root/"* ]] || { echo 'MySQL root secret and import dataset must stay outside the repository.' >&2; exit 1; }
done
for path in "$env_file" "$secret" "$dataset" "$dataset/manifest.json" "$dataset/napay-v2.sql"; do
    [[ -e $path && ! -L $path ]] || { echo 'Required private input unavailable or symlinked.' >&2; exit 1; }
    mode=$(stat -c '%a' -- "$path")
    (( (8#$mode & 077) == 0 )) || { echo 'Private configuration/dataset requires owner-only permissions.' >&2; exit 1; }
done
for path in "$dataset" "$dataset/manifest.json" "$dataset/napay-v2.sql"; do
    [[ $(stat -c '%u' -- "$path") == "$job_uid" ]] || { echo 'Bootstrap UID must match private dataset owner.' >&2; exit 1; }
done
budget=${fields[4]}
[[ $budget =~ ^[0-9]+$ ]] && (( budget >= 1 && budget <= 86400 )) || exit 1
if [[ ${2:-} == --check ]]; then echo 'Napay preflight OK; no services started.'; exit 0; fi
if ! docker volume inspect "${fields[2]}" >/dev/null 2>&1; then
    docker volume create --label napay.service=database "${fields[2]}" >/dev/null
fi
"${compose[@]}" up -d --build --wait --wait-timeout "$budget"

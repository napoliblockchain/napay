#!/usr/bin/env bash
# Validate all role-separated private inputs before creating volumes or starting services.
set -euo pipefail
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
source "$root/docker/private-inputs.sh"
[[ ! -e $root/.env && ! -L $root/.env ]] || { echo 'Role separation requires an external environment file, not repository .env.' >&2; exit 1; }
[[ $# -ge 1 && $# -le 2 && ( ${2:-} == '' || ${2:-} == --check ) ]] || { echo 'Usage: docker/up.sh env-file [--check]' >&2; exit 1; }
env_file=$1
napay_private_input "$env_file" file
export NAPAY_ENV_FILE="$env_file"
compose=(docker compose --project-directory "$root" --env-file "$env_file" -f "$root/docker-compose.yml" -f "$root/docker-compose.roles.yml")
paths=$("${compose[@]}" --profile db-check config --format json | docker run --rm -i --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root/docker/role-config.php:/check.php:ro" --entrypoint php php:8.3.36-cli /check.php)
mapfile -t fields <<< "$paths"
[[ ${#fields[@]} == 7 && ${fields[2]} =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ && ${fields[3]} =~ ^([0-9]+):([0-9]+)$ ]] || exit 1
job_uid=${fields[3]%%:*}; job_gid=${fields[3]##*:}
napay_private_input "$env_file" file "$job_uid"
for path in "${fields[0]}" "${fields[5]}" "${fields[6]}"; do napay_private_input "$path" file "$job_uid"; done
napay_private_input "${fields[1]}" directory "$job_uid"
for name in manifest.json napay-v2.sql; do napay_private_input "${fields[1]}/$name" file "$job_uid"; done
[[ ${fields[0]} != "${fields[5]}" && ${fields[0]} != "${fields[6]}" && ${fields[5]} != "${fields[6]}" ]] || { echo 'Role secrets require distinct files.' >&2; exit 1; }
# Reuse runtime secret validation without exposing values in argv, stdout or environment.
"${compose[@]}" --profile db-check config --format json | docker run --rm -i --network none --read-only --user "$job_uid:$job_gid" --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$root/components/db/DatabaseSecret.php:/check/components/db/DatabaseSecret.php:ro" \
    -v "${fields[0]}:/run/secrets/root:ro" -v "${fields[5]}:/run/secrets/migrator:ro" -v "${fields[6]}:/run/secrets/worker:ro" \
    --entrypoint php php:8.3.36-cli -r '
    try { require_once "/check/components/db/DatabaseSecret.php";
        $model = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $values = array_map([app\components\db\DatabaseSecret::class,"read"], ["/run/secrets/root","/run/secrets/migrator","/run/secrets/worker"]);
        $web = $model["services"]["web"]["environment"]["DB_PASSWORD"] ?? null;
        if (!is_string($web) || strlen($web) < 32 || strlen($web) > 256 || strpbrk($web,"\r\n\0") !== false) throw new RuntimeException();
        $values[] = $web;
        if (count(array_unique($values)) !== 4) throw new RuntimeException();
    } catch (Throwable $e) { fwrite(STDERR,"Invalid or reused role secret.\n"); exit(1); }
'
budget=${fields[4]}
[[ $budget =~ ^[0-9]+$ ]] && (( budget >= 1 && budget <= 86400 )) || exit 1
if [[ ${2:-} == --check ]]; then echo 'Napay role preflight OK; no services started.'; exit 0; fi
if ! docker volume inspect "${fields[2]}" >/dev/null 2>&1; then docker volume create --label napay.service=database "${fields[2]}" >/dev/null; fi
"${compose[@]}" stop web >/dev/null 2>&1
"${compose[@]}" up -d --build --wait --wait-timeout "$budget"

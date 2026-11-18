#!/usr/bin/env bash
# Shared host-side checks; never source an environment file as shell code.
napay_private_location() {
    local path=$1
    [[ $path == /* && $path != *$'\n'* && $path != *$'\r'* && $path == "$(realpath -m -- "$path")" ]] \
        || { echo 'Private inputs must use absolute canonical paths without symlinks.' >&2; return 1; }
    [[ $path != "$root" && $path != "$root/"* ]] \
        || { echo 'Private inputs must stay outside the repository.' >&2; return 1; }
}
napay_private_input() {
    local path=$1 type=$2 owner=${3:-} mode
    napay_private_location "$path" || return 1
    [[ ! -L $path && ( ( $type == directory && -d $path ) || ( $type != directory && -f $path ) ) ]] \
        || { echo 'Required private input missing or not a regular file/directory.' >&2; return 1; }
    mode=$(stat -c '%a' -- "$path")
    (( (8#$mode & 077) == 0 && (8#$mode & 0400) != 0 )) \
        || { echo 'Private inputs require owner-only read permissions.' >&2; return 1; }
    [[ $type != directory ]] || (( (8#$mode & 0100) != 0 )) || return 1
    [[ -z $owner || $(stat -c '%u' -- "$path") == "$owner" ]] \
        || { echo 'Private input owner must match the bootstrap UID.' >&2; return 1; }
}

#!/bin/sh
# Install only reviewed lock contents; expose no .env, dataset or source scripts.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
case "${1:-}" in
    '') mode=--no-dev ;;
    --dev) mode=--dev ;;
    *) echo 'Usage: docker/dependencies.sh [--dev]' >&2; exit 1 ;;
esac
[ "$#" -le 1 ] && [ -f "$root/composer.lock" ] || exit 1
# immagine con PHP ed estensioni del rilascio (docker/deploy.sh passa quella del commit); in locale napay/napay:dev
image=${NAPAY_DEPENDENCIES_IMAGE:-napay/napay:dev}
case $image in napay/napay:[A-Za-z0-9]*) ;; *) echo 'NAPAY_DEPENDENCIES_IMAGE must be a napay/napay image.' >&2; exit 1 ;; esac
# leggibile dai container (www-data, utente del bootstrap) anche se chi lancia ha umask 077
install -d -m 0755 "$root/vendor"
docker run --rm --user "$(id -u):$(id -g)" --cap-drop ALL \
    --security-opt no-new-privileges:true --read-only --tmpfs /tmp \
    -e COMPOSER_HOME=/tmp/composer \
    -v "$root/composer.json:/app/composer.json:ro" \
    -v "$root/composer.lock:/app/composer.lock:ro" \
    -v "$root/vendor:/app/vendor" -w /app \
    --entrypoint sh "$image" -c '
        set -eu
        composer validate --strict --no-check-publish --no-plugins --no-scripts
        # The sole allowlisted Yii installer registers extension aliases/bootstrap.
        # Application scripts remain disabled: no key generation or chmod hooks.
        composer install "$1" --prefer-dist --no-interaction --no-scripts --optimize-autoloader
        php -r '\''$e = is_file("vendor/yiisoft/extensions.php") ? require "vendor/yiisoft/extensions.php" : []; if (!isset($e["yiisoft/yii2-bootstrap5"]["bootstrap"])) { fwrite(STDERR, "Yii extension registration missing; repair vendor with the approved installer.\n"); exit(1); }'\''
        if [ "$1" = --no-dev ]; then
            composer check-platform-reqs --no-dev --no-plugins --no-scripts
        else
            composer check-platform-reqs --no-plugins --no-scripts
        fi
        composer audit --locked --no-plugins --no-scripts
    ' sh "$mode"

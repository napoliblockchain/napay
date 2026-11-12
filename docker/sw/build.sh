#!/bin/sh
# Rigenera web/sw.js e la libreria Workbox locale (web/workbox-v7.4.1/) da web/sw-src.js.
# Da lanciare dopo modifiche a sw-src.js o ai file in precache (docker/sw/workbox-config.cjs):
# il test tests/application/service-worker.php fallisce se sw.js non è aggiornato.
# Strumento di build, mai eseguito in produzione: npm audit segnala solo avvisi moderate/low di glob,
# minimatch e deepmerge (dipendenze di workbox-build, nessuna correzione compatibile); tmp forzato a 0.2.7.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm -e HOME=/tmp \
    -v "$root:/app" -w /app node:22.23.5-alpine3.24 sh -ec '
    cd docker/sw && npm ci --no-audit --no-fund --ignore-scripts --loglevel=error && cd /app
    rm -rf web/workbox-v7.4.1
    docker/sw/node_modules/.bin/workbox copyLibraries web/ >/dev/null
    # solo i moduli di produzione usati da sw-src.js: niente debug, source map o moduli inutilizzati
    find web/workbox-v7.4.1 -type f ! -name workbox-sw.js ! -name workbox-core.prod.js ! -name workbox-precaching.prod.js \
        ! -name workbox-routing.prod.js ! -name workbox-strategies.prod.js -delete
    docker/sw/node_modules/.bin/workbox injectManifest docker/sw/workbox-config.cjs
    rm -rf docker/sw/node_modules'

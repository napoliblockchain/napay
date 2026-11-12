<?php
// Service worker (TIP-020): web/sw.js generato con Workbox 7 da web/sw-src.js (docker/sw/build.sh).
// Precache minimo e aggiornato (revisioni = MD5 dei file), nessuna pagina dell'applicazione in cache.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function get($path) {
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '']);
    $raw = curl_exec($curl);
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $r;
}

$sw = get('/sw.js');
check($sw['status'] === 200 && preg_match('#^content-type:\s*(application|text)/javascript#mi', $sw['headers']), '/sw.js served as JavaScript');
check($sw['body'] === file_get_contents($root . '/web/sw.js'), 'Served sw.js is the generated file');
foreach (['workbox-sw.js', 'workbox-core.prod.js', 'workbox-precaching.prod.js', 'workbox-routing.prod.js', 'workbox-strategies.prod.js'] as $file) {
    check(get('/workbox-v7.4.1/' . $file)['status'] === 200, "Workbox runtime served: $file");
}
check(get('/workbox-sw.prod.v2.1.3.js')['status'] === 404, 'Old Workbox v2 removed');

// manifest iniettato da Workbox al posto di self.__WB_MANIFEST
check(preg_match('/precacheAndRoute\((\[.*?\])\)/s', $sw['body'], $m) === 1 && !str_contains($sw['body'], '__WB_MANIFEST'), 'Precache manifest injected');
$manifest = json_decode($m[1], true);
check(is_array($manifest) && count($manifest) >= 3, 'Precache manifest is valid JSON');
$allowed = '#^(offline\.html|manifest\.json|favicon\.ico|bundles/site/images/logo\.png|bundles/sw/src/images/icons/app-icon-\d+x\d+\.png)$#';
foreach ($manifest as $entry) {
    $file = $root . '/web/' . $entry['url'];
    check(preg_match($allowed, $entry['url']) === 1, 'Only static shell files precached: ' . $entry['url']);
    check(is_file($file) && md5_file($file) === $entry['revision'], 'Precache revision up to date (else run docker/sw/build.sh): ' . $entry['url']);
}
check(in_array('offline.html', array_column($manifest, 'url'), true) && get('/offline.html')['status'] === 200, 'Offline page precached and served');

// nessuna pagina in cache: navigazione solo dal server, niente cache dinamiche
$source = file_get_contents($root . '/web/sw-src.js');
check(str_contains($source, "request.mode === 'navigate'") && str_contains($source, 'NetworkOnly'), 'Navigations always go to the server');
check(!preg_match('/cache\.put|CacheFirst|StaleWhileRevalidate|NetworkFirst/', $source), 'No runtime caching of responses');
check(str_contains($source, 'target.origin !== self.location.origin'), 'Notification click opens only napay URLs');
check(str_contains(file_get_contents($root . '/web/bundles/sw/src/js/service.js'), "register('/sw.js')"), 'Page registers /sw.js');
echo "PASS {$checks} service worker checks\n";

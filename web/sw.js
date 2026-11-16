// Service worker napay (TIP-020), sorgente di web/sw.js: rigenerare con docker/sw/build.sh (Workbox 7).
// In cache SOLO i file statici del precache (offline.html, manifest, icone): le pagine dell'applicazione
// contengono dati personali e vanno sempre chieste al server; senza rete si mostra offline.html.
importScripts('/workbox-v7.4.1/workbox-sw.js');
workbox.setConfig({ modulePathPrefix: '/workbox-v7.4.1/', debug: false });

const OFFLINE_PAGE = '/offline.html';

workbox.precaching.cleanupOutdatedCaches();
workbox.precaching.precacheAndRoute([{"revision":"304778ec86f2b8983685c499e6878506","url":"offline.html"},{"revision":"c5e6bd402458e1205279aabe90cff871","url":"manifest.json"},{"revision":"76f84ad2a6a966f5b2e327f64fe772e5","url":"favicon.ico"},{"revision":"a0b4181155bcb76b3b0121d692be5138","url":"bundles/site/images/logo.png"},{"revision":"64171853c79d14b5e2dd6b08ba1c4a19","url":"brand/maskable.svg"},{"revision":"0f3d95519182cba44467ff225aaa17ce","url":"brand/maskable-512.png"},{"revision":"d027a1bd6e3fccbfc98558c9e2864bc3","url":"brand/maskable-192.png"},{"revision":"b4da0af9ccf2cd5b2cd6df2e5c728062","url":"brand/icon.svg"},{"revision":"431e4f3c386d19ad28849cb7c37c2dfd","url":"brand/icon-512.png"},{"revision":"5a05249d2221dfbc1fbeffa425b88dca","url":"brand/icon-192.png"},{"revision":"8c7227348bce989f29e38840faa853e2","url":"brand/apple-touch-icon.png"}]);

// pagine: sempre dal server, mai in cache; offline -> pagina di cortesia
workbox.routing.registerRoute(
    ({ request }) => request.mode === 'navigate',
    new workbox.strategies.NetworkOnly({
        plugins: [{ handlerDidError: async () => (await workbox.precaching.matchPrecache(OFFLINE_PAGE)) || Response.error() }],
    })
);

// aggiornamento richiesto dalla pagina ("nuova versione disponibile": web/bundles/sw/src/js/service.js)
self.addEventListener('message', (event) => {
    if (event.data && event.data.action === 'skipWaiting') self.skipWaiting();
});

// notifiche push
self.addEventListener('push', (event) => {
    let info = {};
    try {
        info = event.data ? event.data.json() : {};
    } catch (error) {
        return;
    }
    if (!info.title) return;
    event.waitUntil(self.registration.showNotification(String(info.title), {
        body: info.body, icon: info.icon, badge: info.badge, vibrate: info.vibrate, image: info.image,
        tag: info.tag, renotify: Boolean(info.tag), actions: info.actions, data: { openUrl: info.openUrl },
    }));
});

// clic sulla notifica: si apre solo un indirizzo di napay, mai un sito esterno
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    if (event.action === 'close') return;
    let target;
    try {
        target = new URL((event.notification.data && event.notification.data.openUrl) || '/', self.location.origin);
    } catch (error) {
        return;
    }
    if (target.origin !== self.location.origin) return;
    event.waitUntil(clients.openWindow(target.href));
});

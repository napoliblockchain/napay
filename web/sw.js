// Service worker napay (TIP-020), sorgente di web/sw.js: rigenerare con docker/sw/build.sh (Workbox 7).
// In cache SOLO i file statici del precache (offline.html, manifest, icone): le pagine dell'applicazione
// contengono dati personali e vanno sempre chieste al server; senza rete si mostra offline.html.
importScripts('/workbox-v7.4.1/workbox-sw.js');
workbox.setConfig({ modulePathPrefix: '/workbox-v7.4.1/', debug: false });

const OFFLINE_PAGE = '/offline.html';

workbox.precaching.cleanupOutdatedCaches();
workbox.precaching.precacheAndRoute([{"revision":"304778ec86f2b8983685c499e6878506","url":"offline.html"},{"revision":"c5e6bd402458e1205279aabe90cff871","url":"manifest.json"},{"revision":"d43493a6770a5116f57b1f8e490d5482","url":"favicon.ico"},{"revision":"a0b4181155bcb76b3b0121d692be5138","url":"bundles/site/images/logo.png"},{"revision":"eded4569d853f3cda9e2dd811526c351","url":"brand/maskable.svg"},{"revision":"f57cc704d51b7dc84250002916372dda","url":"brand/maskable-512.png"},{"revision":"101a19ebf3d922c0d549cf88b45d7198","url":"brand/maskable-192.png"},{"revision":"7056dd02237d87ef27396c5392c83d29","url":"brand/icon.svg"},{"revision":"d6f3fa6269abd1371e9019f8a8b8f71c","url":"brand/icon-512.png"},{"revision":"126ff1c9e3cd5121a3bd31a44764b6d8","url":"brand/icon-192.png"},{"revision":"5c31cea3945c55a16eb5f2380abce8af","url":"brand/apple-touch-icon.png"}]);

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

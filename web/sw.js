// Service worker napay (TIP-020), sorgente di web/sw.js: rigenerare con docker/sw/build.sh (Workbox 7).
// In cache SOLO i file statici del precache (offline.html, manifest, icone): le pagine dell'applicazione
// contengono dati personali e vanno sempre chieste al server; senza rete si mostra offline.html.
importScripts('/workbox-v7.4.1/workbox-sw.js');
workbox.setConfig({ modulePathPrefix: '/workbox-v7.4.1/', debug: false });

const OFFLINE_PAGE = '/offline.html';

workbox.precaching.cleanupOutdatedCaches();
workbox.precaching.precacheAndRoute([{"revision":"304778ec86f2b8983685c499e6878506","url":"offline.html"},{"revision":"4b77e8569a8c3a73c78567596f972d42","url":"manifest.json"},{"revision":"2d0edcab6b1cedb75f60214e9ee61edf","url":"favicon.ico"},{"revision":"a0b4181155bcb76b3b0121d692be5138","url":"bundles/site/images/logo.png"},{"revision":"a8b8880c3a5bee4f530db7bedc96d84d","url":"bundles/sw/src/images/icons/app-icon-96x96.png"},{"revision":"7c019a5a583d4581bd37a1605fc54541","url":"bundles/sw/src/images/icons/app-icon-512x512.png"},{"revision":"cfdf188ec0bafb180cbb47fc8aba5691","url":"bundles/sw/src/images/icons/app-icon-48x48.png"},{"revision":"5dcc8bb8e333a07ebc0bb1468b19e211","url":"bundles/sw/src/images/icons/app-icon-384x384.png"},{"revision":"5951f3f9804c15817f2b732365daf92c","url":"bundles/sw/src/images/icons/app-icon-256x256.png"},{"revision":"f173b93faef57d35136161c4b502edff","url":"bundles/sw/src/images/icons/app-icon-192x192.png"},{"revision":"050f3a6a8b9bce03fc8370f64a4484c5","url":"bundles/sw/src/images/icons/app-icon-144x144.png"}]);

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

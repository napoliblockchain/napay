// Service worker napay (TIP-020), sorgente di web/sw.js: rigenerare con docker/sw/build.sh (Workbox 7).
// In cache SOLO i file statici del precache (offline.html, manifest, icone): le pagine dell'applicazione
// contengono dati personali e vanno sempre chieste al server; senza rete si mostra offline.html.
importScripts('/workbox-v7.4.1/workbox-sw.js');
workbox.setConfig({ modulePathPrefix: '/workbox-v7.4.1/', debug: false });

const OFFLINE_PAGE = '/offline.html';

workbox.precaching.cleanupOutdatedCaches();
workbox.precaching.precacheAndRoute(self.__WB_MANIFEST);

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

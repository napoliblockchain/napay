// Precache minimo: solo i file per l'app installabile e la pagina offline (TIP-020).
// Mai pagine HTML dell'applicazione: contengono dati personali (vedi web/sw-src.js).
module.exports = {
  globDirectory: 'web/',
  globPatterns: [
    'offline.html',
    'manifest.json',
    'favicon.ico',
    'bundles/site/images/logo.png',
    'bundles/sw/src/images/icons/app-icon-*.png',
  ],
  swSrc: 'web/sw-src.js',
  swDest: 'web/sw.js',
  maximumFileSizeToCacheInBytes: 1024 * 1024,
};

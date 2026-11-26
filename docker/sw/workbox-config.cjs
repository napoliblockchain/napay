// Precache minimo: solo i file per l'app installabile e la pagina offline (TIP-020).
// Mai pagine HTML dell'applicazione: contengono dati personali (vedi web/sw-src.js).
module.exports = {
  globDirectory: 'web/',
  globPatterns: [
    'offline.html',
    'manifest.json',
    'favicon.ico',
    // icone dell'app (stile Napay, 2026-11-16)
    'brand/*.{png,svg}',
  ],
  swSrc: 'web/sw-src.js',
  swDest: 'web/sw.js',
  maximumFileSizeToCacheInBytes: 1024 * 1024,
};

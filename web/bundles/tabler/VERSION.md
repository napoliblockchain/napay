# Tabler core e Bootstrap locali

- Tabler core 1.6.1, CSS originale riusato dalla baseline Aura.
- Provenienza: `/var/www/levitas/aura/web/bundles/tabler`.
- Shasum archivio npm registrato da Aura: `eeca2b82f8be5200d97696aaad125a69fe0a57a8`.
- Licenza Tabler MIT: `LICENSE`.
- Bootstrap JavaScript 5.3.8: `bootstrap.bundle.min.js` originale dalla dipendenza
  `twbs/bootstrap` di Aura; licenza MIT in `BOOTSTRAP-LICENSE`.
- Il JS completo Tabler non viene caricato. `TablerPluginAsset` sostituisce il
  bundle Yii Bootstrap 5 per garantire una sola versione del JavaScript.
- Asset locali senza CDN. Aggiornare entrambe le versioni insieme e ripetere test
  form, modali, menu e grafo asset prima del rilascio.

## SHA-256 dei file distribuiti

- `css/tabler.min.css`: `370a2d4e608ae518351d10528fc7dbe19b354ae5062c51b2a9cb294850d65403`.
- `js/bootstrap.bundle.min.js`: `e4fd49181388c48ec5040bd3fe66f57c29c8e67fcd8502b3354b96ec7ab47cc7`.

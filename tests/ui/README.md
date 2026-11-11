# Pilota UI Tabler (SIP-018)

`layout.php` usa SQLite in memoria e valori fittizi. Carica le classi reali di
Napay, renderizza dashboard per i tre ruoli, menu, layout e un form fixture con
ActiveForm/Select2/DatePicker/Modal. Non esegue controller CRUD, login o pagamenti.
Eseguibile solo da CLI; nessuna route di test viene aggiunta all'applicazione.

Menu account in alto a destra: profilo con ID cifrato e logout POST;
nessun profilo nella sidebar, nessuna sezione Developer/Debug/Gii.
56 controlli layout, inclusi output encoding e menu per ruolo.
Il test HTTPS `tests/browser/local.cjs` verifica apertura dropdown e profilo
su desktop/mobile e logout reale. Moduli Debug/Gii non registrati in web/console
in nessun ambiente; configurazione e test HTTP verificano questa assenza.
Il merchant vede Azienda sotto Profilo; admin usa Aziende nella sidebar.
La suite browser su fixture copre anche questo dropdown (35 controlli).
`NAPAY_CHROME_PATH` permette di usare Chrome locale invece del Chromium Playwright.
Preservate le quattro voci associazione aggiunte in `8f1da057`, visibili solo
all'amministratore: non sono sostituite dalla navigazione Azienda.

```sh
php tests/ui/layout.php
node tests/ui/browser.cjs
```

Prerequisiti: PHP 8.3.36 con PDO SQLite e dipendenze Yii/Kartik/Hail compatibili;
Playwright 1.55.0 con Chromium disponibile come strumento di test. Non e una
nuova dipendenza runtime. In assenza del vendor completo Napay,
`NAPAY_TEST_VENDOR` puo indicare un vendor isolato compatibile, montato in sola
lettura: non copiarlo nel vendor dell'applicazione.

`NAPAY_UI_OUTPUT` sceglie la directory di output (default `/tmp/napay-ui-pilot`);
contiene solo fixture e asset pubblici, mai dati operativi. Il browser test avvia
un server statico temporaneo su loopback, verifica 375/768/1440 px, sidebar,
tema, stato del form, widget e modale; salva screenshot nella sottocartella
`screenshots`. `NAPAY_PLAYWRIGHT_MODULE` permette di usare il modulo Playwright
installato in un ambiente di test separato.

Questi test non sostituiscono smoke login/CRUD/API con il lock e il database di
test di Napay. Per l'intervento iniziale e stato montato il vendor Aura solo nel
container di test. Il vendor operativo Napay e rimasto invariato.

# Primo test web locale Napay

Sandbox persistente per browser, separata dal compose operativo e da poawallet.
Importa una copia del dataset v2 revisionato su `napay_browser_local_mysql_data`:
non modifica dump sorgente, volume operativo o identita storiche.
Contiene dati recuperati reali: resta accessibile solo dalla macchina locale.

## Avvio

```sh
NAPAY_IMPORT_DIR=/percorso/privato/dataset-v2 bash docker/local.sh up
bash docker/local.sh status
```

Prerequisiti: Docker Compose con `!reset`/`!override`, curl, OpenSSL, flock, vendor
Napay installato dal lock e dataset privato revisionato. Il primo avvio costruisce
le immagini PHP gia previste dal progetto; non cambia versione PHP.

- Browser: `https://localhost:18444`.
- HTTP: `http://localhost:18121`, solo redirect HTTPS.
- Porte diverse da gateway condiviso `18080`/`18443` e diagnostica `18120`.
- Username: `browser-admin`.
- Password casuale: `$HOME/.local/share/napay/browser-local/browser-password`.
- Configurazione privata: stessa directory, `local.env` (0600).
- CA pubblica locale: stessa directory, `caddy-root.crt`.

Non cancellare questi file per recuperare credenziali: il volume DB persiste.
Il provisioning verifica l'identita locale gia presente senza cambiarne password.
Nessuna password viene stampata nei log o salvata nel repository.

## HTTPS locale

Caddy genera e rinnova certificati automaticamente; CA e chiavi persistono nei
volumi della sandbox. Nessuna gestione manuale di certificati server.
Nel browser occorre fidarsi **una sola volta** della CA locale esportata,
nel proprio archivio di certificati: un container non puo installarla nel browser
host. Non disabilitare verifiche TLS globalmente e non importare chiavi private.
La procedura non cambia automaticamente la fiducia di sistema.

Verifica TLS senza bypass:

```sh
curl --cacert "$HOME/.local/share/napay/browser-local/caddy-root.crt" \
  https://localhost:18444/site/login --output /dev/null --fail
```

Backend Apache senza porte pubblicate; rete browser interna, separata da DB.
Web e DB senza rete con uscita Internet: chiamate wallet/pagamenti/API non funzionano
volutamente. Solo Caddy dispone anche della rete bridge d'ingresso necessaria a
pubblicare le porte loopback Docker. Apache dichiara HTTPS solo nell'overlay dedicato a questo gateway.
`HTTPS_ONLY=true` protegge cookie sessione, CSRF e identita con Secure,
HttpOnly e SameSite=Lax. Il compose diagnostico normale resta compatibile HTTP.
Nessun access log Caddy abilitato; Apache non registra query bearer.

## Mail su file

`MAIL_TRANSPORT=file` obbligatorio per provisioning locale. Nessun SMTP.
Registrazione/recupero salvano messaggi privati nel volume runtime persistente.

```sh
bash docker/local.sh mail
```

Messaggi copiati in `$HOME/.local/share/napay/browser-local/mail-review/`
(directory 0700, file 0600). Aprire `.eml` localmente e seguire link HTTPS.
I link contengono credenziali temporanee: non incollarli in chat/log pubblici.
Prima richiesta mail va generata tramite registrazione o recupero password.

## Test browser ripetibile

Con Playwright gia disponibile, senza aggiungere dipendenze PHP/JS al runtime:

```sh
PLAYWRIGHT_PACKAGE=/percorso/playwright/package \
  /percorso/node tests/browser/local.cjs
```

Chrome predefinito `/usr/bin/google-chrome`, personalizzabile con `CHROME_PATH`.
Il test verifica TLS con curl e CA, poi limita l'eccezione di fiducia del solo
browser di automazione alla chiave pubblica del certificato sandbox (SPKI pin).
Non usa `ignoreHTTPSErrors` o bypass TLS globale. Blocca richieste browser esterne.
Verifica login desktop/mobile, cookie, autorizzazione, registrazione, attivazione,
reset e replay, seguendo URL estratti dai messaggi file. Crea solo utenti locali
nuovi con suffisso casuale; non modifica account storici o password browser-admin.

## Arresto e limiti

```sh
bash docker/local.sh stop
bash docker/local.sh up
```

Stop/restart preservano DB, mail, asset e CA. Import segue lo stesso bootstrap
una tantum: completato = no-op; fallimento/interruzione = blocco, niente retry
distruttivo. Non usare `down -v` per manutenzione: perderebbe mail e CA;
il DB esterno richiede comunque backup separati.

Chiavi cifratura sandbox nuove, **non originali**: non certifica decifratura dei
dati storici, login utenti originali, gateway condiviso o integrazioni finanziarie.
Per collaudo operativo recuperare le chiavi originali; non sostituirle con queste.
La build PWA/Workbox resta separata: `/sw.js` assente non impedisce questi flussi,
ma installazione e funzionamento offline PWA non sono certificati.

## Verifiche eseguite

- 43 controlli configurazione (cookie HTTPS, compatibilita HTTP e assenza Debug/Gii in ogni ambiente).
- 25 controlli Compose sandbox (porte, reti, volume, mount e mail file).
- 27 controlli Chrome HTTPS desktop/mobile, inclusi menu account, profilo, logout e link reali dalle mail file.
- Regressioni HTTP: 113 runtime, 30 accessi record-level, 15 stato/log,
  68 password/mail e 86 registrazione/attivazione.
- 21 controlli dipendenze e 47 layout Yii.

Nessun SMTP reale, pagamento o chiamata wallet; nessuna modifica poawallet.

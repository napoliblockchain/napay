# Primo test web locale Napay

Sandbox persistente per browser, separata dal compose operativo e da poawallet.
Importa una copia del dataset v2 revisionato su `napay_browser_local_mysql_data`:
non modifica dump sorgente, volume operativo o identita storiche.
Contiene dati recuperati reali: resta accessibile solo dalla macchina locale.

## Avvio

Avvio di tutto lo stack (wallet, backoffice, gateway) dall'orchestratore:

```sh
NAPAY_IMPORT_DIR=/percorso/privato/dataset-v2 bash ../start-localhost.sh   # primo avvio
bash ../start-localhost.sh                                                 # avvii successivi
bash docker/local.sh status
```

`docker/local.sh up` avvia solo il sandbox: senza il gateway dell'orchestratore
non e raggiungibile dal browser.

Prerequisiti: Docker Compose con `!reset`/`!override`, curl, OpenSSL, flock, vendor
Napay installato dal lock e dataset privato revisionato. Il primo avvio costruisce
le immagini PHP gia previste dal progetto; non cambia versione PHP.

- Browser: `https://napay.local` (hosts: `127.0.0.1 napay.local`).
- HTTP: `http://napay.local`, solo redirect HTTPS; `localhost` reindirizza qui.
- Gateway `napay-gateway` dell'orchestratore su `127.0.0.1:80/443`; diagnostica `18120`.
- Email di accesso: `browser-admin@example.invalid` (login solo con email, SIP-020).
- Cartella privata: `NAPAY_LOCAL_STATE` (in locale `/var/www/documents/progetti/napoliblockchain/napay/ambiente-locale`, 0700; default storico `$HOME/.local/share/napay/browser-local`).
- Password casuale: `$NAPAY_LOCAL_STATE/browser-password`.
- Configurazione privata: stessa directory, `local.env` (0600).
- CA pubblica locale: stessa directory, `caddy-root.crt`.

Non cancellare questi file per recuperare credenziali: il volume DB persiste.
Il provisioning verifica l'identita locale gia presente senza cambiarne password.
Nessuna password viene stampata nei log o salvata nel repository.

## HTTPS locale

Caddy (`napay-gateway`) genera e rinnova certificati automaticamente; CA e chiavi
persistono nei suoi volumi. `start-localhost.sh` esporta la CA in `caddy-root.crt`. Nessuna gestione manuale di certificati server.
Nel browser occorre fidarsi **una sola volta** della CA locale esportata,
nel proprio archivio di certificati: un container non puo installarla nel browser
host. Non disabilitare verifiche TLS globalmente e non importare chiavi private.
La procedura non cambia automaticamente la fiducia di sistema.

Verifica TLS senza bypass:

```sh
curl --cacert "$NAPAY_LOCAL_STATE/caddy-root.crt" \
  https://napay.local/site/login --output /dev/null --fail
```

Backend Apache senza porte pubblicate; rete browser interna `napay_local_browser`,
separata da DB. Oltre a `napay-gateway`, la usa ora anche la preview POS v2;
nessun accesso diretto del POS alla rete MySQL.
Web e DB senza rete con uscita Internet: chiamate wallet/pagamenti/API non funzionano
volutamente. Solo il gateway dispone anche della rete bridge necessaria a
pubblicare le porte loopback Docker. Apache dichiara HTTPS solo nell'overlay locale.
`HTTPS_ONLY=true` protegge cookie sessione, CSRF e identita con Secure,
HttpOnly e SameSite=Lax. Il compose diagnostico normale resta compatibile HTTP.
Nessun access log Caddy abilitato; Apache non registra query bearer.

## Mail su file

`MAIL_TRANSPORT=file` obbligatorio per provisioning locale. Nessun SMTP.
Registrazione/recupero salvano messaggi privati nel volume runtime persistente.

```sh
bash docker/local.sh mail
```

Messaggi copiati in `$NAPAY_LOCAL_STATE/mail-review/`
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

Aggiornamento di chiusura menu/Azienda del 2026-11-11; test su stack temporaneo
e fixture anonime, senza modificare il database locale recuperato.

- 43 controlli configurazione (cookie HTTPS, compatibilita HTTP e assenza Debug/Gii in ogni ambiente).
- 20 controlli Compose sandbox corrente (porte, reti, volume, mount e mail file).
- 27 controlli Chrome HTTPS desktop/mobile eseguiti nel precedente collaudo, inclusi menu account, profilo, logout e link reali dalle mail file.
- Regressioni HTTP: 134 runtime, 46 proprieta/IVA azienda, 30 accessi record-level, 15 stato/log,
  68 password/mail, 92 registrazione/attivazione e 36 backoffice associazione.
- 21 controlli dipendenze, 56 layout Yii e 35 browser UI su fixture anonime.

Nessun SMTP reale, pagamento o chiamata wallet; nessuna modifica poawallet.

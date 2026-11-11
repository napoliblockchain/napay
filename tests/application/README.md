# Runtime Napay

Suite CLI/HTTP isolate: nessuna route di test aggiunta al sito.
`dependencies.php` verifica PHP/estensioni, autoload, registrazione estensioni
Yii/traduzioni Bootstrap e documenti XLSX/PDF fittizi. Nessun DB o rete.

```sh
docker run --rm --network none --read-only --tmpfs /tmp \
  -v "$PWD:/app:ro" -w /app --entrypoint php napay/napay:dev \
  tests/application/dependencies.php
NAPAY_TEST_DATASET=/percorso/dataset-v2 sh tests/docker/runtime.sh
```

Prerequisiti: immagini locali `napay/napay:dev` e `napay/bootstrap:dev`, vendor
Napay installato con `sh docker/dependencies.sh`, dataset v2 revisionato fuori
repository. Nessun vendor Aura richiesto. Dataset letto solo dal job bootstrap.

`fixture.php` richiede CLI, conferma `isolated-fixture` e DB esattamente
`napay_test_runtime`; verifica bootstrap prima di aggiungere utenti fittizi
(admin, base, altro utente, merchant, disabilitato/bloccato, livelli 40/60).
Non altera utenti originali. Credenziali/chiavi di
test non sono utilizzabili in produzione. Nessuna rotazione delle chiavi reali.

`http.php` gira nel web temporaneo contro Apache su loopback, non segue redirect
automaticamente e conserva cookie solo in file temporanei. Verifica login,
asset pubblicati, CSRF, password errata, stato utente, dashboard Tabler e accesso
per ruolo alle liste utenti/settings. Testa file privati e entrypoint test negati.
Non stampa HTML, dati copiati o credenziali. Sorgente montata read-only; asset e
runtime su tmpfs. Rete interna senza egress e nessuna porta host pubblicata.
Riavvio web e successivo bootstrap devono conservare DB e restare no-op.

## Esiti Del 2026-10-23

- Build PHP web/bootstrap 8.3.36 riuscite; Apache syntax OK.
- Composer validate strict, check-platform-reqs e audit lock riusciti, sia dev
  sia produzione; nessun advisory o pacchetto abbandonato segnalato.
- Prima installazione produzione ripetuta su vendor temporaneo completamente
  vuoto con lo script documentato: registrazione Yii e 21 controlli riusciti.
- 23 controlli configurazione, 21 dipendenze, 38 layout Yii e 26 browser
  (375/768/1440 px, widget e interazioni Tabler, fixture senza dati reali).
- 34 schema v2, 32 bootstrap + 2 Compose, con vendor proprio Napay.
- 36 controlli HTTP + riavvio/no-op, ripetuti con vendor produzione senza dev.
- Step utenti locali successivo: 30 record-level, suite HTTP ampliata a 76,
  15 stato/preservazione/log e riavvio/no-op. Regressioni ambiente/dipendenze/
  layout/schema/bootstrap ripetute con esito positivo.

## Limiti E Rischi Residui

Non e un'approvazione al cutover. Chiavi originali non usate: conservazione dei
byte cifrati verificata, decifratura e sessioni storiche no. Login provato con
utenti fittizi, non password originali. Reset password, posta reale, SSO,
CRUD completi e integrazioni finanziarie non coperti.

Il difetto preesistente `UsersController` e stato corretto nello step successivo:
filtro access unico, proprietario/livello verificati, mutazioni POST/CSRF,
stato account locale senza Cognito e rifiuto mass assignment. Aggiunti test
record-level, sessioni revocate, XSS e preservazione credenziali nei log.
Dettagli e limiti: [utenti locali](../../docker/USERS-AUTH.md).
Gli altri controller non sono coperti da questo audit record-level.

Codeception legacy riferisce `tests/_bootstrap.php` e `config/test.php` non
presenti: suite legacy non eseguita. Nessun PHPStan/PHPCS configurato.
Audit dipendenze non equivale a scansione CVE dell'intera immagine OS/PHP.

## Completamento Utenti Del 2026-10-24

Eliminato adapter SSO senza chiamanti, senza rimuovere dati identita.
Suite HTTP estesa a filtri/paginazione, scope utenti nei pannelli amministrativi,
export XLSX reale per amministratore e operatore livello 40, filtri alterati,
JSON malformato/oversize e formule Excel salvate come testo. Nessuna modifica
al dataset sorgente; nessun invio email o operazione finanziaria.

Esito finale 2026-10-24: 109 HTTP, 30 record-level, 15 stato/log, restart/no-op;
regressioni 23 configurazione, 21 dipendenze, 38 layout, 34 schema e 32 bootstrap
+ 2 Compose passate. Nessun DB operativo modificato. Suite legacy Codeception
non eseguita; nessun PHPStan/PHPCS configurato.

## Password Reset E Mail

`password-reset.php` gira nello stesso stack temporaneo dopo smoke utenti;
usa solo account fixture e `MAIL_TRANSPORT=file`. Verifica email HTML/testo,
origine Host alterata, permessi 0700/0600, token hash/TTL/monouso, consumo atomico
con istanze stale, password non valide, vecchie sessioni/API, utenti inattivi,
email duplicate, rate limit, rollback consegna fallita e log senza bearer.
Il runner controlla anche i log Apache prima del cleanup. Trasporto SMTP viene
costruito per verificarne TLS/timeout senza aprire connessioni. Nessun recapito
reale, test provider o modifica a utenti/dump operativi.

## Attivazione

`activation.php` completa registrazione/consensi tramite HTTP in DB temporaneo,
legge solo email file private e prova GET non mutante, POST/CSRF, replay, firme
errate, nonce scaduti/futuri, stati/provider non ammessi, ID alterati e consumo
atomico con istanze stale. Verifica login successivo e preservazione identita/
password/ruolo/consensi. Nessun invio reale, nessuna cancellazione automatica.

Esito attivazione 2026-10-24: 39 controlli registrazione/attivazione passati;
ripetute con successo suite 68 password/mail, 109 HTTP, 30 record-level,
15 stato/log, 33 configurazione, 21 dipendenze, 38 layout, 34 schema e
32 bootstrap + 2 Compose. Controllo log bearer e riavvio/no-op passati.

## Completamento Locale Recupero

Reinvio da login/link scaduto, recupero dopo consegna fallita, email duplicate,
account disabilitato pending, rate limit, validazione signup e uso dei link
estratti dalle mail `.eml` sono coperti dalla suite attivazione. Trasporto file
forzato; nessun SMTP reale. `EmailRequestForm` condivide i controlli fra reset e
reinvio; la suite password viene ripetuta per verificare assenza di regressioni.

Esito completamento locale: 86 registrazione/attivazione/reinvio, 68 password/
mail, 109 HTTP, 30 record-level, 15 stato/log; regressioni 33 configurazione,
21 dipendenze, 38 layout, 34 schema e 32 bootstrap + 2 Compose passate.
I percorsi positivi di recupero seguono link estratti dai messaggi file;
i casi negativi includono anche link costruiti/alterati dalla suite. Nessun SMTP.

## Azienda del Merchant

`merchants.php`, solo `napay_test_runtime`: 46 controlli record-level e scenario
update, con rollback finale. Owner e P.IVA invariati nonostante POST costruite
manualmente, sia admin sia merchant; pagina Azienda ricavata dall'identita,
nessun ID client. La suite HTTP sale a 134 controlli, incluse pagine admin
esistenti e flusso company GET/POST/CSRF/ruoli. Regressioni password/mail e
attivazione passate. Dettagli e limiti in `docker/MERCHANT-COMPANY.md`.

Collaudo finale 2026-11-11 dopo integrazione dell'altro agente: 134 HTTP,
46 proprieta/P.IVA, 30 utenti record-level, 15 stato/log, 68 password/mail,
92 registrazione/attivazione e 36 backoffice associazione. Riavvio con dati
preservati e bootstrap no-op verificati. Menu UI: 56 renderer e 35 browser.

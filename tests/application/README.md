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

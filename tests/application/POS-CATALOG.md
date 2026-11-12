# POS C1: schema e modelli

Fonte: orchestratore `sip/SIP-021-pos-login-operatori-apertura-esclusiva.md`,
sezione C1. Implementazione del 2026-11-12; nessun catalogo HTTP o backoffice
incluso. Sandbox condivisa gia migrata da un precedente bootstrap: schema e
storico verificati in sola lettura il 2026-11-12. Nessuna nuova migration
applicata alla sandbox durante la chiusura C1.

## File riservati all'agente POS/API

- `migrations/m261112_130000_create_pos_catalog.php`.
- `models/PosOperators.php`, `PosStoreOperators.php`, `PosTerminals.php`.
- `tests/application/pos-catalog-schema.php`.
- `tests/docker/pos-catalog.sh` (runner dedicato).

Non modificati: `PosApi.php`, `Throttle.php`, routing, runner runtime comune,
Users/Stores/Merchants e controller del backoffice. Nessuna dipendenza o
versione PHP aggiornata.

## Esecuzione isolata

```sh
NAPAY_TEST_DATASET=/percorso/assoluto/dataset-v2-revisionato \
  sh tests/docker/pos-catalog.sh
```

Richiede immagini locali `napay/napay:dev`, `napay/bootstrap:dev`, MySQL 8.4,
Docker Compose e vendor gia presenti, come la suite runtime esistente.
Il runner riusa il setup di `tests/docker/runtime.sh` fino alla fixture iniziale,
poi esegue C1, login/logout API e bootstrap ripetuto. Se cambia il confine della
fixture, termina senza inventare uno stack alternativo. Nessuna porta host;
rete e volume temporanei, credenziali casuali. Cleanup automatico solo delle
risorse di quella esecuzione. Il dataset sorgente e montato read-only.

La suite PHP rifiuta DB diversi da `napay_test_runtime` o privi della conferma
`NAPAY_TEST_DB_CONFIRM=isolated-fixture`. Le prove DROP/ALTER e le fixture
esistono solo in quel DB usa-e-getta; non sono comandi operativi di riparazione.
Due processi PHP con connessioni indipendenti verificano l'assegnazione
concorrente dello stesso utente a merchant diversi.

## Migrazione e limiti

Schema nuovo disabilitato per default, senza seed. Unica aggiunta a `stores`:
chiave candidata UNIQUE `(id, merchant_id)`; indice equivalente riusato,
collisione con definizione diversa rifiutata. FK nuove tutte RESTRICT,
CHECK applicati dal DB, UUID v4 generato nel modello e non mass-assignable.
Identita/appartenenze non riassegnabili tramite normale salvataggio AR.
Timestamp generati dal server; account o ruolo da soli non creano grant.

Preflight prima del DDL: engine/tipi parent e definizione delle tabelle C1 gia
presenti. Ogni CREATE TABLE contiene i suoi indici/FK/CHECK: dopo un'interruzione
si possono riusare solo oggetti verificati coerenti. Non si promette una
transazione atomica del DDL MySQL; niente DROP/riparazioni automatiche.
Rollback distruttivo rifiutato. La verifica CHECK conserva parentesi di funzioni
e raggruppamenti: un'espressione indebolita non viene considerata equivalente.

Attenzione per l'altro agente: il bootstrap rileva automaticamente le migration;
prima di aggiornare altri ambienti, concordarne applicazione e backup. La
sandbox condivisa possiede gia la versione 130000. Le FK possono impedire DELETE o cambio merchant di record
referenziati, anche con grant/terminali disabilitati. Non disattivare le FK per
aggirare questo vincolo. Il trasferimento amministrativo resta fuori perimetro.

C2 deve ancora collegare autenticazione/grant/profilo/terminale abilitati;
questi modelli non aggiungono autorizzazione automatica alle API esistenti.
Disponibilita futura iniziale `unknown`, nessuna colonna stato apertura,
nessun incasso, SIN, wallet o import legacy introdotto.

## Esito chiusura e revisione

Suite C1 su MySQL usa-e-getta: 51 controlli schema/modelli; regressioni API:
62 login e 32 logout; bootstrap finale `already-complete`. Il caso FK verso
altro schema usa metadati simulati sullo schema locale reale, senza creare
database esterni o ampliare privilegi. Due richieste di assegnazione concorrenti
usano invece processi e connessioni DB reali.

Revisione indipendente: aggiunto controllo dello schema referenziato nelle FK;
corretto il test di identita grant affinche non passi per il solo validatore di
unicita. Sintassi PHP/shell e diff controllati. PHPStan/PHPCS non configurati.
Non dichiarati test catalogo/lock/pagamenti: C2 e successive fasi ancora da fare.

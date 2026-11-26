# POS C2 - Catalogo HTTP e integrazione C3

Fonte normativa: `sip/SIP-021/C2-CONTRATTO.md` nell'orchestratore.
GET server-to-server `/api/pos/v1/terminals`; nessuna apertura o pagamento.
Non serve una nuova migration: riutilizza C1 senza seed nel DB condiviso.

## Test ripetibili

```sh
NAPAY_TEST_DATASET=/percorso/dataset-v2-revisionato sh tests/docker/pos-terminals.sh
```

Runner dedicato derivato dal setup comune usa-e-getta, senza modificarlo.
Rete/DB/volume effimeri, dataset sorgente read-only, nessuna porta host.
Suite rifiuta DB diverso da napay_test_runtime o conferma assente. Include
fixture autonome, failure probe di schema/dati solo nel DB isolato, API reale,
verifica log e bootstrap finale. Nessuna prova finanziaria o istanza BTCPay.

## Copertura

Scope multi-negozio e cross-merchant, soli account base attivi anche dopo
cambio ruolo, flags profilo/grant/terminale, sessione scaduta/revocata o invalidata
da password/stato/chiave, cursor manomesso e legato a sessione/client/limit,
revoca grant tra pagine, JSON 16 KiB con pagine adattive e nomi lunghi, rate limit
client/sessione separati dal login/logout, errori fissi e assenza segreti.

Il cursor cifra posizione e limit tramite primitive Yii2; non e grant e non
congela il catalogo. Schema mancante o nome incoerente non diventano una lista
vuota. Con LOGIN-R1-D3 il catalogo vuoto e legittimo per un operatore abilitato
senza grant; profilo assente/disabilitato e identita R1 incoerente danno 401.

## POS

`pos/v2/tests/catalog-client.php`: parser/vista/controller con trasporto
simulato, encoding, lista vuota, token assente dall'HTML, errore senza riuso di
cache e invalidazione della custodia locale su invalid_token. Browser sandbox
aveva verificato il precedente catalogo vuoto sulla fixture admin; questa prova
non certifica il nuovo flusso R1, che rifiuta admin/gestori al login POS.
Le prove HTTP/DB popolate avvengono esclusivamente nella suite isolata;
nessun operatore creato implicitamente nella sandbox per aggirare il requisito.

## Handoff

Master possiede TerminalCatalog, TerminalsController, rotta GET e repository
POS. Nessun edit a PosApi, Throttle, AR C1, runner comune o file C4. L'agente
backoffice usa il proprio worktree e il contratto C1, non modifica queste API.
Le future aperture devono ripetere autenticazione/grant e acquisire il lock
atomicamente: vedere un terminale in questo catalogo non lo apre.

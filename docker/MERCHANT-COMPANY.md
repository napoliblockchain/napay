# Aziende e pagina Azienda del merchant

Riferimento funzionale: Aura `docs/sip/SIP-071-dati-azienda-amministratore.md`.
Napay riusa `Merchants`, relativo controller e form: nessuna tabella nuova,
nessun secondo modello Company, nessun import Yii1.

## Navigazione e accesso

- Admin: voce **Aziende** nella sidebar, elenco `merchants/index`.
- Merchant: voce **Azienda** sotto Profilo nel menu account in alto a destra.
- `merchants/company` accetta GET/POST con CSRF, solo ruolo merchant.
- L'azienda deriva esclusivamente da `user_id` dell'identita collegata: ID in
  query o POST non selezionano un'altra azienda.
- Nessuna azienda o piu aziende per lo stesso account: risposta 404 controllata;
  non viene scelto arbitrariamente il primo record. Per supportare multi-azienda
  occorre definire un flusso esplicito, non cambiare questa selezione in silenzio.
- Admin e utenti semplici non accedono alla pagina account Azienda.
- Dettaglio/modifica esistenti: admin oppure merchant proprietario del record.
  ID cifrati malformati/non presenti producono 404; proprietario diverso 403.

## Campi modificabili

Scenario `Merchants::SCENARIO_UPDATE`: ragione sociale (`description`), email,
telefono, mobile e indirizzo (via, civico, comune, CAP, provincia, paese).
Si conservano le regole di validazione esistenti.

**Partita IVA e proprietario non sono caricabili in modifica**, anche per admin.
La partita IVA viene mostrata in sola lettura, ma il blocco effettivo e lato
server: richieste costruite manualmente, anche con valori array, non la cambiano.
Il proprietario originario viene inoltre ripristinato prima della validazione.
La creazione mantiene il proprio scenario con partita IVA iniziale.

Non viene aggiunto upload logo: il modello/schema merchant non ha il campo logo
presente in Aura. I flussi creazione/eliminazione/export e i negozi richiedono
ulteriore audit; questo intervento non certifica l'intero CRUD come sicuro.

## Verifiche

`tests/application/merchants.php`: 46 controlli sul DB temporaneo
`napay_test_runtime`, scritture racchiuse in transazione e rollback finale.
Accesso proprietario, ID alterati, profilo non autorizzato, conservazione owner,
scenario update, immutabilita IVA admin/merchant e pagina senza ID client.

`tests/docker/runtime.sh`: 134 controlli HTTP, inclusi menu/ruoli, POST con CSRF,
aggiornamento contatti, richiesta diretta non autorizzata e POST con owner/IVA
manipolati. Regressioni: 30 utenti record-level, 15 stato/log, 68 password/mail,
92 registrazione/attivazione. Riavvio e bootstrap no-op verificati.

UI: 56 controlli renderer e 35 browser su fixture anonime, viewport
375/768/1440 e menu Azienda mobile. Nessun account storico modificato;
nessun SMTP, pagamento o chiamata wallet.

Collaudo di chiusura 2026-11-11: incluso il nuovo login email-only e mantenute
le quattro voci associazione del commit `8f1da057`. Passati anche i 36 controlli
del backoffice associazione, 43 configurazione, 20 Compose locale e 21 dipendenze.

## Pagine ancora mancanti nella v2

Ricognizione GET locale: dashboard, aziende, negozi, pannello admin,
impostazioni, privilegi, log e form creazione aziende/negozi rispondono 200
all'account admin di prova. POS e transazioni restituiscono 404: il repository
contiene view, ma non controller/modelli corrispondenti; il DB v2 recuperato
non contiene le tabelle POS/invoices. Non e un errore di Docker o TLS.

Il porting funzionale di questi moduli richiede uno step dedicato su schema,
autorizzazioni e protocolli di pagamento. Non sono stati creati placeholder,
importati dati Yii1 o riattivate chiamate a servizi esterni.

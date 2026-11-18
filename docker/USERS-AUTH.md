# Utenti Locali, Nessuna Dipendenza Cognito

## Verifica Sorgente

Login effettivo: `SiteController` -> `models/forms/LoginForm` -> `Users` ->
verifica hash password nel database locale. Configurazione web usa `Users` come
IdentityInterface, senza auth client Cognito. Composer non richiede SDK AWS,
la classe `CognitoAuthenticator` non esiste e `params['aws']` non e configurato.
Le chiamate rimaste in enable/disable erano quindi residui non funzionanti,
non un'integrazione da configurare per il nuovo runtime Docker.

Rimosse le chiamate dal controller e l'import inutilizzato di `AuthHandler`
nel form login. Il 2026-10-24 e stato eliminato anche il vecchio
`components/AuthHandler.php`: nessun riferimento attivo nel repository,
assunzioni SSO/schema non supportate, non collegato al login locale.
Le colonne oauth e la tabella auth restano conservate: nessuna cancellazione
di identita storiche e nessuna nuova integrazione SSO.

## Policy Applicata Al Controller Utenti

- Autenticazione obbligatoria su tutte le azioni consentite; resto negato.
- Ogni utente puo leggere il proprio profilo.
- Lettura altri profili, liste/export e gestione stato richiedono il confine
  amministrativo gia previsto (`level >= 40`), con livello destinatario non
  superiore a quello dell'operatore. Nessuna visibilita trasversale merchant.
- Auto-modifica stato vietata; confermata la limitazione gia presente nella UI.
- ID di profilo resta cifrato per sessione, ma non costituisce autorizzazione.
  Verifica server-side di proprietario/livello dopo risoluzione ID. ID malformati,
  array, fuori range o inesistenti sono rifiutati senza stack al client.
- Update accetta esclusivamente `Users[is_active]` scalare 0/1. Nessun cambio
  password, ruolo, email, authKey o accessToken tramite mass assignment.
- Enable/disable modificano solo stato locale. Disabilitazione effettiva ruota
  authKey e accessToken del solo account interessato; vecchie sessioni/cookie
  e token non tornano validi dopo riabilitazione. Nessuna rotazione al bootstrap.
- Identita sessione/API richiedono stato esattamente ACTIVE=1. Anche BLOCKED=2
  e negato: prima era truthy nel controllo login. La lista ora visualizza BLOCKED.
- Update/enable/disable/export e toggle sidebar legacy richiedono POST + CSRF.
  Form stato ora invia realmente i campi all'azione update. Cookie legacy
  tramite collezione Yii firmata, HttpOnly/SameSite Lax e Secure su HTTPS.
- Output profilo, attributo hidden export e descrizioni lista codificati HTML.
- Liste/export e scheda utenti del pannello admin applicano il limite di livello
  nella query, prima di conteggi e paginazione. Rimossa la selezione successiva
  dei modelli, che escludeva solo alcuni livelli e non tutti gli operatori.
- Filtri export: JSON array/oggetto, massimo 64 KiB; valori mancanti, non scalari
  o JSON malformato rifiutati con 400. POST/CSRF e scope DB restano obbligatori.
- Celle dati XLSX salvate come testo esplicito: un nome che inizia con `=` non
  diventa formula. Anche ID/stato nell'export sono testo; DB/lista non cambiano
  tipo. Risposta RAW con MIME XLSX, filename senza slash e cache `no-store`.

## Logging

`AuthenticationFileTarget` conserva avvisi Yii su identita rifiutate ma rimuove
la credenziale dal messaggio. Prefisso mantiene IP/user ID, non session ID.
Target web non allega dump GET/POST/cookie/sessione/server ai log; potrebbero
contenere password o token. Cambio stato registrato tramite audit applicativo
con ID destinatario e stato, senza credenziali. Non e una revisione completa
dei messaggi di log di tutti gli altri controller.

## Prove E Limiti

`tests/docker/runtime.sh`: MySQL temporaneo da copia v2, chiavi e utenti fittizi,
Apache reale, vendor produzione Napay, nessuna porta host o uscita Internet.
`users-access.php` verifica ID cifrati generati server-side anche per destinatari
non autorizzati, gerarchia livelli e credenziali non attive. `http.php` verifica
richieste reali, CSRF/metodi, stato, mass assignment, sessioni revocate e XSS.
`users-state.php` confronta password/ruolo/identita fixture e controlla log senza
credenziali. Nessuna modifica a utenti operativi, source dump o poawallet.

Non sostituisce revisione completa autorizzazioni su merchant/store/pagamenti,
reset password/email, SSO, gestione nuovi ruoli o account amministrativi.
Il criterio livelli esistente e ora applicato prima della query dati;
eventuale nuovo RBAC completo richiede decisione e intervento dedicati.
Nessun cutover operativo autorizzato.

Esito 2026-10-23: 30 controlli record-level, 76 HTTP, 15 stato/preservazione/log,
riavvio/no-op; regressioni 23 configurazione, 21 dipendenze, 38 layout,
34 schema e 32 bootstrap + 2 Compose. Tutti eseguiti con esito positivo.
Lint PHP/JS/shell e scansione segreti riusciti. PHPStan/PHPCS non configurati;
Codeception legacy incompleto, non eseguito. Solo suite isolate, nessun deploy.

Esito 2026-10-24: 109 controlli HTTP (inclusi export XLSX e scheda utenti admin),
30 record-level, 15 stato/preservazione/log e riavvio/no-op riusciti. Ripetute
con esito positivo regressioni 23 configurazione, 21 dipendenze, 38 layout,
34 schema, 32 bootstrap + 2 Compose. Lint PHP, diff e scansione segreti puliti.

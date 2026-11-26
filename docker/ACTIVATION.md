# Attivazione Locale Non Distruttiva

## Correzione

Rimosso ogni DELETE dall'azione di attivazione. Link errato, scaduto o riusato
non modifica utente, password, consensi o dati dipendenti. Non esiste cleanup
automatico degli account scaduti. Prima il ramo firma errata poteva cancellare
anche utenti attivi e relativi record collegati tramite cascade.

Il link esistente `site/activate?id=...&sign=...` resta compatibile per richieste
locali pending valide. ID cifrato con la chiave applicativa esistente; firma
HMAC dello stesso formato storico, ora confrontata con `hash_equals`.
Nessuna nuova chiave globale, tabella, migration o rotazione di identita.

## GET E POST

GET verifica il link e mostra conferma con CSRF: nessun dato modificato, anche
in caso di scansione automatica email. POST ripete verifica e consuma la
credenziale con UPDATE condizionale atomico su ID/stato/provider/authKey/token.
Richieste concorrenti con vecchia fotografia della riga non possono vincere
due volte. Nessun login automatico: dopo successo si torna al login.

Header `no-store`/`no-referrer` impostati prima di filtri e CSRF, anche per
errori. Apache non registra query con `id`/`sign`; verificare separatamente
log gateway/APM prima del deploy. Log applicativo registra solo ID dell'account
attivato, mai credenziale o URL completo.

## Ammissibilita

- Solo account `SELF` nello stato pending 0, con nonce storico numerico di 16
  cifre e accessToken casuale non vuoto (almeno 32 caratteri).
- Timestamp Unix completo nelle prime 10 cifre del nonce, non substring
  tronca; durata `nonce.timeout`, attualmente 48 ore; timestamp futuri negati.
- Firma base64 canonica da 64 byte SHA-512, verifica a tempo costante.
- Account attivo, bloccato, non locale o disabilitato con credenziale ruotata
  non puo essere riattivato dal vecchio link.
- ID mancante, array, malformato, fuori range o inesistente: errore 400 generico,
  nessuna cancellazione. Vecchie firme non tornano valide dopo altre operazioni.

Al successo ruotano solo authKey/accessToken del destinatario, si azzera eventuale
reset hash e si imposta ACTIVE. Password, oauth_uid, profilo e ruolo preservati.
Il metodo `Users::activate()` ora richiede una firma valida: non basta chiamarlo
senza credenziale. Il precedente metodo inutilizzato cambiava oauth_uid invece
di eseguire questa transizione; nessun chiamante nel repo ne dipendeva.

## Email

Template registrazione utente/admin mantengono layout originale ma usano
`WEBAPP_LINK` HTTPS configurato, non HTTP Host. Nomi, email e metadati codificati
HTML. Primo account gia attivo riceve link login, non un link di attivazione
non applicabile. Restano trasporto file privato e SMTP TLS della configurazione
comune; nessun invio reale effettuato nei test.

## Prove E Limiti

`tests/application/activation.php`, richiamato da `tests/docker/runtime.sh`, usa
registrazione HTTP reale, consensi e mail file, poi GET di conferma/POST, firma
errata, replay, scadenza/futuro, stato bloccato/provider esterno, credenziale
sostituita, istanze stale, ID malformati e login successivo. Confronta password,
identita, ruolo e consensi prima/dopo; verifica log senza bearer. Solo DB
`napay_test_runtime` e record fittizi, nessuna modifica a utenti operativi.

Non introduce nuova policy di provisioning del primo amministratore, pulizia
pending o coda/outbox mail. Restano separati consegna SMTP reale, provisioning
amministratori e anti-abuso edge per rilascio pubblico. Il recupero locale dei
link e ora implementato come descritto sotto; non modificare credenziali nel DB.

## Recupero E Reinvio

`site/resend-activation` e accessibile dal login e dalla pagina del link errato/
scaduto. GET mostra form, POST richiede CSRF/email valida. Risposta pubblica
uniforme per email assenti, duplicate, account attivi/bloccati/non locali e
richieste limitate. Solo pending `SELF` con credenziale di registrazione puo
ricevere un nuovo link; nessuna notifica admin aggiuntiva al reinvio.

Il rinnovo sostituisce nonce e accessToken tramite UPDATE condizionale, senza
toccare password, identita, consensi o ruolo. Ogni link precedente e invalidato.
Errore consegna ripristina le vecchie credenziali solo se sono ancora quelle
attese: non sovrascrive attivazioni o interventi admin concorrenti. Non riattiva
un account amministrativamente disabilitato. Disabilitare esplicitamente un
pending (gia stato 0) ora ruota comunque le credenziali per distinguerlo da una
registrazione in attesa; senza questo passaggio il vecchio stato 0 era ambiguo.

`EmailRequestForm` centralizza validazione e mutex/cache dei moduli reset e
reinvio: per ciascun flusso massimo cinque richieste/IP/minuto e una/email/minuto,
con gli stessi limiti single-runtime descritti nella guida password/mail.
Pagine errore funzionano anche per ospiti e offrono il recupero corrispondente.

Registrazione convalida password/conferma obbligatorie, limite bcrypt 72 byte,
campi testuali coerenti col model e tipo socio solo 0/1. User e consensi sono
committati prima delle notifiche. Fallimento mail non cancella la registrazione:
il pending resta recuperabile dal form. Errori di invio registrati senza
eccezioni/credenziali nel messaggio pubblico.

## Esito 2026-10-24

39 controlli registrazione/attivazione, 68 password/mail, 109 HTTP utenti,
30 record-level e 15 stato/log riusciti; log Apache privi di query bearer,
restart e bootstrap no-op riusciti. Regressioni 33 configurazione, 21 dipendenze,
38 layout, 34 schema e 32 bootstrap + 2 Compose passate. Lint PHP/shell,
diff e scansione segreti puliti. Nessun invio SMTP reale o deploy.
Codeception legacy non eseguito; PHPStan/PHPCS non configurati.

## Completamento Locale 2026-10-24

86 controlli registrazione/attivazione/reinvio e failure-recovery passati con
file mail, inclusi apertura/conferma dei link letti dagli `.eml`. Ripetuti con
successo 68 password/mail, 109 HTTP, 30 record-level e 15 stato/log; 33 ambiente,
21 dipendenze, 38 layout, 34 schema e 32 bootstrap + 2 Compose. Controllo log
senza bearer e restart/no-op riusciti. Nessun warning PHP nelle suite finali;
lint, diff e scansione segreti puliti. Nessun invio SMTP o deploy operativo.

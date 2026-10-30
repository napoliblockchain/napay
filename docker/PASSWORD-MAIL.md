# Password Reset Ed Email Locali

## Flusso

`site/forgot-password` -> form/CSRF -> throttle -> un solo account attivo per
email -> nuovo token -> hash in `users.activationCode` -> email multipart.
`site/reset-password` -> verifica token/scadenza -> validazione password/CSRF ->
UPDATE condizionale atomico -> password nuova, token consumato, authKey e
accessToken ruotati -> notifica email. Nessun login automatico dopo reset.

Nessuna nuova tabella/migration. Token raw casuale di 48 caratteri piu timestamp;
DB conserva SHA-256 e timestamp, non il bearer. Scadenza un'ora, no timestamp
futuri. Nuova richiesta invalida il precedente link; richieste ravvicinate
sono limitate. Token vecchio formato in chiaro non accettato: richiedere nuovo
link dopo aggiornamento. Nessuna rotazione generale chiavi/sessioni al deploy.
Disabilitare account cancella anche eventuale hash reset.

Password: minimo esistente 8 caratteri, massimo 72 byte per evitare troncamento
bcrypt, conferma obbligatoria. Password e API/session credentials cambiano in
una sola istruzione con condizione su ID, stato ACTIVE e hash reset atteso.
Stesso token gia consumato o sostituito, account disabilitato e form scaduto
non possono sovrascrivere la password. Non modifica email, ruolo o identita.

Email sconosciute, inattive, duplicate e richieste limitate ricevono lo stesso
redirect/messaggio pubblico delle richieste elaborate. Non si seleziona un
account arbitrario se piu utenti attivi condividono l'email. Errori consegna
non espongono eccezioni SMTP; hash nuovo ripristinato al precedente solo se
ancora corrispondente, senza sovrascrivere richieste concorrenti.

## Origine E Segreti

Impostare `WEBAPP_LINK` all'URL pubblico HTTPS fidato (eventuale sottopercorso
incluso). Link generati solo da questa variabile, mai da HTTP Host; query,
frammento e credenziali nell'origine sono rifiutati. Template HTML codificano
nomi/valori dinamici; parte testo include URL utilizzabile. Mai password in mail.

Reset HTML: `Cache-Control: no-store`, `Referrer-Policy: no-referrer`.
Apache registra metodo/percorso, non query string o Referer. Log applicativi non
includono dump POST/sessione/cookie e gli errori Symfony Mailer sono generici.
Verificare separatamente che anche gateway Caddy, proxy, monitoraggio e APM non
registrino URL completi con token prima dell'esposizione pubblica.

## Trasporti `.env`

Default sicuro per sviluppo/test:

```dotenv
MAIL_TRANSPORT=file
WEBAPP_LINK=https://napay.local
```

Nessuna email esce: messaggi in `runtime/mail`, fuori document root, directory
0700/file 0600. Contengono link bearer: non committare, pubblicare o allegare ai
log. Prevedere pulizia/retention; questa modifica non introduce cron di pulizia.
Il helper restituisce errore su file non scrivibile, non finge avvenuta consegna.

SMTP reale e solo opt-in, credenziali da configurare privatamente:

```dotenv
MAIL_TRANSPORT=smtp
MAIL_SMTP_SCHEME=smtps
MAIL_SMTP_HOST=smtp.example.invalid
MAIL_SMTP_PORT=465
MAIL_SMTP_USERNAME=
MAIL_SMTP_PASSWORD=
```

Alternativa: `MAIL_SMTP_SCHEME=smtp`, porta 587, STARTTLS obbligatorio.
Symfony `require_tls=true`, verifica peer/certificato predefinita mantenuta;
nessuna opzione per disattivarla. Timeout stream 10 secondi per operazione,
non garanzia di deadline end-to-end. Logging protocollo disabilitato.
Non stampare compose config o eccezioni SMTP con credenziali. `ADMIN_EMAIL`
resta mittente applicativo; dominio/mittente devono essere accettati dal relay.

## Limitazione Richieste

FileMutex Yii e FileCache condivisi nel runtime serializzano i contatori:
massimo cinque richieste valide per IP nella finestra mobile di 60 secondi e
una per email/minuto, prima di cercare l'account. Non blocca/disabilita utenti.
Nessun sleep fisso per rendere i container pronti o retry dell'import iniziale.

Limiti: protezione locale per singola istanza/runtime condiviso; ricreare un
runtime effimero azzera contatori. IP dietro proxy deve essere configurato con
proxy fidati, altrimenti piu client condividono il limite. Servono rate limit
edge e storage condiviso prima di scaling multi-web. SMTP sincrono puo rivelare
differenze temporali fra richiesta con consegna e account inesistente: risposte
pubbliche uniformi non sono una garanzia di timing indistinguibile. Una coda
mail e relativo threat model restano un passo separato prima di hardening pubblico.

## Prove

`tests/docker/runtime.sh` usa sempre `MAIL_TRANSPORT=file`, URL `.invalid`,
DB `napay_test_runtime`, utenti fittizi e rete interna senza egress. Verifica
Host alterato, mail private, link scaduti/futuri, input errati, token monouso,
istanze concorrenti stale, password e sessioni, rate limit, duplicati, failure
rollback, HTML/testo e log senza bearer. Config SMTP/TLS/timeout verificata
senza aprire connessioni. Nessun invio SMTP reale, recapito pubblico, SPF/DKIM/
DMARC o affidabilita provider dimostrati. Chiavi originali DB non necessarie
per i dati fittizi e mai ruotate. Registrazione, attivazione e reinvio sono
documentati separatamente nella [guida attivazione](ACTIVATION.md).

Riferimenti primari: [OWASP reset password](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html),
[Symfony Mailer 7.4 TLS](https://symfony.com/doc/7.4/mailer.html#ensure-tls).

## Attivazione Legacy: Blocco Risolto

Il rischio individuato durante il reset e stato corretto nello step successivo:
nessun DELETE, conferma GET/POST-CSRF, consumo atomico e timestamp completo.
Dettagli, prove e limiti residui: [attivazione locale](ACTIVATION.md).

## Esito Locale 2026-10-24

68 controlli password/mail, 33 configurazione, 109 HTTP utenti, 30 record-level,
15 stato/log, 21 dipendenze e 38 layout passati; schema 34 e bootstrap 32 + 2
Compose ripetuti con esito positivo. Controllo Apache senza query bearer e
riavvio/no-op riusciti. Nessun invio reale. Lint/diff e scansione segreti puliti;
Codeception legacy non eseguito, PHPStan/PHPCS non configurati.

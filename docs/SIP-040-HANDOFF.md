# SIP-040 fase A - Napay: documenti legali versionati (handoff CLA)

- Worktree: /var/www/blockchain-napoli/napay-worktrees/napay-privacy-documents
- Branch: feature/v2-privacy-documents, base 9996ccb4 (HEAD rilasciato)
- Incarico: sip/SIP-040/PROMPT-CLA.md (worktree backup-offsite di COD)
- Stato: fase A implementata, PRONTA PER REVIEW (nessun commit, come da incarico).

## 1. Inventario (stato attuale)

- Registrazione (`SiteController::actionSignup`, `views/site/signup.php`, `SignupForm`,
  `UserConsensus`): quattro caselle. Statuto = link esterno al sito dell'associazione
  (funziona). Informativa -> `site/print-privacy`, condizioni -> `site/term-of-use`:
  **le action non esistono nella v2** (link rotti). Marketing facoltativo, default 0.
- `user_consensus`: flag 0/1 + timestamp per statuto, privacy, condizioni, condizioni POS,
  marketing. Nessun riferimento al testo accettato. Righe legacy importate dalla v1.
- `settings` `gdpr_*` importati dalla v1 (titolare, indirizzo, CAP, città, P.IVA, telefono,
  fax, email, DPO): usati solo dalla ricevuta delle quote. Nessun testo dell'informativa.
- Nessuna gestione di contenuti/pagine nel backoffice. Disponibili nel vendor (dipendenze di
  Yii2, nessuna nuova dipendenza): `cebe/markdown` (yii\helpers\Markdown) ed
  `ezyang/htmlpurifier` (yii\helpers\HtmlPurifier).
- Cookie: solo tecnici (`_csrf`, sessione, `_identity`, tutti HttpOnly, SameSite Lax).

## 2. Contratto proposto

Dati
- Nuova tabella `legal_documents` (migration additiva m261126_120000): tipo
  (`privacy` | `terms` | `cookies`), numero di versione per tipo, titolo, testo in Markdown,
  stato `draft` | `published`, autore e data di creazione, chi e quando ha pubblicato.
  Unicità (tipo, versione). Il documento in vigore di un tipo = la versione pubblicata più alta.
- **Versioni pubblicate immutabili**: nessuna modifica né cancellazione (model + test); si
  corregge creando una nuova versione. Le bozze sono modificabili ed eliminabili.
- `user_consensus`: due colonne nuove facoltative, versione accettata di informativa e
  condizioni (riferimento alla riga pubblicata). NULL = consenso registrato prima del
  versionamento (righe legacy e registrazioni fino al rilascio): nessuna versione inventata.

Rendering
- Testo Markdown -> HTML con `Markdown::process` (gfm) e poi `HtmlPurifier` con allowlist:
  titoli, paragrafi, elenchi, grassetto/corsivo, tabelle, link solo http(s)/mailto con
  rel noopener. Nessun HTML, script, stile o immagine inseriti dall'admin.

Pagine pubbliche (anonime, solo GET)
- `/legal/privacy`, `/legal/terms`, `/legal/cookies`: versione in vigore, numero e data di
  pubblicazione; `?version=N` mostra una versione storica pubblicata (mai le bozze).
- Documento mai pubblicato: pagina "documento in preparazione" con HTTP 404, nessun testo
  legacy mostrato come se fosse approvato.
- Link nel piè di pagina di login, registrazione e backoffice.

Amministrazione (solo admin, POST + CSRF, registro attività)
- Elenco per tipo, nuova bozza (vuota o copiata dall'ultima pubblicata; per l'informativa
  prefisso con i dati `gdpr_*` del titolare come semplice testo di bozza), modifica,
  anteprima con lo stesso rendering pubblico, pubblicazione con conferma, eliminazione bozza.

Consensi
- Registrazione: le caselle di informativa e condizioni linkano le pagine nuove e salvano la
  versione in vigore mostrata nel modulo (id nascosto ricontrollato lato server: se nel
  frattempo è stata pubblicata una versione nuova, il modulo viene ripresentato).
- Nuova versione delle **condizioni**: al login successivo l'utente vede la pagina di nuova
  accettazione e non entra nel backoffice finché non accetta (logout possibile). Per
  l'**informativa** nessun blocco (è un'informazione, non un consenso): avviso con link.
- Account legacy (versione NULL): stessa regola delle condizioni quando esiste una versione
  pubblicata.
- Marketing: resta facoltativo, mai implicito.

## 2b. Testi standard come bozze (richiesta utente 2026-11-26)

- Modelli di testo per informativa (art. 13 GDPR), condizioni di utilizzo e cookie policy,
  in Markdown, versionati nel repository (`legal/templates/*.md`). Segnaposto per i dati del
  titolare riempiti dalle impostazioni `gdpr_*`; parti da decidere marcate "[DA APPROVARE: ...]"
  (durate SIP-039, responsabili esterni, foro).
- Usati solo per creare una **bozza** ("Nuova bozza" nel backoffice). Mai pubblicati in
  automatico: la pubblicazione resta un atto dell'admin dopo l'approvazione dell'associazione.
- La pubblicazione è rifiutata se il testo contiene ancora "[DA APPROVARE" (nessun segnaposto
  pubblicato per errore).

## 3. Decisione necessaria (si ferma qui, come da incarico)

Cosa succede alla **registrazione finché informativa e condizioni non sono pubblicate**
dall'associazione (oggi nessun testo approvato esiste):
- A) Registrazione sospesa con messaggio chiaro, finché entrambi i documenti non sono pubblicati.
  Conforme a "mai consenso verso documenti assenti", ma blocca le iscrizioni in produzione.
- B) Registrazione invariata (come oggi), consenso salvato con versione NULL; le pagine pubbliche
  mostrano "in preparazione"; la nuova accettazione al login recupera tutti dopo la pubblicazione.
  Nessun blocco, ma per un periodo si accetta un documento non leggibile (situazione attuale).

Raccomandazione CLA: B solo fino alla pubblicazione dei testi, con avviso nell'admin; A se
l'associazione preferisce la conformità piena subito.

**Decisione utente 2026-11-26: B** (registrazione aperta, consenso senza versione finché i
testi non sono pubblicati; nuova accettazione al login dopo la pubblicazione).

## 4. Fuori incarico / da segnalare

- Wallet e POS: stesse pagine nella fase successiva; contratto suggerito: leggere da Napay le
  versioni pubblicate tramite endpoint pubblico in sola lettura, oppure copia propria.
- La base giuridica del trattamento dei soci è il rapporto associativo, non il consenso: la
  casella "autorizzo il trattamento" va rivista con il consulente (testo, non codice).
- SIP-039 (retention/cancellazione) fuori incarico.

## 4b. Implementazione (stato)

File scritti (lint PHP verde, nessun commit):
- `migrations/m261126_120000_create_legal_documents.php`, `components/db/DatabaseRoles.php`
- `models/LegalDocuments.php` (immutabilità, pubblicazione condizionata, render Markdown+HtmlPurifier)
- `controllers/LegalController.php` (pubblico), `controllers/LegalDocumentsController.php` (admin)
- `components/legal/TermsGate.php` (filtro applicativo `as termsGate` in `config/web.php`)
- `controllers/SiteController.php` (versioni nel signup, action accept-terms, flash errori codificati)
- `models/UserConsensus.php` (colonne versione), `config/web.php` (regola URL `legal/<type>`)
- viste `views/legal/*`, `views/legal-documents/*`, `views/site/accept-terms.php`, `views/site/signup.php`,
  menu `views/layouts/sidebar.php`; modelli `legal/templates/{privacy,terms,cookies}.md`
- traduzioni it-IT, link nel piè di pagina (`views/layouts/footer.php`, `views/layouts/auth.php`)
- test `tests/application/legal-documents.php` + runner `tests/docker/legal-documents.sh`

## 5. Test eseguiti (2026-11-26, stack usa-e-getta, dataset import-v2-20261022)

- `tests/docker/legal-documents.sh`: **38 PASS**. Pagine anonime e 404 senza testo, link della
  registrazione e del piè di pagina, accesso (anonimo, socio, merchant, admin), CSRF e POST,
  bozza dal testo standard con segnaposto e pubblicazione rifiutata, bozze mai pubbliche, XSS
  (script, onerror, javascript:, HTML nel titolo), immutabilità via HTTP e model, doppia
  pubblicazione concorrente, versione in vigore, consenso con versioni mostrate e versione
  scaduta rifiutata, marketing facoltativo, blocco e nuova accettazione (casella obbligatoria,
  versione superata rifiutata, sblocco registrato), pagine legali libere durante il blocco,
  versioni storiche e inesistenti.
- Regressioni: `tests/docker/runtime.sh` verde (signup/activation 95, password reset 69, HTTP 143,
  associazione 40+81, quote 40, PayPal 34, BTCPay 34, POS 62+32, ...), `navigation.sh` 117,
  `database-roles.sh` 75. `php -l` su tutti i PHP toccati, `git diff --check` pulito.
- Non eseguiti: browser reale (aspetto delle pagine, mobile), deploy-preflight (nessun file di
  rilascio toccato), POS e wallet (fuori incarico).

## 6. Note per la review

- `config/web.php`: filtro applicativo `as termsGate` (solo utenti collegati; liberi
  logout, errore, pagine legali, accept-terms) e regola URL `legal/<type>`.
- `SiteController`: errori del signup ora codificati con `Html::encode` (prima concatenati come HTML).
- Account senza riga di consensi (se esistono): l'accettazione crea una riga con statuto,
  privacy e marketing a 0 (non dati), mai valori inventati.
- `vendor/`, `runtime/`, `web/assets/` presenti nel worktree solo per i test (ignorati da git).

# SIP - Scorciatoie Wallet e POS

- Stato: IN_PROGRESS
- Area: Navigazione Napay
- Branch: `feature/v2-napay-app-shortcuts`
- Segnalato: 2026-11-24

## Contesto
La topbar v1 collegava Wallet TTS e POS. Ripristinare le scorciatoie nella
topbar e nella pagina pubblica di accesso, senza intervenire sul rilascio VPS.

## Proposta e criteri
- Stesso widget: menu App in topbar dopo il login e pulsanti con icona
  nella pagina pubblica di accesso. Nessun pulsante nella dashboard.
- Su mobile: pulsanti su righe separate.
- Origini da `NAPAY_WALLET_APP_URL` e `NAPAY_POS_APP_URL`: solo HTTPS,
  hostname DNS e porta facoltativa. Valori vuoti o invalidi omettono il link.
- Link in nuova scheda senza referrer. Nessun token, SSO o cambio permessi;
  per operare al POS serve il relativo account operatore.
- Nessuna migration, nuova dipendenza o modifica a gateway/compose.
- Test isolati per menu, accesso pubblico e URL mancanti/non validi.

## Configurazione
Impostare le due variabili nel file env di Napay, nell'ambiente di destinazione.
Per esempio, in locale: `https://wallet.local` e `https://pos.localhost`.
In produzione usare gli indirizzi pubblici del gateway. Variabili facoltative:
ometterle per lasciare disattivate le relative scorciatoie. Nessuna modifica
alla VPS durante lo sviluppo del branch.

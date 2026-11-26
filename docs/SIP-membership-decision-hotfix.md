# SIP-030 - Correzione urgente decisioni iscrizione

- Branch: `fix/membership-request-decision`
- Contesto: il gestore Yii di `data-confirm` invia il form senza il pulsante
  attivato e ignora `formaction`. Il default era l'approvazione.
- Correzione: form base non mutante, destinazioni native esplicite e conferma
  sul submit nativo. Entrambi gli endpoint rifiutano un intento mancante o
  non corrispondente, anche da una pagina caricata prima del rilascio.
- Verifica: HTML servito, POST senza intento/CSRF, mismatch e rifiuto vero
  con motivazione su DB isolato. Nessuna modifica a schema o permessi.
- Recupero separato: identificare gli ID e la finestra temporale dal registro
  e dai campi approvatore/data; verificare quote e dati collegati. Nessuna
  cancellazione o ripristino automatici, nessuna modifica ai dati VPS.

## Eliminazione manuale confermata
Nella scheda Associazione > Soci, un admin puo eliminare il socio e il suo
account insieme, anche se oggi attivo. Conferma esplicita, POST con CSRF e
traccia nel registro. Solo profili base con scheda socio; esclusi il proprio
account, merchant, operatori e soci con quote o sessioni POS. Restano validi
i vincoli di integrita del database e la sola lettura durante l'import legacy.
Non elimina automaticamente nessun socio ne manda email di rifiuto.

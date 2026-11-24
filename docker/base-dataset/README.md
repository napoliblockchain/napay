# Dataset base per il primo avvio (SIP-036 A5)

In produzione non esiste lo schema `napay_v2` (solo il database v1 `npay`): il
primo avvio parte da questo dataset, ricavato con `docker/base-dataset.sh` dal
dataset v2 rivisto del 2026-10-22. Contiene lo schema delle 11 tabelle, lo
storico delle migration e i tre livelli di privilegio generici; nessun utente,
merchant, log, consenso o impostazione. Tutti i dati arrivano dall'import della
v1 (`LEGACY_DB_DSN`).

Il bootstrap lo vuole fuori dal repository, di proprietà dell'utente del job,
con permessi privati: all'installazione si copia in `NAPAY_IMPORT_DIR`
(cartella 0700, file 0600). Prova sulla copia v1: 56 utenti, 12 merchant,
13 negozi, 13 casse, storico BTC/TTS completo, secondo giro identico.

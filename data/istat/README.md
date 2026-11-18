# Elenco comuni ISTAT

`comuni-20260221.csv`: comuni italiani dall'elenco ufficiale ISTAT aggiornato al
21 febbraio 2026 (codice comune alfanumerico, denominazione in italiano,
unità territoriale sovracomunale, sigla automobilistica). 7894 righe.

- Fonte: https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.xlsx
  (scaricato il 2026-11-15, sha256 `83842076860450f7e482daecea6b7a769f5f93d0bf5b0d48802b44896d7a26d5`).
- Conversione una tantum: colonne 5, 7, 12 e 15 del primo foglio, ordinate per codice.
- Caricato dalla migration `m261115_100000_create_istat_comuni` (checksum del CSV verificato).

Aggiornamento: nuovo CSV datato e nuova migration che sostituisce il contenuto
della tabella `istat_comuni`; mai download durante l'esecuzione.

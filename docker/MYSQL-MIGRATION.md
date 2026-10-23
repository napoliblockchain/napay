# Napay: MySQL Docker, riuso dati e migrazione

Prova eseguita il 2026-10-23 sul backup fisico locale del 2026-10-22:
recupero su MySQL 8.0.46, import logico su MySQL 8.4.13, confronto dati e
persistenza OK. Dettagli in `napay-project/tips/TIP-019-napay-mysql-persistenza-migrazione.md`.
Sorgente confermata come perimetro dall'utente: SOLO `napay_v2` (11 tabelle).
Lo schema `napay` legacy resta escluso: nessuna conversione o fusione prevista.
Il nome `napay` usato come destinazione nel test non indica import del legacy.

## Perimetro e vincoli

Solo repository Napay e relativo compose locale. Nessun intervento su poawallet,
MySQL host o compose centrale. Il compose locale implementa import AUTOMATICO
di v2 al primo avvio tramite job dedicato,
MySQL healthy prima del job, web/writer operativi solo dopo job riuscito.
Prima installazione: predisporre dataset e `.env` privati, poi `./docker/up.sh .env`.
Nessun comando manuale di import. Dataset/root secret fuori repository;
UID/GID del job corrispondenti al proprietario del dataset. Testata su copia
reale con vendor Yii isolato; dipendenze Napay e smoke HTTP reale da completare.
PHP resta 8.3.36. Lo schema esistente viene trasferito, non ricreato da zero.
Migrazioni presenti non garantiscono rappresentazione completa del DB operativo.
In particolare, riconciliare `activationCode` mancante e nome migration utenti
diverso prima dell'avvio applicativo. Il repository ora include guard della
migration storica e due migration additive (activationCode/auth); validate
su fixture e sulla copia del backup tramite job automatico. Non modificano o
precompilano account social. Analisi e contratto bootstrap in
`napay-project/tips/TIP-019/`. I comandi seguenti servono alla prova locale e
al runbook di recupero, non sono passaggi manuali dell'onboarding automatico.

Destinazione proposta: MySQL 8.4 LTS. Prima dell'import verificare versione e
distribuzione sorgente. Per MySQL 5.7 prevedere passaggio validato tramite 8.0;
MariaDB richiede analisi separata. Non montare mai il vecchio datadir nel nuovo
container. Non importare schemi di sistema, utenti/grant o dump `--all-databases`.
Il tag `mysql:8.4` segue la linea, non fissa una patch: prima del rilascio registrare
digest approvato e verificare aggiornamenti di sicurezza/compatibilita.

Fonti: [percorsi upgrade MySQL](https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html),
[Docker e variabili inizializzazione](https://hub.docker.com/_/mysql).

## 1. Inventario sorgente e prova

Raccogliere, con account autorizzato e client compatibile col server:

```sql
SELECT VERSION(), @@version_comment, @@sql_mode,
       @@character_set_server, @@collation_server, @@time_zone;
SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'napay_v2';
```

Confermare nome reale del database; inventariare viste, trigger, routine,
eventi e relativi DEFINER, dimensioni, chiavi esterne e storico Yii `migration`.
Rilevare conteggi esatti per tabella (`COUNT(*)`, non TABLE_ROWS stimato),
totali economici applicativi e campioni cifrati senza pubblicarli nei log.
Verificare charset per tabella e connessione: questa modifica non cambia
le tabelle/collazioni. `config/db.php` ora usa connessione `utf8mb4`, coerente
con sorgente e verifiche di preservazione Unicode.

Conservare `SECRET_HASH_KEY`, `COOKIE_VALIDATION_KEY`, hash password e altri
segreti applicativi originali. Non rigenerare chiavi per dati gia cifrati.
Conservare separatamente upload/allegati e configurazioni non presenti nel DB.

Provare prima su copia isolata, con volume e progetto Compose distinti,
gateway scollegato e chiamate esterne/webhook disabilitati. L'import di SQL e
esecuzione di codice privilegiato: accettare solo dump fidati e revisionati.
Revisionare DEFINER e riferimenti a schemi assoluti; mai sostituzioni cieche.

## 2. Preparare destinazione

```sh
cp .env.example .env
chmod 600 .env
install -d -m 700 "$HOME/.config/napay"
# Solo prima creazione: noclobber impedisce sovrascrittura di chiavi esistenti.
(umask 077; set -C; openssl rand -hex 32 > "$HOME/.config/napay/mysql-root-password")
docker volume create napay_mysql_data
```

Compilare `.env`: `NAPAY_MYSQL_ROOT_SECRET_FILE` deve essere percorso assoluto
del file appena creato, fuori repository (il repository e montato nel web).
Impostare password applicativa forte e chiavi originali. `DB_DATABASE` e dbname
in `DB_DSN` devono coincidere; host DSN deve essere `mysql`, porta interna 3306.
Compose richiede DB_USERNAME/DB_PASSWORD non vuoti. Non usare root per Napay.
Con segreti contenenti `$`, usare quote singole e verificare parsing di Compose.
Per nuove password preferire valori casuali esadecimali, senza alterare le
chiavi di cifratura originali. Prima di riusare un volume gia esistente,
verificarne proprieta e contenuto: `volume create` non lo reinizializza.

```sh
docker compose config --quiet
docker compose up -d --wait mysql
docker compose exec -T mysql sh /opt/napay/mysql-tools.sh inventory
```

Non avviare ancora web. Il volume esterno va creato esplicitamente; non viene
rimosso da `docker compose down`, nemmeno con `-v`. Non usare `volume rm/prune`.
Il file root e necessario anche per i successivi riavvii e per i backup.
Root secret locale e protetto da permessi, non e un secret manager cifrato.

## 3. Dump logico sorgente

Usare client della famiglia/versione compatibile con la sorgente. Esempio per
MySQL compatibile con queste opzioni, con password in option file chmod 600:

```sh
# /percorso/napay-v2-client.cnf: sezione [client], host/user/password reali.
# Directory backup FUORI dal repository, su spazio riservato.
umask 077
set -C
if mysqldump --defaults-extra-file=/percorso/napay-v2-client.cnf \
  --single-transaction --quick --routines --events --triggers --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF \
  napay_v2 > /percorso/backup/napay-final.sql.partial; then
  mv -n /percorso/backup/napay-final.sql.partial /percorso/backup/napay-final.sql
  sha256sum /percorso/backup/napay-final.sql
else
  echo 'Dump fallito: non importare il file parziale.' >&2
  exit 1
fi
```

Il dump contiene dati sensibili: non committare, non copiare in webroot, cifrare
e trasferire su storage separato secondo policy. Non condividere option file.
`--single-transaction` garantisce snapshot coerente solo per tabelle transazionali;
vietare DDL durante dump. Tabelle MyISAM richiedono blocco scritture/lock
concordati. Routine/eventi possono richiedere privilegi ulteriori da autorizzare.

## 4. Import e verifiche

```sh
docker compose exec -T mysql sh /opt/napay/mysql-tools.sh \
  restore --confirm-empty-target < /percorso/backup/napay-final.sql
docker compose exec -T mysql sh /opt/napay/mysql-tools.sh inventory
```

Restore rifiuta destinazioni con tabelle, viste, routine o eventi. Lavorare con
web e altri writer fermi: il controllo non protegge da scritture concorrenti.
Un errore SQL puo lasciare import parziale: non rilanciare alla cieca e non
proseguire l'avvio. Conservare diagnostica protetta, correggere causa, usare
nuova destinazione vuota. Il tool non cancella o resetta database.

Confrontare conteggi esatti, schema/indici/FK, storico migrazioni, totali economici,
date/timezone, Unicode e decifratura campioni con chiave originale. Verificare
login, ruoli, merchant, store, impostazioni e letture operative. Account sorgenti
non trasferiti automaticamente: adattare DEFINER con privilegi minimi approvati.
Event scheduler e esplicitamente OFF nel compose, anche dopo import degli eventi
sorgenti (non abilitarlo senza censire eventi e possibili effetti economici).

Non lanciare automaticamente tutte le migrazioni Yii: importare anche lo storico,
poi confrontare migrazioni applicate e pendenti. Eseguire solo quelle approvate,
dopo backup, quando dipendenze Yii sono installate e validate.

## 5. Cutover e rollback

1. Prima prova completa su copia; registrare tempi e approvare riconciliazione.
2. Finestra manutenzione: fermare vecchio web, cron, worker e altri writer.
3. Dump finale coerente, checksum e import in destinazione vuota preparata.
4. Verifiche precedenti; avvio nuovo web e smoke test con scritture controllate.
5. Collegare gateway solo dopo esito positivo; tenere vecchio DB invariato,
   senza writer, per periodo concordato. Mai due ambienti operativi in scrittura.

Prima delle nuove scritture rollback = ripristinare accesso al vecchio ambiente.
Dopo nuove scritture NON basta cambiare DSN: fermare traffico, salvare nuovo DB,
riconciliare/transitare delta o concordare ripristino con RPO approvato. Vietata
perdita silenziosa di pagamenti o transazioni. Dismissione host separata.

## 6. Backup, persistenza e ripristino

```sh
# Destinazione esterna, umask 077 e noclobber come per il dump sorgente.
docker compose exec -T mysql sh /opt/napay/mysql-tools.sh backup \
  > /percorso/backup/napay-docker.sql.partial
# Verificare exit code prima di rinominare, checksum, cifrare e trasferire.
```

Automatizzare backup con retention, allarmi e copie fuori host; custodire anche
chiavi originali e root secret separatamente. Mai archiviare datadir live come
unico backup. Restore periodico su volume NUOVO e confronto dati obbligatori.
Storage persistente non protegge da guasto disco, errore umano o ransomware.

Test infrastrutturale ripetibile: `sh tests/docker/mysql.sh`. Usa solo dati
fittizi e risorse Docker con nomi temporanei; non tocca database operativo.

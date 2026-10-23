# Napay Docker: PHP 8.3.36 e configurazione runtime

Le immagini runtime restano fissate a PHP `8.3.36`: `Dockerfile` usa
`php:8.3.36-apache`; `Dockerfile_Image` ha lo stesso default `VARIANT`.
Il pin non sostituisce una scansione vulnerabilita dell'immagine e delle dipendenze.

## Configurazione unica `.env`

`config/env.php` segue il pattern di Aura (`/var/www/levitas/aura/config/env.php`):
legge `.env` nella root; le variabili gia presenti nel processo Docker/CI hanno
precedenza. Nessun `secrets.php` viene caricato. Web e console usano lo stesso
bootstrap. Default: `APP_ENV=prod`, `APP_DEBUG=false`; valori non validi e
configurazioni obbligatorie mancanti bloccano l'avvio.

Il file supporta righe `KEY=value`, valori su una sola riga, quote singole o
doppie e commenti su righe dedicate. Valori letterali: nessuna espansione shell,
interpolazione `${VAR}`, multilinea o interpretazione di escape. Non usare `source`
per caricare `.env`.

Queste regole descrivono il parser PHP. Docker Compose applica anche il proprio
parsing: usare quote singole per preservare `$` letterali nei segreti e verificare
che i valori originali non vengano interpolati. Non stampare `compose config`
completo nei log: contiene credenziali; usare `config --quiet` per validazione.

```sh
cp .env.example .env
chmod 600 .env
# Impostare credenziali e chiavi prima dell'avvio.
# Preparare secret esterno e volume come descritto in MYSQL-MIGRATION.md.
./docker/up.sh .env
```

Il compose locale legge `.env` a runtime, senza build args DB. Espone soltanto
HTTP diagnostico su `127.0.0.1:18120`, parametrizzabile con `NAPAY_HTTP_PORT`.
L'accesso normale HTTPS passa dal gateway Caddy della piattaforma; niente
certificati da configurare nell'applicazione. Non esporre la porta diagnostica
su Internet. `NAPAY_ENV_FILE` permette un file runtime alternativo al compose.
Per un file alternativo usare anche `docker compose --env-file /percorso/.env`:
`env_file` del servizio non alimenta l'interpolazione del file Compose.

## MySQL dedicato e dati persistenti

Solo questo compose Napay aggiunge `mysql:8.4` (linea LTS), senza cambiare
poawallet o il MySQL condiviso dell'orchestratore. MySQL non pubblica porte host:
`3306` esiste solo sulla rete privata `database`; web conserva rete per API esterne.
Il volume esterno `napay_mysql_data`, montato su `/var/lib/mysql`, non dipende
dal ciclo di vita dei container e non viene eliminato da `compose down -v`.
La cancellazione manuale del volume o la perdita del disco distruggono i dati:
servono comunque backup separati e restore provato.

Root password: file esterno montato solo su MySQL tramite Compose secret.
Credenziali applicative: `DB_USERNAME` / `DB_PASSWORD` in `.env`.
Le variabili di inizializzazione MySQL valgono solo su volume vuoto: modificare
`.env` non cambia gli account gia presenti. Non eliminare il volume per ruotare password.

Procedura completa: [migrazione e backup MySQL](MYSQL-MIGRATION.md).
`docker/up.sh` verifica permessi e collocazione privata degli input, crea il
volume esterno solo se assente e avvia Compose aspettando readiness.
Il volume `mysql_socket` e effimero e separato: non contiene dati DB.

Nell'orchestratore centrale `napay-web` deve ricevere le stesse variabili a
runtime: il suo attuale solo `APP_ENV` non e sufficiente per avviare Napay.
Questa modifica non cambia il compose condiviso con il lavoro su poawallet.

## Migrazione configurazione esistente

Trasferire i valori senza rigenerare le chiavi usate da dati gia cifrati.
`SECRET_HASH_KEY` deve mantenere il precedente `secret_hash_key`; cambiare quel
valore rende illeggibili i dati cifrati con `components/Crypt.php`.

| Chiave precedente | Variabile `.env` |
| --- | --- |
| `db_dsn` | `DB_DSN` |
| `db_username` | `DB_USERNAME` |
| `db_password` | `DB_PASSWORD` |
| `cookieValidationKey` | `COOKIE_VALIDATION_KEY` |
| `secret_hash_key` | `SECRET_HASH_KEY` |
| `adminName` | `ADMIN_NAME` |
| `adminPhone` | `ADMIN_PHONE` |
| `adminEmail` | `ADMIN_EMAIL` |
| `website` | `APP_WEBSITE` |
| `defaultTimeZone` | `DEFAULT_TIME_ZONE` |
| `localTimeZone` | `LOCAL_TIME_ZONE` |
| `webapp_society` | `WEBAPP_SOCIETY` |
| `webapp_link` | `WEBAPP_LINK` |

Anche le precedenti variabili compose applicative `MYSQL_*` vanno sostituite con
`DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`. Il servizio database deriva da queste
le proprie variabili di inizializzazione `MYSQL_*`. Non e previsto fallback automatico a
credenziali legacy o password vuote. Dopo migrazione verificata, rimuovere la
vecchia configurazione dal server secondo le proprie procedure di backup.
Nessuna chiave reale viene generata o inserita nel repository da questa modifica.

## Build distribuibile

Per `Dockerfile_Image`, che usa un contesto esterno con `src/`, copiare anche
`.dockerignore` nella root di quel contesto: `.env` e `secrets.php` non devono
entrare nei layer. Fornire `.env` al container tramite `env_file`/secret manager
al momento dell'avvio. La build non riceve credenziali DB e non genera chiavi VAPID.
I vecchi layer/immagini eventualmente costruiti con credenziali restano invariati:
valutare rotazione e rimozione secondo le procedure di rilascio.

## Verifiche

```sh
php tests/config/environment.php
```

Il test usa directory temporanee e valori fittizi: nessuna connessione DB,
nessuna lettura di configurazioni operative. Verifica precedenza ambiente,
parsing, chiavi obbligatorie, errori senza credenziali, compatibilita web/console
e assenza di caricamenti della configurazione legacy.

## Compatibilita schema v2 (test locale)

Il ripristino del DB v2 mantiene utenti, hash, ruoli e chiavi. Le nuove migration
riconoscono il vecchio nome della migration utenti solo dopo verifica rigorosa
di schema/storico; aggiungono activationCode e auth vuota senza ricreare users.
OAuth/SSO non viene abilitato e non e verificato da questi test.

```sh
docker build --pull=false --network none -t napay/php83-db-test:local \
  -f tests/docker/Dockerfile.php tests/docker
sh tests/docker/v2-schema.sh
```

Occorrono dipendenze Yii compatibili in vendor. Solo per test isolati e possibile
impostare `NAPAY_TEST_VENDOR` a un vendor esterno; non e una soluzione per il
runtime Napay, le cui dipendenze vanno installate/validate separatamente.
La suite crea e rimuove solo MySQL/volumi temporanei, senza porte pubblicate.
34 controlli: DB nuovo, schema storico, blocchi compatibilita, retry,
conservazione dati/consensi/ID/hash e vincoli auth. Nessun dato operativo usato.
Il rollback schema richiede backup approvato.

## Bootstrap Automatico Locale

MySQL healthy -> job `bootstrap` -> import/upgrade verificato -> web. Il web
controlla stato/versione anche nel proprio entrypoint dopo restart del daemon.
Job non-root, rootfs read-only, senza capability; dump montato solo nel job.
Import SQL autenticato via socket Unix privato, senza bypass TLS o grant.
Stato/checksum/lock nel DB impediscono reimport, concorrenza e retry distruttivi.

Impostare `NAPAY_IMPORT_DIR` con directory privata esterna contenente
`napay-v2.sql` e `manifest.json` revisionati. UID/GID bootstrap devono coincidere
col proprietario dei file. Non committare o stampare dataset/chiavi.

```sh
./docker/up.sh .env --check
./docker/up.sh .env
```

Prima occorrono dipendenze runtime Napay installate/verificate e chiavi
originali, non generate dal bootstrap. Il build corrente non risolve da solo
il vendor incompleto; il job fallisce chiuso se mancano dipendenze necessarie.

```sh
docker build --pull=false -t napay/bootstrap:dev -f docker/Dockerfile.bootstrap .
NAPAY_TEST_DATASET=/percorso/dataset-v2 NAPAY_TEST_VENDOR=/percorso/vendor-test \
  sh tests/docker/bootstrap.sh
```

Suite locale: 32 controlli PHP e 2 Compose, su dati v2 in DB temporanei.
Vendor Yii isolato e writer CLI fittizio: non e uno smoke test HTTP del sito.
Dettagli: `napay-project/tips/TIP-019/bootstrap-automatico.md`.

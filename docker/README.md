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

```sh
cp .env.example .env
chmod 600 .env
# Impostare credenziali e chiavi prima dell'avvio.
docker compose up -d --build
```

Il compose locale legge `.env` a runtime, senza build args DB. Espone soltanto
HTTP diagnostico su `127.0.0.1:18120`, parametrizzabile con `NAPAY_HTTP_PORT`.
L'accesso normale HTTPS passa dal gateway Caddy della piattaforma; niente
certificati da configurare nell'applicazione. Non esporre la porta diagnostica
su Internet. `NAPAY_ENV_FILE` permette un file runtime alternativo al compose.

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

Anche le precedenti variabili compose `MYSQL_*` vanno sostituite con la terna
`DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`. Non e previsto fallback automatico a
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

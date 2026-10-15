# Running with docker compose

One `docker-compose.yml`, one container per service: `nginx`, `app` (php-fpm + cron), `db` (MySQL 8), `redis`, `certbot` (renewal loop). No `install.sh` needed.

Requirements: Docker Engine with the compose plugin, `make` (optional but recommended), a domain whose DNS points at the server, ports 80/443 open.

## Quick start

```sh
make setup                  # creates .env from .env.example and starts the stack
# edit .env: DOMAIN, MYSQL_*, TELEGRAM_BOT_TOKEN, TELEGRAM_ADMIN_ID
make restart
make cert                   # issue real Let's Encrypt certificate
```

Without make:

```sh
cp .env.example .env
docker compose up -d --build
```

On start, the `app` container templates `config.php`, creates/updates the schema (`table.php`) and registers the Telegram webhook. `certinit` creates a temporary self-signed cert so nginx can boot.

## Real certificate

DNS for `DOMAIN` must point here and port 80 must be reachable.

```sh
make cert
# or
docker compose --profile certissue run --rm certissue
docker compose exec nginx nginx -s reload
```

The `certbot` service renews automatically every 12h. Force it with `make cert-renew`.

## Makefile commands

Run `make help` for the full list. `make <target>`:

| Group | Targets |
| --- | --- |
| Setup | `setup`, `env` |
| Stack | `up`, `start`, `build`, `rebuild`, `down`, `stop`, `restart`, `ps` |
| Logs | `logs`, `logs-app`, `logs-nginx`, `logs-db` (`TAIL=200`) |
| Shells | `shell` (`SERVICE=app`), `php-shell`, `db-shell`, `redis-cli` |
| App | `table` (schema migrate), `webhook` (re-register Telegram webhook) |
| TLS / nginx | `cert`, `cert-renew`, `cert-info`, `nginx-test`, `nginx-reload` |
| phpMyAdmin | `pma-up`, `pma-down` |
| Database | `db-backup`, `db-restore FILE=backups/x.sql.gz` |
| Maintenance | `pull`, `update` (git pull + rebuild), `clean`, `nuke` |

`nuke` deletes the database and certificates (asks for confirmation). `db-restore` overwrites the database (asks for confirmation).

## Updating

```sh
make update                 # git pull --ff-only, rebuild, recreate containers
```

## Backups

```sh
make db-backup              # writes backups/<db>-<timestamp>.sql.gz
make db-restore FILE=backups/faoxima-20260101-120000.sql.gz
```

## phpMyAdmin (optional)

```sh
make pma-up                 # served at https://DOMAIN/phpmyadmin/
make pma-down
```

## Notes

- `install.sh` renders nginx config into `./nginx/conf.d`; this compose file renders it from `docker/nginx/*.template` instead, so extra bots added via `install.sh` are not picked up when using this flow.
- `.env` is read by the Makefile for DB credentials; avoid unquoted spaces or `#` in values.

# Deploying with Docker

metaglot is a one-shot CLI, not a daemon: something on the host — cron — starts a
container, the container processes the channels and exits. `docker-compose.yml`
therefore defines two services:

| Service | What it is | Ports |
|---|---|---|
| `app` | the metaglot image, built from the `Dockerfile`; `restart: "no"` | none — nothing listens |
| `db` | `postgres:16-alpine` with a named volume (`pgdata`) and a health check | none — reachable only from `app` on the compose network |

The database is deliberately **not published to the host** and must never be
exposed to the internet: it holds OAuth refresh tokens and client secrets.
Everything you need to do against it goes through `docker compose exec`.

## 1. Configure

```sh
cp .env.example .env
```

Set at least `PG_PASS` — the database container refuses to start without a password,
and the compose file refuses to start without it being set. The other values have
container-aware defaults, so a minimal `.env` is:

```sh
PG_PASS=<a long random password>
```

Defaults applied when a value is empty or missing from `.env`:

| Variable | Default inside compose | Notes |
|---|---|---|
| `PG_DSN` | `pgsql:host=db;dbname=metaglot` | `db` is the compose service name |
| `PG_USER` | `metaglot` | also used to create the database user |
| `LLM_ENDPOINT` | `http://host.docker.internal:11434/v1/chat/completions` | an Ollama running **on the host** |
| `LLM_MODEL` | `qwen2.5:14b-instruct` | application default |
| `LLM_KEY` | *(empty)* | not needed for Ollama |

**Ollama on the host, Linux:** Ollama binds to `127.0.0.1` by default, which a
container cannot reach through `host.docker.internal`. Make it listen on all
interfaces (`OLLAMA_HOST=0.0.0.0:11434` in its environment) and keep port 11434
closed in your firewall so only local containers can reach it. On Docker Desktop
(macOS / Windows) the default binding already works. To point at a hosted
OpenAI-compatible API instead, set `LLM_ENDPOINT`, `LLM_MODEL` and `LLM_KEY` in
`.env`.

## 2. Build

```sh
docker compose build
```

The image installs only production dependencies (`composer install --no-dev`) and
runs as an unprivileged user. `.dockerignore` keeps `vendor/`, `.git`, `.env`,
tests and docs out of the build context — the image never contains your `.env`.

## 3. Start the database and apply the migration

```sh
docker compose up -d db
```

`migrations/` is mounted into the container's `/docker-entrypoint-initdb.d`, so on
the **first** start of a fresh volume PostgreSQL applies `001_initial.sql` by itself.
Check with:

```sh
docker compose exec db psql -U metaglot -d metaglot -c '\dt'
```

For an existing volume, or any migration added after the volume was created, apply
the file explicitly — the schema uses `IF NOT EXISTS`, so re-running is harmless:

```sh
docker compose exec -T db psql -U metaglot -d metaglot < migrations/001_initial.sql
```

(`-T` disables the pseudo-terminal so the file can be piped on stdin.)

## 4. Register a channel

Follow [SETUP.md](SETUP.md) to obtain the OAuth client and refresh token, then
insert the channel through the database container:

```sh
docker compose exec db psql -U metaglot -d metaglot -c "
INSERT INTO channels (label, refresh_token, client_id, client_secret, source_lang, target_langs)
VALUES ('my-channel', '<refresh-token>', '<client-id>', '<client-secret>',
        'tr', '{en,es,ar,de,fr,pt,hi,id,ja,ru}');"
```

Take the `id` it was given:

```sh
docker compose exec db psql -U metaglot -d metaglot -c 'SELECT id, label, active FROM channels;'
```

## 5. Dry run

Everything after `app` is passed straight to `bin/metaglot`:

```sh
docker compose run --rm app --channel=1 --dry-run
```

`run` starts `db` if it is not already up and waits for its health check. Read the
titles it would write; when they look right, run for real:

```sh
docker compose run --rm app --channel=1
```

## 6. Cron on the host

Runs are idempotent, so an hourly invocation is the normal deployment. On the host:

```cron
0 * * * * cd /opt/metaglot && docker compose run --rm -T app --all >> /var/log/metaglot.log 2>&1
```

- `-T` disables TTY allocation, which cron does not have.
- `--rm` removes the finished container; nothing accumulates between runs.
- `restart: "no"` on `app` means Docker never restarts it on its own — cron is the
  only thing that starts it.
- The container exits `0` when every channel processed cleanly and `1` when any
  channel failed; a quota-exhausted channel logs `QUOTA:` and is simply retried at
  the next invocation. Point your monitoring at the exit code and the log file.

## Other operations

```sh
docker compose run --rm app channel:delete --channel=1          # preview
docker compose run --rm app channel:delete --channel=1 --force  # delete channel + tokens + history
docker compose exec db pg_dump -U metaglot metaglot > backup.sql # back up (contains OAuth tokens — treat as secret)
docker compose down                                             # stop; the pgdata volume is kept
docker compose down -v                                          # stop AND delete all data
```

## Updating

```sh
git pull
docker compose build
docker compose exec -T db psql -U metaglot -d metaglot < migrations/<new-file>.sql   # if the release added one
```

The next cron invocation uses the new image.

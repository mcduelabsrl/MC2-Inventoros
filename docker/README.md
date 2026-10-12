# Running Inventoros with Docker

Single-image dev stack (built from `docker/dev.Dockerfile`):
- FrankenPHP (Caddy + PHP-FPM in one binary) serves the Laravel app.
- A worker and a scheduler container run the queue and `schedule:run`.
- PostgreSQL holds the data.
- Mailpit catches outgoing email.

Redis is opt-in through a Compose profile.

## Quick start

```bash
# From the repo root
docker compose up --build
```

When the app container is ready (~60s on first build), open http://localhost:8080/install and complete the web installer:

- **Database step:** it is already filled in for the bundled PostgreSQL (host `postgres`, port `5432`, database and user `inventoros`). Enter the password `inventoros`.
- **Admin step:** create your administrator.

| URL | What |
|---|---|
| http://localhost:8080 | Inventoros app |
| http://localhost:8080/api/v1 | REST API |
| http://localhost:8080/mcp | MCP server (POST + Bearer token) |
| http://localhost:8080/docs/api | Stoplight Elements API browser |
| http://localhost:8025 | Mailpit web UI |

You do not need a `.env` file to run the stack. Each container writes its own from `.env.example` on first boot. The database settings and the app's address come from `docker-compose.yml`.

## Database

The app, worker and scheduler all connect to the `postgres` service through environment variables set in `docker-compose.yml`, so they always use the same database. The data persists in the `postgres-data` volume.

To change the credentials, set these before the first `docker compose up`, in your shell or in a `.env` next to `docker-compose.yml`:

| Variable | Default |
|---|---|
| `POSTGRES_DB` | `inventoros` |
| `POSTGRES_USER` | `inventoros` |
| `POSTGRES_PASSWORD` | `inventoros` |
| `POSTGRES_PORT` | `5432` (port published on your machine) |

They are deliberately not called `DB_*`. Compose reads `${...}` values from the `.env` next to `docker-compose.yml`, which is also Laravel's `.env` when you run the app without Docker. A `DB_CONNECTION=sqlite` line there must not leak into the containers.

The web installer only supports MySQL/MariaDB and PostgreSQL, which is why PostgreSQL is part of the default stack.

## The application key

The first container to start generates the `APP_KEY` and saves it in the shared storage volume (`storage/.docker-app-key`). The worker and scheduler, and any container recreated later (`docker compose up --build`), use the same key.

This matters because the key encrypts sessions and stored secrets, such as plugin credentials. If the containers had different keys, queued jobs could not read those secrets. If the key changed on every rebuild, everyone would be signed out and the stored secrets would be lost.

To use a key of your own, set `APP_KEY` in the environment of the three PHP services (for example in a `docker-compose.override.yml`).

## Starting over

```bash
docker compose down -v   # removes the containers and every volume: database, storage, key
docker compose up --build
```

## With Redis

```bash
docker compose --profile redis up --build
```

Then give the three PHP services `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis` and `REDIS_HOST=redis`, in a `docker-compose.override.yml`:

```yaml
x-redis: &redis
  CACHE_STORE: redis
  SESSION_DRIVER: redis
  QUEUE_CONNECTION: redis
  REDIS_HOST: redis

services:
  app: { environment: *redis }
  worker: { environment: *redis }
  scheduler: { environment: *redis }
```

## Plugins

Plugin code is baked into the image. After activating or deactivating a plugin, restart the long-running containers so they load it: `docker compose restart worker scheduler`.

## Common tasks

```bash
# Open a shell inside the running app container
docker compose exec app bash

# Run artisan commands
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan tinker

# Run the test suite
docker compose exec app php artisan test

# Build / re-build assets
docker compose exec app npm run build
```

## Customising

- **Port** — set `APP_PORT=8088` in a `.env` next to `docker-compose.yml` (or in your shell) before `docker compose up` to expose the app on a different host port. `APP_URL` follows it (`http://localhost:8088`); set `APP_URL` yourself if you open the app by another name or IP. Only change `APP_PORT`, not the `ports:` mapping: the app listens on port 80 inside the container.
- **Behind a reverse proxy** — proxies on private networks (the Docker host, another container) are trusted by default through `TRUSTED_PROXIES=private_ranges`, so `X-Forwarded-Proto: https` produces https URLs. See "Behind a reverse proxy" in `docs/site/sections/installation-docker.md`.
- **Mailpit ports** — `MAILPIT_PORT=8025`, `MAILPIT_SMTP_PORT=1025` (defaults).
- **PHP version** — change `PHP_VERSION` build arg in `docker-compose.yml`. Tested on 8.4 (matches CI). Inventoros requires PHP 8.4.1 or newer.

## Production

This stack is for development only. For production use the multi-stage `Dockerfile` at the repo root (published as `ghcr.io/inventoros/inventoros` for every release from v2.0.0; `docker compose -f docker-compose.prod.yml up -d --build` builds it from the checkout instead) with `docker-compose.prod.yml`: non-root, `APP_DEBUG=false`, caches built at start, migrations only when `RUN_MIGRATIONS=true`, and dedicated worker/scheduler containers on PostgreSQL. See `docs/site/sections/installation-docker.md`.

## Troubleshooting

- **The installer's database step asks for a MySQL/MariaDB server** — the checkout predates the bundled PostgreSQL. Update it and run `docker compose down -v && docker compose up --build`.
- **Links point to `http://localhost` without the port** — a `.env` next to `docker-compose.yml` sets `APP_URL` (the one copied from `.env.example` does), and Compose passes it to the containers. Remove that line or set the full address.
- **`docker compose ps` shows a container as unhealthy** — only `app` and `mailpit` have health checks; `worker` and `scheduler` run no web server and show as plain `Up`. If an older checkout shows them `unhealthy`, they inherited the FrankenPHP base image's health check: update your checkout. For `app`, `docker inspect --format '{{json .State.Health}}' <container>` shows the failing probe.
- **`storage/` writes fail** — make sure the `app-storage` volume is healthy: `docker volume inspect inventoros_app-storage`. Recreate with `docker compose down -v` if needed.
- **`docker compose up` never finishes the build** — first build downloads ~500 MB (PHP + Node + Composer + npm deps). Use `docker compose build --progress=plain` to watch what's happening.
- **`pcntl` errors at boot** — the image installs `pcntl`, but if you customised `php.ini` you may have disabled it. Check `docker compose exec app php -m | grep pcntl`.

# LAUNCHR

LAUNCHR is an HRMS for small and medium enterprises. It covers employees, attendance, leave, payroll, and related admin settings. The API is Laravel 13. The UI is a React SPA.

Modules, roles, and payroll rules are in [Technical Documentation](docs/TECHNICAL_DOCUMENTATION.md).

## Two ways to run it

Docker Compose is documented below. Standalone PHP, Node, MySQL, nginx, cron, and supervisor are in [Technical Documentation section 10](docs/TECHNICAL_DOCUMENTATION.md#10-deploying-for-a-new-company). Neither path is deprecated.

Standalone still needs PHP 8.3, Composer, MySQL, Node 20, and a mailer on the host. Docker does not replace that install.

## How to run with Docker Compose

You need Docker Compose v2 and a copy of this repo.

### 1. Create `.env`

```bash
cp .env.example .env
```

Set `APP_KEY`. Do not leave it empty.

```bash
echo "base64:$(openssl rand -base64 32)"
```

Paste the printed value into `APP_KEY`.

Set `APP_URL`, `FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` to the origin browsers will use. For a published local port that is `http://localhost:8080`. Behind TLS on a real hostname, use `https://that-host`.

Mail uses `MAIL_*` as written. There is no Mailpit service.

`backend/.env` is for native PHP. Compose reads the root `.env` only.

### 2. Choose MySQL

Leave `DB_HOST` empty to start a MySQL 8 container named `mysql`. Data lives in the `mysql_data` volume.

To use MySQL on the Docker host:

```env
DB_HOST=host.docker.internal
DB_PORT=3306
DB_DATABASE=launchr
DB_USERNAME=root
DB_PASSWORD=your-password
```

To use MySQL in another Compose project, set `DB_HOST` to that container's hostname. Attach a shared network with `PROXY_NETWORK` or `docker network connect`. This repo does not assume a network name.

### 3. Start the stack

First boot runs `php artisan migrate --force` only. It does not seed. Seed later with `docker compose exec php php artisan db:seed` or `db:seed --class=DemoSeeder`. See `backend/database/seeders/DatabaseSeeder.php` before you seed an admin user.

Local HTTP on port 8080 (pick another port if 8080 is already in use):

```bash
APP_PORT=8080 ./scripts/compose.sh up --build
```

Open `http://localhost:8080`. The nginx service `web` serves the SPA and `/api` on one origin. This stack does not publish ports 80 or 443.

To publish nothing, unset `APP_PORT` in `.env`. Then only an attached proxy can reach `web:80`.

Production images (no Vite):

```bash
COMPOSE_MODE=prod APP_PORT=8080 ./scripts/compose.sh up --build
```

Or pass files yourself:

```bash
./scripts/compose.sh -f compose.yaml -f compose.prod.yaml up --build
```

`scripts/compose.sh` still adds publish and proxy overlays from env when you pass `-f`.

### 4. Attach an existing TLS proxy

Set `PROXY_NETWORK` to the existing Docker network name. Let's Encrypt stays on that proxy. Point the hostname at service `web` port 80.

Caddy:

```caddy
hr.example.com {
    reverse_proxy web:80
}
```

Traefik should route `Host(hr.example.com)` to `http://web:80`.

### 5. Vite HMR in the dev overlay

The default overlay bind-mounts `backend/` and `frontend/` and runs Vite behind nginx. If the browser uses `APP_PORT` over HTTP, keep `VITE_HMR_PROTOCOL=ws` and `VITE_HMR_CLIENT_PORT` equal to `APP_PORT`.

If the browser uses HTTPS on the existing proxy:

```env
VITE_HMR_PROTOCOL=wss
VITE_HMR_CLIENT_PORT=443
```

## How to cut over a live host to Docker

Use this when LAUNCHR already runs on host PHP, nginx, MySQL, cron `schedule:run`, and supervisor `queue:work`. The goal is `COMPOSE_MODE=prod` without wiping data. For a first-time Compose boot, stay with [How to run with Docker Compose](#how-to-run-with-docker-compose). To keep the host PHP path and only pull a new release, follow [How to update a live standalone host](docs/TECHNICAL_DOCUMENTATION.md#11-how-to-update-a-live-standalone-host).

1. Dump the live database. Copy `backend/storage`. Copy `backend/public/system_logo_*` and `backend/public/payroll_template_*`.

2. Keep the host MySQL. Set `DB_HOST=host.docker.internal`, or the existing database hostname. Do not leave `DB_HOST` empty. An empty `DB_HOST` starts a new MySQL container with an empty volume.

3. Copy `APP_KEY` and `MAIL_*` from `backend/.env` into the root `.env`. Do not run `php artisan key:generate`. Do not invent a new `APP_KEY`. Existing Sanctum tokens and encrypted rows use the current key.

4. Set `APP_URL`, `FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` to the existing public HTTPS origin.

5. Set `COMPOSE_MODE=prod`. Unset `APP_PORT`. Set `PROXY_NETWORK` to the existing proxy Docker network. Point Caddy or Traefik at `web:80`. This stack does not publish ports 80 or 443.

6. Start Compose so the `php_storage` volume exists.

   ```bash
   COMPOSE_MODE=prod ./scripts/compose.sh up --build -d
   ```

   `compose.prod.yaml` mounts an empty named volume `php_storage` at `/var/www/backend/storage`. Without an import, DTRs and other files under host `backend/storage` disappear from the container. The prod image also does not see host `backend/public/`, so copy logos and payroll templates into `storage/app/public`.

   ```bash
   HOST_STORAGE=/var/www/launchr-hr/backend/storage \
     HOST_PUBLIC=/var/www/launchr-hr/backend/public \
     COMPOSE_MODE=prod ./scripts/import-host-storage.sh
   ```

7. Stop the host crontab `schedule:run` and the host supervisor `queue:work` before you leave the containers running. Two schedulers double `attendance:auto-clock-out` and `attendance:mark-absent`.

8. Confirm the php entrypoint ran `php artisan migrate --force` only. Never run `migrate:fresh`. Never run `migrate --seed`.

9. Smoke-test `GET /up`, a login, one clock-in, and one queued email.

## What `scripts/compose.sh` does

It loads root `.env`, then:

- enables `--profile mysql` when `DB_HOST` is empty and sets `DB_HOST=mysql`
- adds `compose.publish.yaml` when `APP_PORT` is set
- adds `compose.proxy.yaml` when `PROXY_NETWORK` is set
- uses `compose.dev.yaml` unless `COMPOSE_MODE=prod` or you pass `-f`

Do not start with raw `docker compose up` if you want those rules.

## Native commands (unchanged)

From `backend/`:

```bash
composer run dev
```

That still starts `php artisan serve` on port 8000, the queue worker, and Vite on port 5173.

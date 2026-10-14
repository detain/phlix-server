# Phlix docker-compose Examples

This directory contains example docker-compose stacks for different deployment scenarios.

## Quick Start

1. Copy `.env.example` to `.env` and fill in your values. The three database
   password lines are `${VAR:?…}` self-gates: until you replace them with real
   values, `docker compose` refuses to even parse the stack (same loud-over-
   silent design as the image pins). Set `MYSQL_ROOT_PASSWORD`,
   `PHLIX_DB_PASSWORD`, and — for the hub scenarios — `HUB_DB_PASSWORD` to
   freshly generated values (`openssl rand -hex 16`).
2. Set the two image pins — `PHLIX_SERVER_IMAGE` and `PHLIX_HUB_IMAGE` — in `.env`
   (forms in the table below). The composes hard-fail without them, by design:
   loud over silent — mutable tags drift, so the examples refuse to resolve a
   moving image and stay reproducible.
3. Choose a scenario:
   - `server-only/` — Standalone phlix-server with MySQL
   - `server-hub/` — Phlix server + phlix hub for remote access
   - `full-stack/` — Complete setup with Traefik reverse proxy

4. Start with `docker-compose up -d`

## Scenarios

### Server Only (`server-only/`)

Minimal setup for local-only access. Includes:
- phlix-server container
- MySQL 8.0 database
- Persistent volumes for config, data, backups

### Server + Hub (`server-hub/`)

Adds the phlix-hub relay service for remote access. Includes:
- phlix-server container
- phlix-hub container
- Separate MySQL instances for server and hub
- Network isolation between services

### Full Stack with Traefik (`full-stack/`)

Production setup with Traefik handling HTTPS, WebSocket relay, and routing. Includes:
- Traefik reverse proxy with automatic HTTPS
- phlix-server with ingress labels
- phlix-hub with ingress labels
- Relay endpoint for WebSocket tunneling
- Separate MySQL instances

## Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `PHLIX_SERVER_IMAGE` | Server image pin — immutable tag, form `ghcr.io/detain/phlix-server:<full-sha>-<latest|intel|nvidia>` | **Yes** |
| `PHLIX_HUB_IMAGE` | Hub image pin — immutable tag, form `ghcr.io/detain/phlix-hub:<sha12>`; the published hub `:latest` is currently stale, so pin explicitly | **Yes** |
| `MYSQL_ROOT_PASSWORD` | MySQL root password — compose interpolates `${MYSQL_ROOT_PASSWORD:?…}`; unset/empty is refused | Yes |
| `PHLIX_DB_PASSWORD` | Phlix server database password — `${PHLIX_DB_PASSWORD:?…}` hard-fail likewise | Yes |
| `HUB_DB_PASSWORD` | Phlix hub database password — `${HUB_DB_PASSWORD:?…}` hard-fail in the hub scenarios | Yes |
| `PHLIX_SECRET_KEY` | JWT + media signed-URL signing key. **Leave UNSET** unless you manage the key yourself — see below. | **No** |
| `HUB_SECRET_KEY` | Carrier name for the hub's real secret: `server-hub/` and `full-stack/` wire it into the hub container as `HUB_JWT_SECRET` (the only hub signing-key env). Leave commented unless you want a stable key — see below. | **No** |
| `PHLIX_HUB_PAIRING_CODE` | Server pairing code for hub | No |

### About the secret keys

`PHLIX_SECRET_KEY` used to be listed as **Required: Yes** and `.env.example`
shipped it as `change_me_generate_with_openssl`. That combination handed every
reader of this repository the same, publicly greppable key — and it signs both
JWTs and media signed URLs, so it is admin-token forgery and signed-media-URL
forgery in one variable.

`docker/docker-entrypoint.sh` now **refuses to start** on that value and on
every other obvious placeholder (`change_me*`, `changeme*`, `change-me*`,
`secret`, `password`, `placeholder`, `example`, `test`, …). Leave the variable
unset and the entrypoint generates a 256-bit key on first boot and persists it
to `/var/phlix/config/jwt_secret` — a named volume in every scenario here, so
it survives restarts. Set it only if you want to manage the key yourself:

```bash
PHLIX_SECRET_KEY=$(openssl rand -hex 32)
```

The hub's real signing-key env is **`HUB_JWT_SECRET`**; `HUB_SECRET_KEY` is
only the `.env` carrier name the `server-hub/` and `full-stack/` composes map
onto it. Unset, the hub generates a key and persists it under
`/var/phlix/config` inside the container — a path these examples do NOT mount
as a volume, so the key would change on every recreate and invalidate every
enrollment/session. If you keep the hub longer than a smoke test, either set
`HUB_SECRET_KEY=$(openssl rand -hex 32)` in `.env` or add a volume covering
the hub's persistence path.

## Ports and Publishing

What the daemons ACTUALLY bind (start.php / hub config — verified, not
aspirational):

| Service | Container port | Protocol |
|---------|----------------|----------|
| phlix-server HTTP (REST + `/app` SPA + `/health`) | `8096` | plain HTTP, ZERO WebSocket-upgrade logic |
| phlix-server SyncPlay WebSocket | `8097` | SEPARATE plaintext WS listener — NOT a path on 8096; a plain HTTP GET there answers `400` and that means ALIVE |
| phlix-hub HTTP (API + admin UI + `/health`) | `8800` (`HUB_PORT`) | plain HTTP |
| phlix-hub relay WebSocket | `8802` | separate fixed listener — no env moves it; hub-side TLS only via `HUB_RELAY_TLS` + `HUB_RELAY_TLS_CERT` + `HUB_RELAY_TLS_KEY` |

The server composes publish **`127.0.0.1:` only** by default. Docker
published ports bypass host firewalls like ufw, so a bare `"8096:8096"` is an
internet-facing plaintext service regardless of your ufw rules; drop the
`127.0.0.1:` prefix only knowingly on a trusted network. For real
exposure front the loopback ports with the TLS reverse proxies in this repo
(`reverse-proxy/`, `deploy/haproxy.cfg`) — note those front :8096 as HTTP and
:8097 as its own TCP route; there is no same-origin `wss://` path today (a
client-side `wsUrl` seam is a separate effort). The `full-stack/` example is
the exception by design: Traefik is the TLS edge, so its 80/443 and the hub
relay 8802 are published publicly and the MySQLs stay unpublished.

## Migrations Posture

Since R14-12 the runtime images ship `ENV PHLIX_MIGRATIONS_STRICT=1` and the
server/hub examples set the strict flag explicitly (`PHLIX_MIGRATIONS_STRICT`
server-side, `HUB_MIGRATIONS_STRICT` hub-side): a failed or unrunnable
migration **refuses to start** the container (banners
`PHLIX-MIGRATION-FAILURE` / `PHLIX-MIGRATIONS-NOT-RUN` /
`PHLIX-HUB-MIGRATION-FAILURE`) instead of serving a half-migrated schema.
Dev opt-out (boot anyway with the loud banner): `-e
PHLIX_MIGRATIONS_STRICT=0` / `-e HUB_MIGRATIONS_STRICT=0`.

## Networking

All scenarios use a bridge network named `phlix_network` for container communication.

## Volumes

| Volume | Description |
|--------|-------------|
| `phlix_config` | Server configuration |
| `phlix_data` | Server data files |
| `phlix_backups` | Backup storage |
| `phlix_logs` | Log files |
| `media_library` | Media files (read-only) |
| `mysql_data` | MySQL data directory |

## Health Checks

- phlix-server: `curl http://localhost:8096/health` (the Workerman daemon serves
  this itself; nothing listens on port 80 in these images)
- MySQL: `mysqladmin ping`

## Troubleshooting

### Container won't start

Check logs:
```bash
docker-compose logs phlix
docker-compose logs mysql
```

### The container exited — what the exit code means

```bash
docker inspect -f '{{.State.ExitCode}}' phlix-server
```

| exit code | meaning |
|---|---|
| `0` | clean shutdown (`docker compose stop`, SIGTERM) |
| **`70`** | **a supervised program entered FATAL** — the application is not running and supervisord gave up retrying it. Look for the `PHLIX-SUPERVISOR-FATAL` banner in `docker compose logs phlix`, then `/var/phlix/logs/phlix-error.log`. Common causes: a placeholder `PHLIX_SECRET_KEY` (the entrypoint refuses it), or the database never becoming reachable. |
| other | supervisord's own exit status |

Before this existed, a FATAL left the container reporting `Up` with nothing
serving. It now stops instead — and because every compose file here uses
`restart: unless-stopped`, Docker restarts it, so a persistent fault shows up
as a **restart loop that re-runs the migration chain each cycle** rather than a
silent `Up`. If you would rather it stay down where monitoring can see it,
change the `phlix` service to `restart: on-failure:5`.

### Can't connect to database

Verify environment variables are set correctly in `.env` file.

### Media not scanning

Ensure `media_library` volume is mounted correctly and contains media files.

## Generating Secrets

```bash
# Generate a secure secret key
openssl rand -hex 32
```

## Production Considerations

1. **Change all default passwords**
2. **Use strong secret keys**
3. **Configure proper backup strategy**
4. **Set up TLS certificates for production**
5. **Use Docker secrets for sensitive data in Swarm mode**

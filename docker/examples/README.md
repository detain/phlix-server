# Phlix docker-compose Examples

This directory contains example docker-compose stacks for different deployment scenarios.

## Quick Start

1. Copy `.env.example` to `.env` and fill in your values
2. Choose a scenario:
   - `server-only/` — Standalone phlix-server with MySQL
   - `server-hub/` — Phlix server + phlix hub for remote access
   - `full-stack/` — Complete setup with Traefik reverse proxy

3. Start with `docker-compose up -d`

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
| `MYSQL_ROOT_PASSWORD` | MySQL root password | Yes |
| `PHLIX_DB_PASSWORD` | Phlix server database password | Yes |
| `HUB_DB_PASSWORD` | Phlix hub database password | Yes |
| `PHLIX_SECRET_KEY` | JWT + media signed-URL signing key. **Leave UNSET** unless you manage the key yourself — see below. | **No** |
| `HUB_SECRET_KEY` | Hub application secret. Not read by any code today; leave unset. | **No** |
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

`HUB_SECRET_KEY` is passed through to the hub container by `server-hub/` and
`full-stack/`, but no code in either repository reads it today. It shipped with
the same committed placeholder — i.e. exactly the latent form of the defect
above, one variable over — so it is now unset in `.env.example` too. If it ever
becomes load-bearing, generate it the same way.

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

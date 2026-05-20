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
| `PHLIX_SECRET_KEY` | Application secret key | Yes |
| `HUB_SECRET_KEY` | Hub application secret | Yes |
| `PHLIX_HUB_PAIRING_CODE` | Server pairing code for hub | No |

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

- phlix-server: `curl http://localhost/health`
- MySQL: `mysqladmin ping`

## Troubleshooting

### Container won't start

Check logs:
```bash
docker-compose logs phlix
docker-compose logs mysql
```

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

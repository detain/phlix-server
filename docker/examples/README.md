# Phlex docker-compose Examples

This directory contains example docker-compose stacks for different deployment scenarios.

## Quick Start

1. Copy `.env.example` to `.env` and fill in your values
2. Choose a scenario:
   - `server-only/` — Standalone phlex-server with MySQL
   - `server-hub/` — Phlex server + phlex hub for remote access
   - `full-stack/` — Complete setup with Traefik reverse proxy

3. Start with `docker-compose up -d`

## Scenarios

### Server Only (`server-only/`)

Minimal setup for local-only access. Includes:
- phlex-server container
- MySQL 8.0 database
- Persistent volumes for config, data, backups

### Server + Hub (`server-hub/`)

Adds the phlex-hub relay service for remote access. Includes:
- phlex-server container
- phlex-hub container
- Separate MySQL instances for server and hub
- Network isolation between services

### Full Stack with Traefik (`full-stack/`)

Production setup with Traefik handling HTTPS, WebSocket relay, and routing. Includes:
- Traefik reverse proxy with automatic HTTPS
- phlex-server with ingress labels
- phlex-hub with ingress labels
- Relay endpoint for WebSocket tunneling
- Separate MySQL instances

## Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `MYSQL_ROOT_PASSWORD` | MySQL root password | Yes |
| `PHLEX_DB_PASSWORD` | Phlex server database password | Yes |
| `HUB_DB_PASSWORD` | Phlex hub database password | Yes |
| `PHLEX_SECRET_KEY` | Application secret key | Yes |
| `HUB_SECRET_KEY` | Hub application secret | Yes |
| `PHLEX_HUB_PAIRING_CODE` | Server pairing code for hub | No |

## Networking

All scenarios use a bridge network named `phlex_network` for container communication.

## Volumes

| Volume | Description |
|--------|-------------|
| `phlex_config` | Server configuration |
| `phlex_data` | Server data files |
| `phlex_backups` | Backup storage |
| `phlex_logs` | Log files |
| `media_library` | Media files (read-only) |
| `mysql_data` | MySQL data directory |

## Health Checks

- phlex-server: `curl http://localhost/health`
- MySQL: `mysqladmin ping`

## Troubleshooting

### Container won't start

Check logs:
```bash
docker-compose logs phlex
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

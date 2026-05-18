# Step O.2 — docker-compose Example Stacks

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.2
**Depends on:** O.1 (Docker images)
**Review:** Yes — see `o.2-compose-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Create **docker-compose example stacks** for three scenarios: server-only, server+hub, and full stack with Traefik reverse proxy.

## 2. Context (what already exists)

Read first:

- `docker/Dockerfile` — base Docker image from O.1.
- `docker/docker-compose.yml` — basic test compose.
- `config/` — existing config structure.

## 3. Scope — files to create

### `docker/examples/server-only/docker-compose.yml`

```yaml
version: '3.8'

services:
  phlex:
    image: ghcr.io/detain/phlex-server:latest
    container_name: phlex-server
    ports:
      - "32400:80"
    environment:
      - PHLEX_DATABASE_HOST=mysql
      - PHLEX_DATABASE_PORT=3306
      - PHLEX_DATABASE_NAME=phlex
      - PHLEX_DATABASE_USER=phlex
      - PHLEX_DATABASE_PASSWORD=${PHLEX_DB_PASSWORD}
      - PHLEX_SECRET_KEY=${PHLEX_SECRET_KEY}
      - PHLEX_LOG_LEVEL=info
    volumes:
      - phlex_config:/var/phlex/config
      - phlex_data:/var/phlex/data
      - phlex_backups:/var/phlex/backups
      - phlex_logs:/var/phlex/logs
      - media_library:/media:ro
    depends_on:
      mysql:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - phlex_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3

  mysql:
    image: mysql:8.0
    container_name: phlex-mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: phlex
      MYSQL_USER: phlex
      MYSQL_PASSWORD: ${PHLEX_DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped
    networks:
      - phlex_network

volumes:
  phlex_config:
  phlex_data:
  phlex_backups:
  phlex_logs:
  media_library:
  mysql_data:

networks:
  phlex_network:
    driver: bridge
```

### `docker/examples/server-hub/docker-compose.yml`

```yaml
version: '3.8'

services:
  # Phlex Hub (cloud relay service)
  phlex-hub:
    image: ghcr.io/detain/phlex-hub:latest
    container_name: phlex-hub
    ports:
      - "3443:443"
    environment:
      - HUB_DATABASE_HOST=mysql-hub
      - HUB_DATABASE_PORT=3306
      - HUB_DATABASE_NAME=phlex_hub
      - HUB_DATABASE_USER=phlex_hub
      - HUB_DATABASE_PASSWORD=${HUB_DB_PASSWORD}
      - HUB_SECRET_KEY=${HUB_SECRET_KEY}
      - HUB_RELAY_ENABLED=true
      - HUB_RELAY_PORT=3473
    volumes:
      - hub_config:/var/phlex-hub/config
      - hub_logs:/var/phlex-hub/logs
    depends_on:
      mysql-hub:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - phlex_network

  # Phlex Server (local media server)
  phlex-server:
    image: ghcr.io/detain/phlex-server:latest
    container_name: phlex-server
    ports:
      - "32400:80"
    environment:
      - PHLEX_DATABASE_HOST=mysql-server
      - PHLEX_DATABASE_PORT=3306
      - PHLEX_DATABASE_NAME=phlex
      - PHLEX_DATABASE_USER=phlex
      - PHLEX_DATABASE_PASSWORD=${PHLEX_DB_PASSWORD}
      - PHLEX_SECRET_KEY=${PHLEX_SECRET_KEY}
      - PHLEX_HUB_URL=https://hub.example.com
      - PHLEX_HUB_PAIRING_CODE=${PHLEX_HUB_PAIRING_CODE}
      - PHLEX_LOG_LEVEL=info
    volumes:
      - phlex_config:/var/phlex/config
      - phlex_data:/var/phlex/data
      - phlex_backups:/var/phlex/backups
      - phlex_logs:/var/phlex/logs
      - media_library:/media:ro
    depends_on:
      mysql-server:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - phlex_network

  mysql-hub:
    image: mysql:8.0
    container_name: phlex-hub-mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: phlex_hub
      MYSQL_USER: phlex_hub
      MYSQL_PASSWORD: ${HUB_DB_PASSWORD}
    volumes:
      - mysql_hub_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped
    networks:
      - phlex_network

  mysql-server:
    image: mysql:8.0
    container_name: phlex-server-mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: phlex
      MYSQL_USER: phlex
      MYSQL_PASSWORD: ${PHLEX_DB_PASSWORD}
    volumes:
      - mysql_server_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped
    networks:
      - phlex_network

volumes:
  phlex_config:
  phlex_data:
  phlex_backups:
  phlex_logs:
  media_library:
  hub_config:
  hub_logs:
  mysql_hub_data:
  mysql_server_data:

networks:
  phlex_network:
    driver: bridge
```

### `docker/examples/full-stack/docker-compose.yml`

```yaml
version: '3.8'

services:
  traefik:
    image: traefik:v3.0
    container_name: traefik
    ports:
      - "80:80"
      - "443:443"
      - "3473:3473"
    environment:
      - TZ=UTC
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./traefik/traefik.yml:/etc/traefik/traefik.yml:ro
      - ./traefik/dynamic.yml:/etc/traefik/dynamic.yml:ro
      - traefik_certs:/etc/traefik/certs
    networks:
      - phlex_network
    restart: unless-stopped

  phlex-hub:
    image: ghcr.io/detain/phlex-hub:latest
    container_name: phlex-hub
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.phlex-hub.rule=Host(`hub.example.com`)"
      - "traefik.http.routers.phlex-hub.tls=true"
      - "traefik.http.services.phlex-hub.loadbalancer.server.port=443"
      - "traefik.tcp.routers.phlex-relay.rule=Host(`hub.example.com`)"
      - "traefik.tcp.routers.phlex-relay.entrypoints=relay"
      - "traefik.tcp.services.phlex-relay.loadbalancer.server.port=3473"
    environment:
      - HUB_DATABASE_HOST=mysql-hub
      - HUB_DATABASE_PORT=3306
      - HUB_DATABASE_NAME=phlex_hub
      - HUB_DATABASE_USER=phlex_hub
      - HUB_DATABASE_PASSWORD=${HUB_DB_PASSWORD}
      - HUB_SECRET_KEY=${HUB_SECRET_KEY}
      - HUB_RELAY_ENABLED=true
      - HUB_RELAY_PORT=3473
    volumes:
      - hub_config:/var/phlex-hub/config
      - hub_logs:/var/phlex-hub/logs
    depends_on:
      mysql-hub:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - phlex_network

  phlex-server:
    image: ghcr.io/detain/phlex-server:latest
    container_name: phlex-server
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.phlex-server.rule=Host(`server.example.com`)"
      - "traefik.http.routers.phlex-server.tls=true"
      - "traefik.http.services.phlex-server.loadbalancer.server.port=80"
    environment:
      - PHLEX_DATABASE_HOST=mysql-server
      - PHLEX_DATABASE_PORT=3306
      - PHLEX_DATABASE_NAME=phlex
      - PHLEX_DATABASE_USER=phlex
      - PHLEX_DATABASE_PASSWORD=${PHLEX_DB_PASSWORD}
      - PHLEX_SECRET_KEY=${PHLEX_SECRET_KEY}
      - PHLEX_HUB_URL=https://hub.example.com
      - PHLEX_LOG_LEVEL=info
    volumes:
      - phlex_config:/var/phlex/config
      - phlex_data:/var/phlex/data
      - phlex_backups:/var/phlex/backups
      - phlex_logs:/var/phlex/logs
      - media_library:/media:ro
    depends_on:
      mysql-server:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - phlex_network

  mysql-hub:
    image: mysql:8.0
    container_name: phlex-hub-mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: phlex_hub
      MYSQL_USER: phlex_hub
      MYSQL_PASSWORD: ${HUB_DB_PASSWORD}
    volumes:
      - mysql_hub_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped
    networks:
      - phlex_network

  mysql-server:
    image: mysql:8.0
    container_name: phlex-server-mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: phlex
      MYSQL_USER: phlex
      MYSQL_PASSWORD: ${PHLEX_DB_PASSWORD}
    volumes:
      - mysql_server_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped
    networks:
      - phlex_network

volumes:
  phlex_config:
  phlex_data:
  phlex_backups:
  phlex_logs:
  media_library:
  hub_config:
  hub_logs:
  mysql_hub_data:
  mysql_server_data:
  traefik_certs:

networks:
  phlex_network:
    driver: bridge
```

### `docker/examples/.env.example`

```bash
# Database passwords (change these!)
MYSQL_ROOT_PASSWORD=change_me_in_production
PHLEX_DB_PASSWORD=change_me_in_production
HUB_DB_PASSWORD=change_me_in_production

# Application secrets (generate with: openssl rand -hex 32)
PHLEX_SECRET_KEY=change_me_generate_with_openssl
HUB_SECRET_KEY=change_me_generate_with_openssl

# Optional: Hub pairing code (get from hub dashboard)
PHLEX_HUB_PAIRING_CODE=
```

### `docker/examples/full-stack/traefik/traefik.yml`

```yaml
api:
  dashboard: true
  insecure: true

entryPoints:
  web:
    address: ":80"
  websecure:
    address: ":443"
  relay:
    address: ":3473"

providers:
  docker:
    endpoint: "unix:///var/run/docker.sock"
    exposedByDefault: false
  file:
    filename: /etc/traefik/dynamic.yml

certificatesResolvers:
  letsencrypt:
    acme:
      email: admin@example.com
      storage: /etc/traefik/certs/acme.json
      httpChallenge:
        entryPoint: web
```

### `docker/examples/full-stack/traefik/dynamic.yml`

```yaml
tcp:
  routers:
    phlex-relay:
      rule: "HostSNI(`*`)"
      service: phlex-relay-service
      passthrough: true

  services:
    phlex-relay-service:
      loadBalancer:
        servers:
          - address: "phlex-hub:3473"
```

### `docker/examples/README.md`

```markdown
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

### Server Only
Minimal setup for local-only access.

### Server + Hub
Adds the phlex-hub relay service for remote access.

### Full Stack with Traefik
Production setup with Traefik handling HTTPS, WebSocket relay, and routing.
```

## 4. Approach

1. Branch from master: `git checkout -b o.2-compose`.
2. Create `docker/examples/` directory structure.
3. Create `server-only/docker-compose.yml` with phlex-server + MySQL.
4. Create `server-hub/docker-compose.yml` with both services.
5. Create `full-stack/docker-compose.yml` with Traefik.
6. Create `full-stack/traefik/` with Traefik configuration.
7. Create `.env.example` with all required environment variables.
8. Create `README.md` explaining each scenario.
9. Validate docker-compose YAML syntax.
10. Write tests for compose validation.
11. Verify: PHPStan level 9, PHPCS clean.
12. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `DockerComposeTest::test_server_only_compose_valid`
2. `DockerComposeTest::test_server_hub_compose_valid`
3. `DockerComposeTest::test_full_stack_compose_valid`
4. `DockerComposeTest::test_env_example_has_all_required_vars`

## 6. Acceptance Criteria

- [ ] `server-only/docker-compose.yml` starts phlex-server + MySQL successfully.
- [ ] `server-hub/docker-compose.yml` starts both services with proper networking.
- [ ] `full-stack/docker-compose.yml` includes Traefik with HTTPS and relay routing.
- [ ] `.env.example` documents all required environment variables.
- [ ] All compose files pass `docker-compose config --quiet` validation.
- [ ] README explains each scenario and how to use it.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.2-compose
# ... implement ...
./vendor/bin/phpstan analyze docker/examples/ --level=9 --no-progress
docker-compose -f docker/examples/server-only/docker-compose.yml config --quiet
docker-compose -f docker/examples/server-hub/docker-compose.yml config --quiet
docker-compose -f docker/examples/full-stack/docker-compose.yml config --quiet
git add -A
git commit -m "Step O.2: docker-compose example stacks"
unset GITHUB_TOKEN
gh pr create --title "Step O.2: docker-compose example stacks" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.2-compose-review.md`.

(End of file - total 275 lines)
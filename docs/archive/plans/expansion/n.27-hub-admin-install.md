# Plan N.27 — Hub-Admin: Install & First Boot

## Step Details
- **Step:** N.27
- **Phase:** N (End-User Documentation)
- **Depends on:** B.7 (hub portal MVP — already merged)
- **Review:** No (doc-only step)
- **Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`)
- **Target doc:** `docs/hub-admin/install.md`
- **One-liner:** Hub-admin: install & first boot (TLS, first user, claim flow QA)

## Goal
Author `docs/hub-admin/install.md` — the operator-facing install guide for
running a self-hosted phlex-hub instance (community, family, or self-use).

## Doc Page Structure (§7 layout)
- TL;DR
- Shell blocks (install options)
- what-can-go-wrong (3 failures)
- next-steps

## Context

### Audience
Operators running their own phlex-hub instance (for a community, family, or self).

### Install options

**Docker (detain/phlex-hub image):**
```bash
docker run -d \
  --name phlex-hub \
  -p 8800:8800 \
  -e HUB_DATABASE_HOST=db \
  -e HUB_DATABASE_PORT=3306 \
  -e HUB_DATABASE_NAME=phlex_hub \
  -e HUB_DATABASE_USER=phlex \
  -e HUB_DATABASE_PASSWORD=secret \
  -e HUB_JWT_SECRET="$(openssl rand -hex 32)" \
  -e HUB_JWT_ISSUER=phlex-hub \
  -e HUB_PUBLIC_URL=https://hub.example.com \
  detain/phlex-hub
```

**Docker-compose (server + hub + traefik):**
```yaml
services:
  traefik:
    image: traefik:v3
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    ports:
      - "80:80"
      - "443:443"

  hub:
    image: detain/phlex-hub
    environment:
      HUB_DATABASE_HOST: db
      HUB_JWT_SECRET: "${HUB_JWT_SECRET}"
      HUB_PUBLIC_URL: "https://hub.example.com"
    depends_on:
      - db

  server:
    image: detain/phlex-server
    environment:
      HUB_RELAY_ENABLED: "true"
      HUB_PUBLIC_URL: "https://hub.example.com"
    depends_on:
      - hub
```

**Kubernetes (helm chart from phlex-helm repo):**
```bash
helm repo add phlex https://charts.phlex.io
helm install phlex-hub phlex/phlex-hub \
  --set hub.publicUrl=https://hub.example.com \
  --set hub.jwtSecret=$HUB_JWT_SECRET
```

**Source:**
```bash
git clone https://github.com/detain/phlex-hub.git
cd phlex-hub
composer install
php bin/hub.php admin:create admin@example.com
php bin/hub.php start
```

### TLS setup

**Option A — Let's Encrypt via traefik/caddy (automatic):**
Traefik automatically obtains TLS certificates via ACME when labels are
set on the container:
```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.hub.tls=true"
  - "traefik.http.routers.hub.rule=Host(`hub.example.com`)"
```

**Option B — Manual TLS cert:**
Place cert and key at:
- `config/ssl/hub.crt`
- `config/ssl/hub.key`
```php
// config/ssl.php
return [
    'cert' => __DIR__ . '/hub.crt',
    'key'  => __DIR__ . '/hub.key',
];
```

**HTTP → HTTPS redirect (always):**
```php
// config/ssl.php
return [
    'redirect_http' => true,
    'hsts'          => 'max-age=31536000; includeSubDomains',
];
```

### First admin user

Three ways to create the first admin:

1. **Hub UI (first boot):** Navigate to `https://hub.example.com` — the hub
   shows a "Create admin account" form automatically on first boot.

2. **CLI:**
   ```bash
   php bin/hub.php admin:create admin@example.com
   ```
   Prompts for password interactively. Outputs the user ID on success.

3. **Invite token via env var:**
   ```bash
   HUB_ADMIN_INVITE_TOKEN="$(openssl rand -hex 16)" php bin/hub.php start
   ```
   Then visit `https://hub.example.com/invite?token=$HUB_ADMIN_INVITE_TOKEN`.

### Hub claim flow QA (verify pairing works end-to-end)

1. Start hub + server (both running; server has `HUB_RELAY_ENABLED=true`).
2. On **server**: Settings → Hub → Connect → generates a claim code
   (e.g. `HUB-CLAIM-ABCD1234`).
3. On **hub**: Admin → Servers → Claim Server → enter the claim code.
4. Verify hub shows the server in "My Servers" with a green heartbeat indicator.
5. Create a test user on the hub (Admin → Users → Add User).
6. Log in as that test user; confirm the shared server appears in their
   server list and media is visible.

### env vars reference

| Variable | Required | Default | Description |
|---|---|---|---|
| `HUB_DATABASE_HOST` | Yes | `localhost` | MySQL host |
| `HUB_DATABASE_PORT` | Yes | `3306` | MySQL port |
| `HUB_DATABASE_NAME` | Yes | `phlex_hub` | Database name |
| `HUB_DATABASE_USER` | Yes | — | Database user |
| `HUB_DATABASE_PASSWORD` | Yes | — | Database password |
| `HUB_JWT_SECRET` | Yes | dev default | JWT signing secret (min 32 bytes) |
| `HUB_JWT_ISSUER` | No | `phlex-hub` | JWT issuer claim |
| `HUB_PUBLIC_URL` | Yes | — | Public URL of the hub (e.g. `https://hub.example.com`) |
| `HUB_TLS_CERT` | No | — | Path to TLS cert (traefik handles this if used) |
| `HUB_TLS_KEY` | No | — | Path to TLS key |
| `HUB_RELAY_ENABLED` | No | `false` | Enable relay tunnel for server communication |
| `HUB_CORS_ORIGINS` | No | `*` | Allowed CORS origins (comma-separated) |
| `HUB_ADMIN_INVITE_TOKEN` | No | — | One-time invite token for first admin creation |

## Deliverables

### File: `docs/hub-admin/install.md`

Sections to author:
1. **TL;DR** — one-liner per install method (Docker, docker-compose, k8s, source)
2. **Install options** — Docker, docker-compose, Kubernetes helm, source
3. **TLS setup** — Let's Encrypt via traefik, manual cert at `config/ssl/`, HTTP→HTTPS redirect, HSTS header
4. **First admin user** — UI first-boot form, CLI `admin:create`, invite token via `HUB_ADMIN_INVITE_TOKEN`
5. **Hub claim flow QA** — 6-step end-to-end verification procedure
6. **env vars reference** — table of all supported variables
7. **what-can-go-wrong** — 3 failure scenarios:
   - TLS cert not trusted by clients (self-signed → clients must accept exception)
   - JWT secret not set (falls back to dev default — insecure)
   - Hub can't receive connections from server (firewall, wrong `HUB_PUBLIC_URL`)
   - Database migration fails (schema already exists in dev mode)
8. **next-steps** — links to `docs/hub-admin/configuration.md` (N.28) and `docs/hub-admin/security.md` (N.29)

## Verification
- File exists at `docs/hub-admin/install.md` in phlex-hub repo
- Contains TL;DR, shell blocks, what-can-go-wrong (3+ items), next-steps sections
- All four install methods documented (Docker, docker-compose, k8s, source)
- TLS section covers Let's Encrypt, manual cert, redirect, HSTS
- First admin user section covers UI, CLI, invite token
- Claim flow QA has ≥5 numbered steps
- env vars table has all 11 variables from §Context
- what-can-go-wrong covers at least 3 failure modes with resolutions
- next-steps links to N.28 and N.29

## Dependencies
- B.7 — hub portal MVP (auth scaffold must be in place for the claim flow to work)
- N.28 — hub-admin/configuration.md (next-steps link)
- N.29 — hub-admin/security.md (next-steps link)

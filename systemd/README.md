# Phlix systemd Service

This directory contains the systemd unit file for running Phlix Media Server
as a system service.

## Installation

For a fresh end-to-end install (apt packages, MySQL DB+user, code clone,
composer install, env file, HAProxy + TLS), use the top-level installer:

```bash
sudo bash scripts/install.sh
```

If the rest of the host is already set up and you only want the systemd unit
registered, use the minimal installer:

```bash
sudo bash install/systemd.sh
```

Or do it by hand:

```bash
# Install the unit
sudo cp systemd/phlix-server.service /etc/systemd/system/

# Reload systemd
sudo systemctl daemon-reload

# Enable and start
sudo systemctl enable --now phlix-server
```

## Units

### phlix-server.service

The main long-running service. `ExecStart` runs `php start.php start`, which
brings up TWO dedicated listeners: the HTTP worker (default **:8096**; REST
API + `/app` SPA + `/health`) and the SyncPlay WebSocket worker (default
**:8097**; a separate `websocket://` worker — see the WebSocket note under
Configuration). Neither bind is hard-coded anymore (R14-06): both resolve
through `src/Server/Runtime/ListenEndpoints.php` with precedence
**env > `config/server.php` > shipped default** — `PHLIX_HTTP_HOST`/
`PHLIX_HTTP_PORT`/`PHLIX_HTTP_WORKER_COUNT` fall back to `server.host`/
`server.port`/`worker.count`, and `PHLIX_WS_HOST`/`PHLIX_WS_PORT` to
`websocket.host`/`websocket.port`. The resolved values are written back
into the config before the fork, so advertised values (the DLNA port, the
WS status metadata) always describe the socket that actually accepts. To
move a listener, set the env seam in `/etc/phlix/env` (or edit the config)
and restart — never edit `start.php`.

## Configuration

Edit `/etc/phlix/env` to configure environment variables. The systemd unit
loads it via `EnvironmentFile=`:

```ini
# Every DB connection parameter is env-driven (config/database.php):
# DB_HOST (default 127.0.0.1), DB_PORT (default 3306),
# DB_DATABASE (or DB_NAME, default phlix), DB_USER (or DB_USERNAME,
# default phlix), DB_PASSWORD (default empty). Pool knobs:
# DB_POOL_ENABLED=0 opts out of the coroutine pool (on by default),
# DB_POOL_SIZE caps per-worker connections (default 8).
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=phlix
DB_USER=phlix
DB_PASSWORD=your_strong_password

# REQUIRED. JWT signing secret (openssl rand -hex 32) — the server refuses
# to boot without a non-default value; it also derives media signed URLs.
JWT_SECRET=...

# Alias consumed by the Docker entrypoint (mapped to JWT_SECRET there);
# harmless on a bare-metal systemd install.
#PHLIX_SECRET_KEY=...

# Listening sockets (honored since R14-06): the values below win over
# config/server.php, which wins over the shipped defaults (HTTP
# 0.0.0.0:8096 with a 14-worker pool, WebSocket 0.0.0.0:8097). Garbage
# (non-numeric/out-of-range port, unparseable host) fails the boot loudly.
# `worker.count`/pool 'auto' means the shipped 14. The WS worker's process
# count is fixed at 1 by law (single authoritative SyncPlayManager) and has
# no env seam. Move a listener here or in the config — never in start.php.
#PHLIX_HTTP_HOST=0.0.0.0
#PHLIX_HTTP_PORT=8096
#PHLIX_HTTP_WORKER_COUNT=14
#PHLIX_WS_HOST=0.0.0.0
#PHLIX_WS_PORT=8097

# Informational: install.sh writes these, but log level is fixed in
# config/logger.php and the env-file values below are not read by the
# current code.
PHLIX_LOG_LEVEL=info
PHLIX_ENV=production

# Reverse-proxy IPs whose X-Forwarded-For is trusted for client-IP
# derivation (rate limiting). Loopback-only default is correct for the
# standard same-box proxy; only widen for a non-loopback proxy.
#TRUSTED_PROXIES=127.0.0.0/8,::1/128

# Optional integrations
#TMDB_API_KEY=
#PHLIX_HUB_URL=
#PHLIX_RELAY_ENABLED=1
```

WebSocket note: the SyncPlay WS server is its own listener, default
**:8097**. The bind is not hard-coded (R14-06): `PHLIX_WS_HOST`/
`PHLIX_WS_PORT` env win over `websocket.host`/`websocket.port` in
`config/server.php`, which win over the shipped default. Those same
`websocket.*` keys also tune the WS server object (timeouts, JWT auth),
and because `start.php` writes the resolved pair back into the config
before forking, object and listening socket always agree. Plain HTTP GETs
to the WS port answer **400** — that response IS the alive signal
for liveness probes; there is no HTTP route tree, and therefore no
path-based (`/ws`-style) reverse-proxy fronting the WS worker. Expose it as
its own TCP route — long read/write timeouts, no path routing — see
`reverse-proxy/README.md` and `deploy/haproxy.cfg`.

Permissions:

```bash
sudo chmod 640 /etc/phlix/env
sudo chown root:phlix /etc/phlix/env
```

## Security hardening

The unit applies several sandbox directives:

- `NoNewPrivileges=true` — block setuid / capability escalation
- `PrivateTmp=true` — private `/tmp`
- `ProtectSystem=strict` — read-only filesystem outside `ReadWritePaths`
- `ProtectHome=true` — hide `/home`, `/root`, `/run/user`
- `ReadWritePaths` — only `/var/phlix`, `/var/log/phlix`, `/var/run/phlix`,
  and the install dir's `.logs/` + `templates_c/` are writable
- `RestrictNamespaces=true`, `LockPersonality=true`, `RemoveIPC=true`
- `RuntimeDirectory=phlix` — systemd creates and chowns `/run/phlix` on
  each start (for the PID file at `config/server.php` `pid_file`)

## Troubleshooting

```bash
# Status / logs
sudo systemctl status phlix-server
journalctl -u phlix-server -f

# Restart after a config or env change
sudo systemctl restart phlix-server

# Failed units across the host
sudo systemctl --failed
```

Common failure causes:

- `ExecStart` missing the trailing `start` argument (`start.php start`)
  — Workerman prints help and exits.
- `DB_PASSWORD` empty or wrong in `/etc/phlix/env`.
- `/var/run/phlix` not writable — the unit's `RuntimeDirectory=phlix`
  directive handles this; don't override it without arranging the dir manually.

## Upgrading

If you used `scripts/install.sh`, run `scripts/install.sh --update -y` and
it will handle git fetch, composer, migrations, and a clean restart. For a
manual upgrade:

```bash
sudo systemctl stop phlix-server
# update /var/www/phlix (git pull, composer install --no-dev, …)
sudo systemctl start phlix-server
```

## Removed units

Earlier revisions of the repo shipped four additional units that were
non-functional and have been removed:

- `phlix-server.timer` — activated the long-running `phlix-server.service`
  (Type=simple, already restarted by `Restart=on-failure`), so the timer
  was a no-op. A real scheduled-tasks runner doesn't exist yet; revive
  this if/when one lands.
- `phlix-backup.service` + `phlix-backup.timer` — referenced
  `scripts/backup.php`, which doesn't exist. Restore alongside a real
  backup script.
- `phlix-hub.service` — was a duplicate of the unit shipped by the
  separate `phlix-hub` repo (with a different user and env-file path).
  Install phlix-hub via its own `scripts/install.sh` instead.

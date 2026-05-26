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

The main long-running service. Runs the Workerman HTTP + WebSocket worker
on the port configured in `config/server.php` (default `8096`).

## Configuration

Edit `/etc/phlix/env` to configure environment variables. The systemd unit
loads it via `EnvironmentFile=`:

```ini
# Only DB_PASSWORD is read by config/database.php (host/port/db/user are
# hardcoded to 127.0.0.1:3306 / phlix / phlix).
DB_PASSWORD=your_strong_password

# 32-byte hex secret (openssl rand -hex 32)
PHLIX_SECRET_KEY=...

PHLIX_LOG_LEVEL=info
PHLIX_ENV=production

# Optional integrations
#TMDB_API_KEY=
#PHLIX_HUB_URL=
#PHLIX_RELAY_ENABLED=1
```

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

- `ExecStart` missing the trailing `start` argument (`public/index.php start`)
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

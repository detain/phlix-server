# Phlex systemd Service

This directory contains systemd unit files for running Phlex Media Server as a system service.

## Installation

Run the installer script as root:

```bash
sudo bash install/systemd.sh
```

Or manually:

```bash
# Copy service files
sudo cp systemd/*.service /etc/systemd/system/
sudo cp systemd/*.timer /etc/systemd/system/

# Reload systemd
sudo systemctl daemon-reload

# Enable and start
sudo systemctl enable phlex-server
sudo systemctl start phlex-server
```

## Services

### phlex-server.service

Main application service. Runs the Workerman HTTP/WebSocket server.

### phlex-server.timer

Timer for periodic tasks (statistics collection, etc.)

### phlex-hub.service

Hub relay service for remote access (when using phlex-hub).

### phlex-backup.service / phlex-backup.timer

Automated backup service. Runs weekly by default.

## Configuration

Edit `/etc/phlex/env` to configure environment variables:

```
PHLEX_DATABASE_HOST=localhost
PHLEX_DATABASE_PORT=3306
PHLEX_DATABASE_NAME=phlex
PHLEX_DATABASE_USER=phlex
PHLEX_DATABASE_PASSWORD=your_secure_password
PHLEX_SECRET_KEY=your_secret_key_here
PHLEX_LOG_LEVEL=info
```

## Security

The service files include security hardening:

- `NoNewPrivileges=true` - Prevent privilege escalation
- `PrivateTmp=true` - Use private /tmp
- `ProtectSystem=strict` - Restrict filesystem access
- `ProtectHome=true` - Hide home directories
- `RestrictNamespaces=true` - Restrict Linux namespaces
- `LockPersonality=true` - Lock personality flags
- `RemoveIPC=true` - Remove IPC on stop

## Troubleshooting

View logs:
```bash
journalctl -u phlex-server -f
```

Restart after config change:
```bash
sudo systemctl restart phlex-server
```

Check service status:
```bash
sudo systemctl status phlex-server
```

Check failed services:
```bash
sudo systemctl --failed
```

## Backup

The backup timer runs weekly. To run a backup manually:

```bash
sudo systemctl start phlex-backup
```

List backups:
```bash
ls /var/phlex/backups/
```

## Upgrading

1. Stop the service:
   ```bash
   sudo systemctl stop phlex-server
   ```

2. Update the application files

3. Restart:
   ```bash
   sudo systemctl start phlex-server
   ```

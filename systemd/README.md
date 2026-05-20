# Phlix systemd Service

This directory contains systemd unit files for running Phlix Media Server as a system service.

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
sudo systemctl enable phlix-server
sudo systemctl start phlix-server
```

## Services

### phlix-server.service

Main application service. Runs the Workerman HTTP/WebSocket server.

### phlix-server.timer

Timer for periodic tasks (statistics collection, etc.)

### phlix-hub.service

Hub relay service for remote access (when using phlix-hub).

### phlix-backup.service / phlix-backup.timer

Automated backup service. Runs weekly by default.

## Configuration

Edit `/etc/phlix/env` to configure environment variables:

```
PHLIX_DATABASE_HOST=localhost
PHLIX_DATABASE_PORT=3306
PHLIX_DATABASE_NAME=phlix
PHLIX_DATABASE_USER=phlix
PHLIX_DATABASE_PASSWORD=your_secure_password
PHLIX_SECRET_KEY=your_secret_key_here
PHLIX_LOG_LEVEL=info
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
journalctl -u phlix-server -f
```

Restart after config change:
```bash
sudo systemctl restart phlix-server
```

Check service status:
```bash
sudo systemctl status phlix-server
```

Check failed services:
```bash
sudo systemctl --failed
```

## Backup

The backup timer runs weekly. To run a backup manually:

```bash
sudo systemctl start phlix-backup
```

List backups:
```bash
ls /var/phlix/backups/
```

## Upgrading

1. Stop the service:
   ```bash
   sudo systemctl stop phlix-server
   ```

2. Update the application files

3. Restart:
   ```bash
   sudo systemctl start phlix-server
   ```

# Step O.4 — systemd Unit Files

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.4
**Depends on:** N.1 (Install — Linux, which provides base install docs)
**Review:** Yes — see `o.4-systemd-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Create **systemd unit files** for non-container installs of phlex-server and phlex-hub.

## 2. Context (what already exists)

Read first:

- `public/index.php` — application entry point.
- `scripts/` — existing CLI scripts.
- `config/` — configuration structure.

## 3. Scope — files to create

### `systemd/phlex-server.service`

```ini
[Unit]
Description=Phlex Media Server
Documentation=https://docs.phlex.media
After=network.target mysql.service
Wants=mysql.service
StartLimitIntervalSec=500
StartLimitBurst=5

[Service]
Type=simple
User=phlex
Group=phlex
WorkingDirectory=/var/www/phlex
ExecStart=/usr/bin/php /var/www/phlex/public/index.php start
ExecReload=/bin/kill -SIGUSR1 $MAINPID
ExecStop=/bin/kill -SIGTERM $MAINPID
Restart=on-failure
RestartSec=5s
TimeoutStopSec=30
TimeoutStartSec=30

# Environment
Environment="PHLEX_ENV=production"
EnvironmentFile=/etc/phlex/env

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phlex-server

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/phlex /var/log/phlex /run
RestrictNamespaces=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LockPersonality=true
MemoryDenyWriteExecute=false
RemoveIPC=true

# Capabilities
CapabilityBoundingSet=
AmbientCapabilities=

[Install]
WantedBy=multi-user.target
```

### `systemd/phlex-server.timer` (for scheduled tasks)

```ini
[Unit]
Description=Phlex Media Server Scheduled Tasks Timer
Documentation=https://docs.phlex.media
After=phlex-server.service

[Timer]
OnBootSec=5min
OnUnitActiveSec=1h
Persistent=true

[Install]
WantedBy=timers.target
```

### `systemd/phlex-hub.service`

```ini
[Unit]
Description=Phlex Hub Relay Service
Documentation=https://docs.phlex.media
After=network.target mysql.service
Wants=mysql.service
StartLimitIntervalSec=500
StartLimitBurst=5

[Service]
Type=simple
User=phlex
Group=phlex
WorkingDirectory=/var/www/phlex-hub
ExecStart=/usr/bin/php /var/www/phlex-hub/public/index.php start
ExecReload=/bin/kill -SIGUSR1 $MAINPID
ExecStop=/bin/kill -SIGTERM $MAINPID
Restart=on-failure
RestartSec=5s
TimeoutStopSec=30
TimeoutStartSec=30

# Environment
Environment="PHLEX_ENV=production"
EnvironmentFile=/etc/phlex/hub-env

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phlex-hub

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/phlex-hub /var/log/phlex-hub /run
RestrictNamespaces=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LockPersonality=true
RemoveIPC=true

[Install]
WantedBy=multi-user.target
```

### `systemd/phlex-backup.service`

```ini
[Unit]
Description=Phlex Media Server Backup Service
Documentation=https://docs.phlex.media
After=phlex-server.service

[Service]
Type=oneshot
User=phlex
Group=phlex
WorkingDirectory=/var/www/phlex
ExecStart=/usr/bin/php /var/www/phlex/scripts/backup.php
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phlex-backup

# Security
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/phlex/backups /var/phlex/config /var/phlex/data
```

### `systemd/phlex-backup.timer`

```ini
[Unit]
Description=Phlex Media Server Backup Timer
Documentation=https://docs.phlex.media
After=phlex-backup.service

[Timer]
OnCalendar=weekly
Persistent=true
RandomizedDelaySec=3600

[Install]
WantedBy=timers.target
```

### `install/systemd.sh` (installer script)

```bash
#!/bin/bash
set -e

echo "Installing Phlex systemd service..."

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

# Detect OS
if [ -f /etc/debian_version ]; then
    OS="debian"
elif [ -f /etc/redhat-release ]; then
    OS="rhel"
elif [ -f /etc/arch-release ]; then
    OS="arch"
else
    OS="unknown"
fi

# Create phlex user if not exists
if ! id -u phlex > /dev/null 2>&1; then
    echo "Creating phlex user..."
    useradd --system --no-create-home --shell /usr/sbin/nologin phlex
fi

# Create directories
mkdir -p /var/phlex/{config,data,logs,backups}
mkdir -p /var/www/phlex
chown -R phlex:phlex /var/phlex
chown -R phlex:phlex /var/www/phlex

# Install service files
echo "Installing service files..."
install -m 644 systemd/phlex-server.service /etc/systemd/system/
install -m 644 systemd/phlex-server.timer /etc/systemd/system/
install -m 644 systemd/phlex-hub.service /etc/systemd/system/
install -m 644 systemd/phlex-backup.service /etc/systemd/system/
install -m 644 systemd/phlex-backup.timer /etc/systemd/system/

# Install environment file template
if [ ! -f /etc/phlex/env ]; then
    install -m 600 /dev/null /etc/phlex/env
    echo "PHLEX_DATABASE_HOST=localhost" > /etc/phlex/env
    echo "PHLEX_DATABASE_PORT=3306" >> /etc/phlex/env
    echo "PHLEX_DATABASE_NAME=phlex" >> /etc/phlex/env
    echo "PHLEX_DATABASE_USER=phlex" >> /etc/phlex/env
    echo "PHLEX_DATABASE_PASSWORD=" >> /etc/phlex/env
    echo "PHLEX_SECRET_KEY=" >> /etc/phlex/env
    echo "PHLEX_LOG_LEVEL=info" >> /etc/phlex/env
    chown root:phlex /etc/phlex/env
    chmod 640 /etc/phlex/env
    echo "Created /etc/phlex/env - please update with your configuration"
fi

# Reload systemd
systemctl daemon-reload

# Enable services
echo "Enabling services..."
systemctl enable phlex-server.service
systemctl enable phlex-server.timer
systemctl enable phlex-backup.timer

echo ""
echo "Installation complete!"
echo ""
echo "Start the server with: systemctl start phlex-server"
echo "Check status with:    systemctl status phlex-server"
echo "View logs with:        journalctl -u phlex-server -f"
```

### `systemd/README.md`

```markdown
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
```

## 4. Approach

1. Branch from master: `git checkout -b o.4-systemd`.
2. Create `systemd/` directory.
3. Create `phlex-server.service` with security hardening.
4. Create `phlex-server.timer` for scheduled tasks.
5. Create `phlex-hub.service` for hub relay.
6. Create `phlex-backup.service` and `.timer` for automated backups.
7. Create `install/systemd.sh` installer script.
8. Create `systemd/README.md` documentation.
9. Test service file syntax with `systemd-analyze verify`.
10. Write tests for service configuration.
11. Verify: PHPStan level 9, PHPCS clean.
12. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `SystemdTest::test_service_file_syntax_valid`
2. `SystemdTest::test_timer_file_syntax_valid`
3. `SystemdTest::test_install_script_executable`
4. `SystemdTest::test_environment_template_complete`

## 6. Acceptance Criteria

- [ ] `phlex-server.service` starts the application successfully.
- [ ] Service includes security hardening (NoNewPrivileges, PrivateTmp, ProtectSystem).
- [ ] Service handles SIGTERM for graceful shutdown.
- [ ] `phlex-server.timer` triggers periodic tasks.
- [ ] `phlex-hub.service` available for hub installations.
- [ ] `phlex-backup.service` and timer for automated backups.
- [ ] `install/systemd.sh` works on Debian/Ubuntu/RHEL/Arch.
- [ ] Service files pass `systemd-analyze verify`.
- [ ] README documents installation and troubleshooting.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.4-systemd
# ... implement ...
systemd-analyze verify systemd/phlex-server.service
systemd-analyze verify systemd/phlex-hub.service
./vendor/bin/phpstan analyze systemd/ install/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 systemd/ install/
git add -A
git commit -m "Step O.4: systemd unit files"
unset GITHUB_TOKEN
gh pr create --title "Step O.4: systemd unit files" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.4-systemd-review.md`.

(End of file - total 266 lines)
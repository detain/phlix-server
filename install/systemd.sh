#!/bin/bash
#
# Minimal systemd-only installer for phlix-server. Use this when the rest
# of the host is already set up (PHP, MySQL, code in /var/www/phlix) and
# you just want the systemd unit registered.
#
# For an end-to-end install (apt packages, MySQL DB+user, code clone,
# composer, env file, HAProxy + TLS), use scripts/install.sh instead.
#
# Usage:
#   sudo bash install/systemd.sh

set -e

echo "Installing Phlix systemd service..."

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

if [ ! -f systemd/phlix-server.service ]; then
    echo "Run this from the repo root (couldn't find systemd/phlix-server.service)" >&2
    exit 1
fi

echo "Creating phlix user..."
if ! id -u phlix > /dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin phlix
fi

echo "Creating directories..."
mkdir -p /var/phlix/{config,data,logs,backups}
mkdir -p /var/log/phlix /var/run/phlix
mkdir -p /var/www/phlix /etc/phlix
chown -R phlix:phlix /var/phlix /var/log/phlix /var/run/phlix
chown -R phlix:phlix /var/www/phlix

echo "Installing service file..."
install -m 644 systemd/phlix-server.service /etc/systemd/system/

if [ ! -f /etc/phlix/env ]; then
    cat > /etc/phlix/env <<'EOF'
# Phlix Media Server environment.
# The systemd unit loads this via EnvironmentFile=. Only DB_PASSWORD is
# currently read by config/database.php; the rest are placeholders for
# the uninstaller and forward-compat.

DB_PASSWORD=

PHLIX_DATABASE_HOST=127.0.0.1
PHLIX_DATABASE_PORT=3306
PHLIX_DATABASE_NAME=phlix
PHLIX_DATABASE_USER=phlix

# 32-byte hex secret (openssl rand -hex 32)
PHLIX_SECRET_KEY=

PHLIX_LOG_LEVEL=info
PHLIX_ENV=production
EOF
    chown root:phlix /etc/phlix/env
    chmod 640 /etc/phlix/env
    echo "Created /etc/phlix/env - fill in DB_PASSWORD and PHLIX_SECRET_KEY."
fi

systemctl daemon-reload

echo "Enabling service..."
systemctl enable phlix-server.service

echo ""
echo "Installation complete."
echo ""
echo "Start the server with: systemctl start phlix-server"
echo "Check status with:     systemctl status phlix-server"
echo "View logs with:        journalctl -u phlix-server -f"

#!/bin/bash
set -e

echo "Installing Phlix systemd service..."

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

if [ -f /etc/debian_version ]; then
    OS="debian"
elif [ -f /etc/redhat-release ]; then
    OS="rhel"
elif [ -f /etc/arch-release ]; then
    OS="arch"
else
    OS="unknown"
fi

echo "Creating phlix user..."
if ! id -u phlix > /dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin phlix
fi

echo "Creating directories..."
mkdir -p /var/phlix/{config,data,logs,backups}
mkdir -p /var/www/phlix
chown -R phlix:phlix /var/phlix
chown -R phlix:phlix /var/www/phlix

echo "Installing service files..."
install -m 644 systemd/phlix-server.service /etc/systemd/system/
install -m 644 systemd/phlix-server.timer /etc/systemd/system/
install -m 644 systemd/phlix-hub.service /etc/systemd/system/
install -m 644 systemd/phlix-backup.service /etc/systemd/system/
install -m 644 systemd/phlix-backup.timer /etc/systemd/system/

if [ ! -f /etc/phlix/env ]; then
    install -m 600 /dev/null /etc/phlix/env
    echo "PHLIX_DATABASE_HOST=localhost" > /etc/phlix/env
    echo "PHLIX_DATABASE_PORT=3306" >> /etc/phlix/env
    echo "PHLIX_DATABASE_NAME=phlix" >> /etc/phlix/env
    echo "PHLIX_DATABASE_USER=phlix" >> /etc/phlix/env
    echo "PHLIX_DATABASE_PASSWORD=" >> /etc/phlix/env
    echo "PHLIX_SECRET_KEY=" >> /etc/phlix/env
    echo "PHLIX_LOG_LEVEL=info" >> /etc/phlix/env
    chown root:phlix /etc/phlix/env
    chmod 640 /etc/phlix/env
    echo "Created /etc/phlix/env - please update with your configuration"
fi

systemctl daemon-reload

echo "Enabling services..."
systemctl enable phlix-server.service
systemctl enable phlix-server.timer
systemctl enable phlix-backup.timer

echo ""
echo "Installation complete!"
echo ""
echo "Start the server with: systemctl start phlix-server"
echo "Check status with:    systemctl status phlix-server"
echo "View logs with:        journalctl -u phlix-server -f"

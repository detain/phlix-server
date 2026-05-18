#!/bin/bash
set -e

echo "Installing Phlex systemd service..."

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

echo "Creating phlex user..."
if ! id -u phlex > /dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin phlex
fi

echo "Creating directories..."
mkdir -p /var/phlex/{config,data,logs,backups}
mkdir -p /var/www/phlex
chown -R phlex:phlex /var/phlex
chown -R phlex:phlex /var/www/phlex

echo "Installing service files..."
install -m 644 systemd/phlex-server.service /etc/systemd/system/
install -m 644 systemd/phlex-server.timer /etc/systemd/system/
install -m 644 systemd/phlex-hub.service /etc/systemd/system/
install -m 644 systemd/phlex-backup.service /etc/systemd/system/
install -m 644 systemd/phlex-backup.timer /etc/systemd/system/

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

systemctl daemon-reload

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

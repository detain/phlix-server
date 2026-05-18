#!/bin/bash
set -e

echo "Installing Phlex Caddy reverse-proxy configuration..."

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

echo "Installing Caddy..."
apt-get update -qq
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>/dev/null || true
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list 2>/dev/null || true
apt-get update -qq
apt-get install -y caddy

echo "Installing Caddyfile..."
install -m 644 reverse-proxy/caddy/Caddyfile /etc/caddy/Caddyfile

echo "Reloading Caddy..."
systemctl reload caddy

echo ""
echo "Caddy configuration installed!"
echo ""
echo "IMPORTANT: Update /etc/caddy/Caddyfile with:"
echo "  - Your domain"
echo "  - TLS email"
echo "  - Backend server address"
echo ""
echo "Then run: sudo systemctl reload caddy"

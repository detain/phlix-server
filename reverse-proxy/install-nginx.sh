#!/bin/bash
set -e

echo "Installing Phlex nginx reverse-proxy configuration..."

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

if [ -f /etc/debian_version ]; then
    PKG_MANAGER="apt-get"
    NGINX_SITES="/etc/nginx/sites-available"
    NGINX_ENABLED="/etc/nginx/sites-enabled"
elif [ -f /etc/redhat-release ]; then
    PKG_MANAGER="yum"
    NGINX_SITES="/etc/nginx/conf.d"
    NGINX_ENABLED="/etc/nginx/conf.d"
else
    echo "Unsupported OS"
    exit 1
fi

echo "Installing nginx..."
$PKG_MANAGER update -qq
$PKG_MANAGER install -y nginx

echo "Installing configuration..."
install -m 644 reverse-proxy/nginx/phlex.conf $NGINX_SITES/phlex.conf

echo "Testing nginx configuration..."
nginx -t

echo "Reloading nginx..."
systemctl reload nginx

echo ""
echo "Nginx configuration installed!"
echo ""
echo "IMPORTANT: Update $NGINX_SITES/phlex.conf with:"
echo "  - Your server_name"
echo "  - Path to your SSL certificates"
echo "  - Backend server address"
echo ""
echo "Then run: sudo systemctl reload nginx"

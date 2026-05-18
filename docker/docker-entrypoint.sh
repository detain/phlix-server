#!/bin/sh
set -e

echo "Starting Phlex Media Server..."

if [ -n "${PHLEX_DATABASE_HOST}" ]; then
    if [ -f /var/www/html/scripts/run-migrations.php ]; then
        echo "Running database migrations..."
        php /var/www/html/scripts/run-migrations.php || true
    fi
fi

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

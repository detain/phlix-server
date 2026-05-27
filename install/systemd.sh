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

# ---------------------------------------------------------------------------
# Workerman preflight + Swoole/php-uv extensions
#
# Even in the minimal systemd-only path the server still needs the Workerman
# process-control functions (preflight below) and benefits from Swoole +
# php-uv. The extensions are compiled from source with the EXACT flag set in
# docker/Dockerfile.base (the source of truth — see docker/README.md "Swoole
# build flags"). Both steps are idempotent: an already-loaded extension is
# skipped, so re-running this script is a no-op for them.
#
# --enable-iouring / --enable-uring-socket build on any kernel but only
# activate at RUNTIME on Linux kernel >= 5.6; older kernels fall back to epoll.
# ---------------------------------------------------------------------------
echo "Checking PHP disable_functions for Workerman compatibility..."
phlix_disabled="$(php -r 'echo (string) ini_get("disable_functions");' 2>/dev/null)"
phlix_missing=""
for fn in pcntl_fork pcntl_wait pcntl_signal pcntl_alarm pcntl_async_signals \
          posix_getpid posix_kill posix_setuid posix_setgid \
          proc_open proc_close proc_get_status proc_terminate \
          exec shell_exec \
          stream_socket_server stream_socket_client stream_socket_accept; do
    case ",${phlix_disabled//[[:space:]]/}," in
        *",${fn},"*) phlix_missing="${phlix_missing:+$phlix_missing }$fn" ;;
    esac
done
if [ -n "$phlix_missing" ]; then
    echo "ERROR: PHP disable_functions blocks functions Workerman requires: $phlix_missing" >&2
    echo "       Remove them from the 'disable_functions' directive in your php.ini" >&2
    echo "       (and php-fpm pool config if present). Workerman needs pcntl_*, posix_*," >&2
    echo "       proc_*, exec/shell_exec and stream_socket_* to fork workers." >&2
    exit 1
fi
echo "disable_functions preflight passed."

# Resolve PHP's conf.d directory (Debian layout: /etc/php/X.Y/<sapi>/conf.d).
phlix_confd="$(php -r 'echo (string) (PHP_CONFIG_FILE_SCAN_DIR ?: "");' 2>/dev/null)"
if [ -z "$phlix_confd" ]; then
    phlix_confd="$(php --ini 2>/dev/null | awk -F': *' '/Scan for additional .ini files in/{print $2; exit}')"
fi

# Debian -dev packages mirroring the Alpine set in docker/Dockerfile.base.
phlix_build_pkgs="build-essential autoconf pkg-config git \
libssl-dev libuv1-dev libbrotli-dev libzstd-dev libnghttp2-dev \
libpq-dev libsqlite3-dev libc-ares-dev liburing-dev libssh2-1-dev"
phlix_php_mm="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
phlix_phpdev="php-dev"
if [ -n "$phlix_php_mm" ] && apt-cache show "php${phlix_php_mm}-dev" >/dev/null 2>&1; then
    phlix_phpdev="php${phlix_php_mm}-dev"
fi

phlix_need_swoole="no"; phlix_need_uv="no"
php -m 2>/dev/null | grep -qi '^swoole$' || phlix_need_swoole="yes"
php -m 2>/dev/null | grep -qi '^uv$'     || phlix_need_uv="yes"

if [ "$phlix_need_swoole" = "yes" ] || [ "$phlix_need_uv" = "yes" ]; then
    [ -n "$phlix_confd" ] || { echo "ERROR: could not determine PHP conf.d directory." >&2; exit 1; }
    if ! command -v apt-get >/dev/null 2>&1; then
        echo "ERROR: Swoole/php-uv missing but apt-get not found — install the extensions manually." >&2
        exit 1
    fi
    echo "Installing Swoole/php-uv build dependencies ($phlix_phpdev + C library headers)..."
    export DEBIAN_FRONTEND=noninteractive
    # Refresh the apt index first (this minimal installer has no earlier global
    # apt-get update); only reached when an extension is actually missing, so it
    # never fires on the already-loaded re-run. A stale cache would otherwise
    # 404 the -dev packages on a clean box.
    apt-get update -y >/dev/null
    apt-get install -y $phlix_phpdev $phlix_build_pkgs >/dev/null

    if [ "$phlix_need_swoole" = "yes" ]; then
        echo "Building Swoole from source (this can take several minutes)..."
        phlix_tmp="$(mktemp -d)"
        git clone --depth=1 https://github.com/swoole/swoole-src.git "$phlix_tmp/swoole"
        (
            cd "$phlix_tmp/swoole"
            phpize
            # Flags copied verbatim from docker/Dockerfile.base — the source of truth.
            ./configure \
                --enable-swoole \
                --enable-sockets \
                --enable-mysqlnd \
                --enable-swoole-curl \
                --enable-cares \
                --enable-swoole-pgsql \
                --with-openssl-dir=/usr \
                --with-nghttp2-dir=/usr \
                --enable-swoole-sqlite \
                --enable-swoole-coro-time \
                --enable-zstd \
                --enable-brotli \
                --enable-iouring \
                --enable-uring-socket \
                --with-swoole-ssh2 \
                --enable-swoole-ftp
            make -j"$(nproc)"
            make install
        )
        echo "extension=swoole.so" > "$phlix_confd/zz-swoole.ini"
        rm -rf "$phlix_tmp"
        php -m 2>/dev/null | grep -qi '^swoole$' \
            || { echo "ERROR: Swoole built but is not loading — check $phlix_confd/zz-swoole.ini." >&2; exit 1; }
        echo "Swoole installed and loaded."
    else
        echo "Swoole already loaded — skipping build."
    fi

    if [ "$phlix_need_uv" = "yes" ]; then
        echo "Building php-uv from source..."
        phlix_tmp="$(mktemp -d)"
        git clone --depth=1 https://github.com/bwoebi/php-uv.git "$phlix_tmp/php-uv"
        (
            cd "$phlix_tmp/php-uv"
            phpize
            ./configure --with-uv
            make -j"$(nproc)"
            make install
        )
        echo "extension=uv.so" > "$phlix_confd/zz-uv.ini"
        rm -rf "$phlix_tmp"
        php -m 2>/dev/null | grep -qi '^uv$' \
            || { echo "ERROR: php-uv built but is not loading — check $phlix_confd/zz-uv.ini." >&2; exit 1; }
        echo "php-uv installed and loaded."
    else
        echo "php-uv already loaded — skipping build."
    fi
else
    echo "Swoole and php-uv already loaded — nothing to build."
fi

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

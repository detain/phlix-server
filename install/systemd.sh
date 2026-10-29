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
if ! id -u phlix >/dev/null 2>&1; then
	useradd --system --no-create-home --shell /usr/sbin/nologin phlix
fi

echo "Creating directories..."
mkdir -p /var/phlix/{config,data,logs,backups}
mkdir -p /var/log/phlix /var/run/phlix
mkdir -p /var/www/phlix /etc/phlix
# anidb plugin's persistent title-dump cache (PHLIX_ANIDB_CACHE_DIR in the
# unit + its ReadWritePaths). Must exist and be owned by the service user
# before the worker starts, mirroring how the full installer provisions
# /var/artwork.
mkdir -p /var/cache/phlix/anidb
chown -R phlix:phlix /var/phlix /var/log/phlix /var/run/phlix
chown -R phlix:phlix /var/www/phlix
chown -R phlix:phlix /var/cache/phlix

# ---------------------------------------------------------------------------
# Workerman preflight + Swoole/php-uv extensions
#
# Even in the minimal systemd-only path the server still needs the Workerman
# process-control functions (preflight below) and benefits from Swoole +
# php-uv. The extensions are compiled from source with the flag set in
# docker/Dockerfile.base (the source of truth — see docker/README.md "Swoole
# build flags"), with ONE deliberate divergence at the configure block below:
# a host carries no container seccomp profile, so the io_uring flags stay ON
# here while the base gates them OFF behind `ARG SWOOLE_IOURING=0`. Both steps
# are idempotent: an already-loaded extension is skipped, so re-running this
# script is a no-op for them.
#
# MEASURED FLOOR (S200 note in docker/Dockerfile.base — this supersedes the
# refuted ">= 5.6, older kernels fall back to epoll" claim once recorded
# here): Swoole 6.2.1's io_uring path needs kernel >= 6.9 and its ctor
# REJECTS older kernels FATAL (exit 1 at ring init, "The Iouring::ftruncate()
# requires 6.9 or higher Linux kernel") — there is no silent epoll fallback
# once the flags are compiled in. The seccomp blocker is container-only; the
# 6.9 floor is not, so a pre-6.9 host hits the same fatal with these flags ON.
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

phlix_need_swoole="no"
phlix_need_uv="no"
php -m 2>/dev/null | grep -qi '^swoole$' || phlix_need_swoole="yes"
php -m 2>/dev/null | grep -qi '^uv$' || phlix_need_uv="yes"

if [ "$phlix_need_swoole" = "yes" ] || [ "$phlix_need_uv" = "yes" ]; then
	[ -n "$phlix_confd" ] || {
		echo "ERROR: could not determine PHP conf.d directory." >&2
		exit 1
	}
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
			# Flags from docker/Dockerfile.base's configure line — the source of
			# truth — with the one divergence named in the header above: the
			# two io_uring flags below are UNCONDITIONAL here (no seccomp on a
			# host), while the base emits them only under `SWOOLE_IOURING=1`.
			# See also scripts/install.sh's S163 note for the full story.
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
		echo "extension=swoole.so" >"$phlix_confd/zz-swoole.ini"
		rm -rf "$phlix_tmp"
		php -m 2>/dev/null | grep -qi '^swoole$' ||
			{
				echo "ERROR: Swoole built but is not loading — check $phlix_confd/zz-swoole.ini." >&2
				exit 1
			}
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
		echo "extension=uv.so" >"$phlix_confd/zz-uv.ini"
		rm -rf "$phlix_tmp"
		php -m 2>/dev/null | grep -qi '^uv$' ||
			{
				echo "ERROR: php-uv built but is not loading — check $phlix_confd/zz-uv.ini." >&2
				exit 1
			}
		echo "php-uv installed and loaded."
	else
		echo "php-uv already loaded — skipping build."
	fi
else
	echo "Swoole and php-uv already loaded — nothing to build."
fi

echo "Installing service file..."
install -m 644 systemd/phlix-server.service /etc/systemd/system/

# Review server__devops M11: this unit's EnvironmentFile must carry every key
# the service needs TO BOOT, not a scrap of hopeful placeholders. The old
# template omitted JWT_SECRET (which the boot guard hard-requires) and wrote
# only PHLIX_DATABASE_* aliases for the DB (which config/database.php never
# reads) — so a fresh install crash-looped against Restart=on-failure. The
# helpers below mirror what scripts/install.sh already does for the same file.

phlix_generate_jwt_secret() {
	# 64 hex chars = openssl rand -hex 32 (same generator and size as the full
	# installer's rand_hex 32). Fail loudly without openssl — never write a
	# blank secret the boot guard would reject anyway.
	if ! command -v openssl >/dev/null 2>&1; then
		echo "openssl is required to generate JWT_SECRET (or set a real one in /etc/phlix/env yourself)" >&2
		return 1
	fi
	openssl rand -hex 32
}

phlix_backfill_jwt_secret() {
	# A JWT_SECRET key that is missing or present-but-blank is treated as
	# MISSING (same law as scripts/install.sh's phlix_ensure_env_key): legacy
	# env files created by the pre-M11 template get a generated secret folded
	# in without touching anything else.
	local env_file="$1"
	if grep -Eq '^JWT_SECRET=.+' "$env_file"; then
		return 0
	fi
	local secret
	secret="$(phlix_generate_jwt_secret)" || return 1
	local tmp
	tmp="$(mktemp "${env_file}.XXXXXX")"
	grep -v '^JWT_SECRET=' "$env_file" >"$tmp" || true
	printf '\n# Generated by install/systemd.sh — the boot guard refuses empty,\n# shipped-default, and public-placeholder signing keys.\nJWT_SECRET=%s\n' "$secret" >>"$tmp"
	chmod 640 "$tmp"
	chown root:phlix "$tmp" 2>/dev/null || true
	mv "$tmp" "$env_file"
	echo "Backfilled a generated JWT_SECRET into $env_file."
}

if [ ! -f /etc/phlix/env ]; then
	phlix_jwt_secret="$(phlix_generate_jwt_secret)" || exit 1
	phlix_domain="$(hostname -f 2>/dev/null || hostname)"
	cat >/etc/phlix/env <<EOF
# Phlix Media Server environment (systemd carrier).
# The unit systemd/phlix-server.service loads this via EnvironmentFile=.
#
# Every key below has a reader (review server__devops M11):
#   - DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD are the keys
#     config/database.php actually reads. DB_PASSWORD is the ONLY value
#     this minimal installer cannot know (it does not create the MySQL
#     user) — fill it in before the first start.
#   - PHLIX_DATABASE_HOST/NAME/USER are recorded aliases the uninstaller
#     (scripts/install.sh do_uninstall) greps back out of this file.
#   - JWT_SECRET signs session tokens and derives the media signed-URL
#     key. It is generated here; the boot guard refuses empty, shipped-
#     default, and publicly-known placeholder values on every carrier.
#   - PHLIX_DOMAIN feeds base-URL construction (config) and is read by
#     the uninstaller.
#   - PHLIX_LOG_LEVEL is a live knob (StructuredLogger); PHLIX_ENV selects
#     the dev/prod tier of the boot policy.
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=phlix
DB_USER=phlix
DB_PASSWORD=
PHLIX_DATABASE_HOST=127.0.0.1
PHLIX_DATABASE_NAME=phlix
PHLIX_DATABASE_USER=phlix
JWT_SECRET=${phlix_jwt_secret}
PHLIX_DOMAIN=${phlix_domain}
PHLIX_LOG_LEVEL=info
PHLIX_ENV=production
EOF
	chown root:phlix /etc/phlix/env
	chmod 640 /etc/phlix/env
	echo "Created /etc/phlix/env (JWT_SECRET generated; 640 root:phlix)."
	echo "Fill in DB_PASSWORD before starting the service."
elif ! phlix_backfill_jwt_secret /etc/phlix/env; then
	echo "WARNING: could not backfill JWT_SECRET into /etc/phlix/env — the boot" >&2
	echo "guard will refuse to start the service until a real one is set there." >&2
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

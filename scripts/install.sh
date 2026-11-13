#!/usr/bin/env bash
#
# Phlix Media Server one-shot installer for Ubuntu/Debian.
#
# Installs system packages (PHP 8.3+, MySQL, ffmpeg), creates the MySQL
# database + user, creates a system `phlix` account, fetches the
# application code, writes the environment file, generates a JWT/secret,
# runs database migrations, installs a systemd service, and (optionally)
# sets up an HAProxy reverse proxy with a Let's Encrypt certificate that
# auto-renews monthly.
#
# Usage:
#   sudo bash install.sh [options]
#   curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh | sudo bash
#
# Run with --help for the full option list.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults (override via flags or interactive prompts)
# ---------------------------------------------------------------------------
REPO_URL="https://github.com/detain/phlix-server.git"
BRANCH="master"
INSTALL_PATH="/var/www/phlix"
SERVICE_USER="phlix"
ENV_FILE="/etc/phlix/env"
SERVICE_FILE="/etc/systemd/system/phlix-server.service"
SERVICE_NAME="phlix-server"

# Runtime directories the service expects (match the systemd unit's
# ReadWritePaths and config/server.php's pid_file).
DATA_ROOT="/var/phlix"
LOG_DIR="/var/log/phlix"
RUN_DIR="/var/run/phlix"
# Local artwork cache root (config/artwork.php: ARTWORK_STORAGE_PATH, historic
# default /var/artwork). Honour an operator override in the env file so this
# path — which MUST be a systemd ReadWritePath, else ProtectSystem=strict makes
# it read-only and the scanner fails with "mkdir(): Read-only file system" —
# tracks wherever the cache actually lives.
ARTWORK_DIR="$(grep -m1 -E '^ARTWORK_STORAGE_PATH=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
[ -n "$ARTWORK_DIR" ] || ARTWORK_DIR="/var/artwork"
# Persistent cache root for the anidb plugin's offline title-dump index
# (PHLIX_ANIDB_CACHE_DIR). Like ARTWORK_DIR it MUST be a systemd ReadWritePath,
# else ProtectSystem=strict makes it read-only and the plugin's index write
# fails. Honour an operator override already recorded in the env file.
ANIDB_CACHE_DIR="$(grep -m1 -E '^PHLIX_ANIDB_CACHE_DIR=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
[ -n "$ANIDB_CACHE_DIR" ] || ANIDB_CACHE_DIR="/var/cache/phlix/anidb"
# Root for DOWNLOADED external subtitles (config/subtitles.php: SUBTITLE_STORAGE_PATH,
# default /var/subtitles). Like ARTWORK_DIR it MUST be a systemd ReadWritePath, else
# ProtectSystem=strict makes it read-only and the on-demand subtitle download write
# fails with "Read-only file system". Honour an operator override in the env file.
SUBTITLE_DIR="$(grep -m1 -E '^SUBTITLE_STORAGE_PATH=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
[ -n "$SUBTITLE_DIR" ] || SUBTITLE_DIR="/var/subtitles"
# Persistent home for the HLS segment cache (config/server.php resolves it from
# PHLIX_SEGMENT_DIR; unset means sys_get_temp_dir()/phlix_hls). The shipped unit
# runs PrivateTmp=true, so the temp default is a per-start namespace: EVERY
# deploy/restart silently wipes the cache and each cold first-play re-pays 3–6.6 s
# of inline segment transcode (the FIELD-hub-phlix playback trace). Like
# ANIDB_CACHE_DIR it MUST be a systemd ReadWritePath. Honour an operator override
# already recorded in the env file.
SEGMENT_DIR="$(grep -m1 -E '^PHLIX_SEGMENT_DIR=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
[ -n "$SEGMENT_DIR" ] || SEGMENT_DIR="/var/phlix/hls"

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="phlix"
DB_USER="phlix"
DB_PASS=""       # generated if empty

HTTP_PORT="8096" # config/server.php default
DLNA_PORT="1900" # UDP multicast for DLNA discovery (optional)

DOMAIN=""      # public hostname; enables TLS when paired with --admin-email
ADMIN_EMAIL="" # Let's Encrypt registration email

# SV-4.15: comma-separated reverse-proxy IPs/CIDRs trusted for X-Forwarded-For.
# Left blank => the code's loopback-only default applies (correct for the
# bundled loopback proxy). Only populated by the interactive --no-proxy prompt
# below, when the operator fronts Phlix with their own NON-loopback proxy.
TRUSTED_PROXIES=""

# Optional external service keys. Left blank by default; the operator can
# fill them in /etc/phlix/env later.
TMDB_API_KEY=""
HUB_URL=""         # PHLIX_HUB_URL — paired separately via scripts/pair-with-hub.php

WANT_TLS="auto"    # auto|yes|no
SKIP_HAPROXY="no"  # --no-proxy: skip HAProxy install + config entirely
PLAINTEXT_ACK="no" # --i-know-plaintext-internet-facing: explicit ack of the --no-proxy plaintext posture
INTERACTIVE="auto" # auto|yes|no
ASSUME_YES="no"
ACTION="install" # install|update|uninstall
PURGE="no"       # uninstall: also drop the DB and delete the Let's Encrypt cert
SELF_TEST="no"   # --self-test: exercise the pure TLS-bind helpers and exit

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
	C_BOLD=$'\033[1m'
	C_GREEN=$'\033[32m'
	C_YELLOW=$'\033[33m'
	C_RED=$'\033[31m'
	C_RESET=$'\033[0m'
else
	C_BOLD=""
	C_GREEN=""
	C_YELLOW=""
	C_RED=""
	C_RESET=""
fi
log() { printf '%s==>%s %s\n' "$C_GREEN$C_BOLD" "$C_RESET" "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die() {
	printf '%s[error]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2
	exit 1
}

# ---------------------------------------------------------------------------
# HAProxy TLS-bind shape helpers (audit AUDIT-wildcard-cert-rotation.md §3.1)
#
# Defined at the top of the file — and dependency-free — so `--self-test` can
# exercise them on any machine (no root, no haproxy, no network): they are the
# pure decision core of the cert-rotation cure, and this repo has no CI shell
# harness, so the flag below is the testable leg. phlix_haproxy_rebuild in the
# shared-haproxy region consumes them.
#
# LAW being enforced: a fe_https bind must never read a CAT-MERGED COPY of a
# certificate. Merged copies go stale the moment any cert rotates outside this
# host's certbot pipeline, and the next reload then serves expired bytes — the
# §3.1 outage class. HAProxy >= 2.9 references the certbot live/ symlinks
# directly with the split form
#     crt <live/fullchain.pem> key-file <live/privkey.pem>
# and re-reads material at those content-stable paths on every reload. The
# Phlix support matrix (Ubuntu 24.04+ — see README) ships haproxy 2.9.x or
# newer, but the installer apt-installs an unpinned haproxy, so the capability
# is MEASURED here, never assumed. Pre-2.9 hosts keep the historical merged-copy
# shape (best-effort: the deploy hook re-merges on every renewal THIS host's
# certbot performs, but off-pipeline rotations still strand the copy — the warn
# lines say so).
# ---------------------------------------------------------------------------

# Is a version string >= 2.9? '2.9.5'/'2.9'/'2.10.4'/'3.0.3' yes; '2.8.14'/
# '1.8.30'/empty/garbage no. Numeric compare — no lexicographic trap for 2.10.
phlix_haproxy_version_ge_29() {
	local ver="$1" major minor
	case "$ver" in [0-9]*) ;; *) return 1 ;; esac
	major="${ver%%.*}"
	if [ "$ver" = "$major" ]; then
		minor=0
	else
		minor="${ver#*.}"
		minor="${minor%%.*}"
	fi
	case "$major" in '' | *[!0-9]*) return 1 ;; esac
	case "$minor" in '' | *[!0-9]*) return 1 ;; esac
	[ "$major" -gt 2 ] && return 0
	[ "$major" -eq 2 ] && [ "$minor" -ge 9 ] && return 0
	return 1
}

# Print the installed haproxy version number ('2.9.5'), or nothing when the
# binary is absent. Never fails the caller's set -e.
phlix_haproxy_installed_version() {
	command -v haproxy >/dev/null 2>&1 || return 0
	haproxy -v 2>/dev/null |
		sed -n 's/^HAProxy version \([0-9][0-9.]*\).*/\1/p' |
		head -n1
	return 0
}

# Does the haproxy on this box support `crt <pem> key-file <pem>` binds?
phlix_haproxy_supports_tls_key_file() {
	local ver
	ver="$(phlix_haproxy_installed_version)"
	[ -n "$ver" ] || return 1
	phlix_haproxy_version_ge_29 "$ver"
}

# Certbot's live/ root. Overridable so the --self-test + rebuild conviction
# harnesses exercise the decision paths against scratch material; on real
# hosts (where nobody exports it) it is exactly /etc/letsencrypt/live.
PHLIX_LE_LIVE_DIR="${PHLIX_LE_LIVE_DIR:-/etc/letsencrypt/live}"

# Pure renderer: print the fe_https `bind :443 ...` lines (4-space indented).
#   $1 shape      live | merged
#   $2 certs_dir  directory holding legacy merge copies
#   $3 extras     yes|no — certs_dir still holds a .pem the dir-bind must serve
#   $4.. domains  annotated tls domains whose live/ material is present
phlix_haproxy_render_https_binds() {
	local shape="$1" certs_dir="$2" extras="$3" dom
	shift 3
	if [ "$shape" = "live" ]; then
		for dom in "$@"; do
			printf '    bind :443 ssl crt "%s/%s/fullchain.pem" key-file "%s/%s/privkey.pem"\n' "$PHLIX_LE_LIVE_DIR" "$dom" "$PHLIX_LE_LIVE_DIR" "$dom"
		done
	fi
	if [ "$shape" = "merged" ] || [ "$extras" = "yes" ]; then
		printf '    bind :443 ssl crt %s/\n' "$certs_dir"
	fi
}

# Self-update guard (SRV-12 / SRV-S9). `--update` fetches the new release into
# the install dir and then must run the NEW release's install logic, never the
# previous release's (the running copy may be the old on-disk installer or a
# piped one). do_update re-execs the installer from the updated checkout once,
# marking the child with the PHLIX_INSTALL_REEXEC sentinel so it cannot loop.
# Pure decision helper (--self-test covers it): $1 = sentinel value, $2 = path
# of the updated installer. Returns 0 when a re-exec should happen.
phlix_update_should_reexec() {
	local sentinel="${1:-}" new_script="${2:-}"
	[ "$sentinel" = "1" ] && return 1
	[ -n "$new_script" ] && [ -f "$new_script" ] || return 1
	return 0
}

# --self-test plumbing: equality assertions on plain stdout; nonzero exit if
# any FAIL line was emitted.
phlix_selftest_assert() {
	local label="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		printf '    ok   %s\n' "$label"
		return 0
	fi
	printf '    FAIL %s\n         expected: %s\n         actual:   %s\n' "$label" "$expected" "$actual" >&2
	return 1
}

phlix_selftest_ge29() {
	local v="$1" want="$2" got="no"
	phlix_haproxy_version_ge_29 "$v" && got="yes"
	phlix_selftest_assert "version_ge_29 '$v' => $want" "$want" "$got"
}

phlix_run_self_tests() {
	local rc=0 out want
	PHLIX_LE_LIVE_DIR="${PHLIX_LE_LIVE_DIR:-/etc/letsencrypt/live}"
	log "Installer self-test (pure TLS-bind helpers — audit §3.1 decision core)"
	phlix_selftest_ge29 2.9.5 yes || rc=1
	phlix_selftest_ge29 2.9 yes || rc=1
	phlix_selftest_ge29 2.10.4 yes || rc=1
	phlix_selftest_ge29 3.0.3 yes || rc=1
	phlix_selftest_ge29 2.8.14 no || rc=1
	phlix_selftest_ge29 2.2 no || rc=1
	phlix_selftest_ge29 1.8.30 no || rc=1
	phlix_selftest_ge29 2 no || rc=1
	phlix_selftest_ge29 '' no || rc=1
	phlix_selftest_ge29 dev no || rc=1
	phlix_selftest_ge29 300x no || rc=1

	out="$(phlix_haproxy_render_https_binds live /etc/haproxy/certs no phlix.example.com)"
	want='    bind :443 ssl crt "/etc/letsencrypt/live/phlix.example.com/fullchain.pem" key-file "/etc/letsencrypt/live/phlix.example.com/privkey.pem"'
	phlix_selftest_assert 'render live/1 domain/no extras' "$want" "$out" || rc=1

	out="$(phlix_haproxy_render_https_binds live /etc/haproxy/certs yes a.example.com b.example.com)"
	want="$(printf '    bind :443 ssl crt "/etc/letsencrypt/live/a.example.com/fullchain.pem" key-file "/etc/letsencrypt/live/a.example.com/privkey.pem"\n    bind :443 ssl crt "/etc/letsencrypt/live/b.example.com/fullchain.pem" key-file "/etc/letsencrypt/live/b.example.com/privkey.pem"\n    bind :443 ssl crt /etc/haproxy/certs/')"
	phlix_selftest_assert 'render live/2 domains/extras' "$want" "$out" || rc=1

	out="$(phlix_haproxy_render_https_binds merged /etc/haproxy/certs no a.example.com)"
	want='    bind :443 ssl crt /etc/haproxy/certs/'
	phlix_selftest_assert 'render merged ignores domains, dir-bind only' "$want" "$out" || rc=1

	out="$(phlix_haproxy_render_https_binds live /etc/haproxy/certs yes)"
	want='    bind :443 ssl crt /etc/haproxy/certs/'
	phlix_selftest_assert 'render live/no domains/extras => dir-bind only' "$want" "$out" || rc=1

	out="$(phlix_haproxy_render_https_binds live /etc/haproxy/certs no)"
	want=''
	phlix_selftest_assert 'render live/no domains/no extras => empty' "$want" "$out" || rc=1

	local f_none="" f_real
	f_real="$(mktemp)"
	phlix_update_should_reexec "" "$f_real" && f_none="yes" || f_none="no"
	phlix_selftest_assert 'reexec: no sentinel + new installer present => yes' yes "$f_none" || rc=1
	phlix_update_should_reexec 1 "$f_real" && f_none="yes" || f_none="no"
	phlix_selftest_assert 'reexec: sentinel set => no (loop guard)' no "$f_none" || rc=1
	phlix_update_should_reexec "" "$f_real.missing" && f_none="yes" || f_none="no"
	phlix_selftest_assert 'reexec: new installer missing => no' no "$f_none" || rc=1
	phlix_update_should_reexec "" "" && f_none="yes" || f_none="no"
	phlix_selftest_assert 'reexec: empty path => no' no "$f_none" || rc=1
	rm -f "$f_real"

	if command -v haproxy >/dev/null 2>&1; then
		info "(haproxy found: '$(phlix_haproxy_installed_version)'; supports key-file bind: $(phlix_haproxy_supports_tls_key_file && echo yes || echo no))"
	else
		info "(haproxy not installed here — capability probe exercised against fixture strings only)"
	fi

	if [ "$rc" -ne 0 ]; then
		die "Installer self-test FAILED."
	fi
	log "Installer self-test passed."
	return 0
}

# ---------------------------------------------------------------------------
# Usage
# ---------------------------------------------------------------------------
usage() {
	cat <<'EOF'
Phlix Media Server installer

Usage:
  sudo bash install.sh [options]

Options:
  --install-path PATH     Where to install the code      (default: /var/www/phlix)
  --domain HOST           Public hostname for the server  (enables TLS with --admin-email)
  --admin-email EMAIL     Email for Let's Encrypt registration
  --db-name NAME          Database name to create         (default: phlix)
  --db-user USER          Database user to create         (default: phlix)
  --db-pass PASS          Database password               (default: random)
  --db-host HOST          Database host                   (default: 127.0.0.1)
  --db-port PORT          Database port                   (default: 3306)
  --http-port PORT        HTTP listen port                (default: 8096)
  --tmdb-api-key KEY      TMDB API key (metadata)   (optional for the
                          installer, required for provider metadata)
  --hub-url URL           PHLIX_HUB_URL for hub relay     (optional)
  --service-user USER     System user to run as           (default: phlix)
  --branch NAME           Git branch (or tag, fresh install only) to install
                          (default: master; --update takes a branch, not a tag)
  --repo URL              Git repository URL              (default: detain/phlix-server)
  --tls                   Force TLS / HAProxy + certbot setup
  --no-tls                Skip TLS; HAProxy serves plain HTTP on :80
  --no-proxy              Skip HAProxy and certbot entirely (use when you
                          run your own reverse proxy or are co-hosting
                          phlix-hub on the same box — see README). Confirms
                          first unless the ack flag below is given.
  --i-know-plaintext-internet-facing
                          Acknowledge that --no-proxy leaves the HTTP port
                          serving plaintext to every network ufw allows and
                          skip the interactive confirmation. REQUIRED for
                          non-interactive --no-proxy installs.
  --update                Update an existing install (reuses env file, pulls
                          new code, runs composer + migrations, restarts service)
  --uninstall             Remove an existing (possibly partial) install
                          (prompts before each destructive step)
  --purge                 With --uninstall, also DROP the database and DELETE
                          the Let's Encrypt certificate (data loss)
  --self-test             Run the installer's pure decision helpers (TLS-bind, self-update re-exec guard)
                          (no root, no network, no side effects) and exit
  -y, --non-interactive   Never prompt; use defaults/flags (auto when no TTY)
  --interactive           Force prompts even when piped
  -h, --help              Show this help and exit

Examples:
  # Interactive install
  sudo bash install.sh

  # Fully unattended with TLS
  sudo bash install.sh -y --domain phlix.example.com --admin-email me@example.com

  # One-liner (auto non-interactive; add flags for TLS)
  curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh | sudo bash

  # Interactive uninstall (keeps DB + cert unless you confirm each)
  sudo bash install.sh --uninstall

  # Fully unattended uninstall, including DB drop and cert deletion
  sudo bash install.sh --uninstall --purge -y

  # Update an existing install to the latest master (preserves env + secrets)
  sudo bash install.sh --update -y

  # Update and switch to a different branch (a tag aborts on --update; tags are
  # for fresh installs only: install.sh --branch v1.2.3)
  sudo bash install.sh --update --branch release-1.x -y
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
# Keep the original argv so do_update can re-exec the freshly fetched installer
# with exactly the flags the operator gave (see phlix_update_should_reexec).
PHLIX_ORIG_ARGS=("$@")
while [ $# -gt 0 ]; do
	case "$1" in
	--install-path)
		INSTALL_PATH="$2"
		shift 2
		;;
	--domain)
		DOMAIN="$2"
		shift 2
		;;
	--admin-email)
		ADMIN_EMAIL="$2"
		shift 2
		;;
	--db-name)
		DB_NAME="$2"
		shift 2
		;;
	--db-user)
		DB_USER="$2"
		shift 2
		;;
	--db-pass)
		DB_PASS="$2"
		shift 2
		;;
	--db-host)
		DB_HOST="$2"
		shift 2
		;;
	--db-port)
		DB_PORT="$2"
		shift 2
		;;
	--http-port)
		HTTP_PORT="$2"
		shift 2
		;;
	--tmdb-api-key)
		TMDB_API_KEY="$2"
		shift 2
		;;
	--hub-url)
		HUB_URL="$2"
		shift 2
		;;
	--service-user)
		SERVICE_USER="$2"
		shift 2
		;;
	--branch)
		BRANCH="$2"
		shift 2
		;;
	--repo)
		REPO_URL="$2"
		shift 2
		;;
	--tls)
		WANT_TLS="yes"
		shift
		;;
	--no-tls)
		WANT_TLS="no"
		shift
		;;
	--no-proxy)
		SKIP_HAPROXY="yes"
		WANT_TLS="no"
		shift
		;;
	--i-know-plaintext-internet-facing)
		PLAINTEXT_ACK="yes"
		shift
		;;
	--update)
		ACTION="update"
		shift
		;;
	--uninstall)
		ACTION="uninstall"
		shift
		;;
	--purge)
		PURGE="yes"
		ACTION="uninstall"
		shift
		;;
	--self-test)
		SELF_TEST="yes"
		shift
		;;
	-y | --non-interactive | --yes)
		INTERACTIVE="no"
		ASSUME_YES="yes"
		shift
		;;
	--interactive)
		INTERACTIVE="yes"
		shift
		;;
	-h | --help)
		usage
		exit 0
		;;
	*) die "Unknown option: $1 (try --help)" ;;
	esac
done

# --self-test short-circuits before every environment requirement: the pure
# helpers defined near the top of this file are its entire surface. This is the
# conviction seam for the §3.1 bind-shape decision logic (audit §3.1 cure —
# the repo has no CI shell harness, so the flag IS the shell-script test leg).
if [ "$SELF_TEST" = "yes" ]; then
	phlix_run_self_tests
	exit 0
fi

# ---------------------------------------------------------------------------
# Environment checks
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "Please run as root (e.g. with sudo)."
command -v apt-get >/dev/null 2>&1 || die "This installer targets Ubuntu/Debian (apt-get not found)."

# Decide interactivity: explicit flag wins, otherwise auto-detect a TTY on stdin.
if [ "$INTERACTIVE" = "auto" ]; then
	if [ -t 0 ]; then INTERACTIVE="yes"; else INTERACTIVE="no"; fi
fi

prompt() {
	# prompt VAR "message" "default"
	local __var="$1" __msg="$2" __def="$3" __ans=""
	if [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
		read -r -p "$__msg [$__def]: " __ans </dev/tty || true
	fi
	printf -v "$__var" '%s' "${__ans:-$__def}"
}

confirm() {
	# confirm "message" -> returns 0 for yes
	[ "$ASSUME_YES" = "yes" ] && return 0
	[ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ] || return 0
	local __ans=""
	read -r -p "$1 [Y/n]: " __ans </dev/tty || true
	case "${__ans:-y}" in [Nn]*) return 1 ;; *) return 0 ;; esac
}

rand_hex() { openssl rand -hex "${1:-32}"; }
rand_pass() { openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24; }

# Idempotently ensure an env file contains `KEY=VALUE`. Used by the --update
# path to backfill keys that newer code requires but an older install never
# wrote (e.g. JWT_SECRET, added in the 0.55.0 boot guard). A line is matched on
# `^KEY=` so a key that is present but blank (`KEY=`) is treated as MISSING and
# rewritten — an empty required secret is as fatal as an absent one.
#
# Usage: phlix_ensure_env_key <env_file> <KEY> <VALUE> [comment]
# Returns 0 and prints an info line when it writes; 0 silently when already set.
phlix_ensure_env_key() {
	local file="$1" key="$2" value="$3" comment="${4:-}"
	[ -f "$file" ] || return 0
	# Present AND non-empty? Leave operator's value untouched.
	if grep -qE "^${key}=.+" "$file" 2>/dev/null; then
		return 0
	fi
	# Drop any blank `KEY=` line so we don't end up with a duplicate.
	if grep -qE "^${key}=" "$file" 2>/dev/null; then
		sed -i "/^${key}=/d" "$file"
	fi
	{
		[ -n "$comment" ] && printf '# %s\n' "$comment"
		printf '%s=%s\n' "$key" "$value"
	} >>"$file"
	info "Backfilled missing $key into $file"
}

# ---------------------------------------------------------------------------
# Swoole + php-uv (compiled from source)
#
# Workerman's coroutine event loop (and the runtime feature-detect added in
# step 0.2) wants the Swoole and php-uv extensions. The Docker images build
# both from source with a specific Swoole ./configure flag set AND pinned
# source refs — see docker/Dockerfile.base and docker/README.md ("Swoole build
# flags"). On bare metal (apt-based Debian/Ubuntu) we mirror that exact build
# here: the same flag set AND the same refs (S482 — the flags were always
# verbatim, but the refs used to float with each repo's default branch, so the
# "exact build" claim was false). swoole tracks Dockerfile.base's
# ARG SWOOLE_REF tag and php-uv tracks its ARG PHP_UV_REF commit;
# tests/Unit/Support/ThirdPartyClonePinGuardTest.php reddens CI if the two
# files' pins ever drift apart.
#
# These functions are idempotent: if `php -m` already lists the extension we
# skip the (slow) recompile entirely, so re-running install.sh is a no-op for
# the extensions on a box that already has them.
# ---------------------------------------------------------------------------

# Debian -dev packages mirroring the Alpine set in docker/Dockerfile.base.
# php-dev (version-matched below) provides phpize; the rest are the C library
# headers each Swoole ./configure flag links against.
PHLIX_SWOOLE_BUILD_PKGS="build-essential autoconf pkg-config git \
libssl-dev libuv1-dev libbrotli-dev libzstd-dev libnghttp2-dev \
libpq-dev libsqlite3-dev libc-ares-dev liburing-dev libssh2-1-dev"

# Resolve PHP's conf.d directory (Debian layout: /etc/php/X.Y/<sapi>/conf.d).
# Falls back to the CLI scan dir reported by `php --ini`.
phlix_php_confd_dir() {
	local d
	d="$(php -r 'echo (string) (PHP_CONFIG_FILE_SCAN_DIR ?: "");' 2>/dev/null)"
	if [ -z "$d" ]; then
		d="$(php --ini 2>/dev/null | awk -F': *' '/Scan for additional .ini files in/{print $2; exit}')"
	fi
	printf '%s' "$d"
}

# Install the -dev packages needed to compile Swoole + php-uv. Picks the
# version-matched phpX.Y-dev (for phpize) and falls back to php-dev.
phlix_install_ext_build_deps() {
	local php_mm phpdev_pkg
	php_mm="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
	phpdev_pkg="php-dev"
	if [ -n "$php_mm" ] && apt-cache show "php${php_mm}-dev" >/dev/null 2>&1; then
		phpdev_pkg="php${php_mm}-dev"
	fi
	log "Installing Swoole/UV build dependencies ($phpdev_pkg + C library headers)"
	# Refresh the apt index first: this is only reached when an extension is
	# actually missing (so it never fires on the common already-loaded re-run),
	# and the --update path has no earlier global apt-get update — a stale/empty
	# cache would otherwise 404 the -dev packages on a clean box.
	apt-get update -y >/dev/null
	# shellcheck disable=SC2086
	apt-get install -y $phpdev_pkg $PHLIX_SWOOLE_BUILD_PKGS >/dev/null
}

# Build + install Swoole from source with the canonical flag set, then write
# the conf.d ini. No-op if Swoole is already loaded.
phlix_build_swoole() {
	if php -m 2>/dev/null | grep -qi '^swoole$'; then
		info "Swoole already loaded — skipping build."
		return 0
	fi
	local confd tmp swoole_head
	confd="$(phlix_php_confd_dir)"
	[ -n "$confd" ] || die "Could not determine PHP conf.d directory for Swoole."
	tmp="$(mktemp -d)"
	log "Building Swoole from source (this can take several minutes)"
	# Pinned (S482) to the same release tag as docker/Dockerfile.base
	# ARG SWOOLE_REF. `--depth=1 --branch <tag>` is safe where S309 rejected
	# `--depth=1` + bare SHA: a tag always resolves to its own commit even in a
	# shallow clone. The rev-parse check makes a force-moved tag fail the
	# install LOUD before anything is compiled — if you hit it, re-verify the
	# tag upstream, then re-pin here AND docker/Dockerfile.base together (the
	# guard test holds the recorded SHA too and must move in the same commit).
	git clone --depth=1 --branch v6.2.4 https://github.com/swoole/swoole-src.git "$tmp/swoole"
	swoole_head="$(git -C "$tmp/swoole" rev-parse HEAD)"
	test "$swoole_head" = c7e514509451beafd4d70ce5b78943d51e76381c ||
		die "swoole-src tag v6.2.4 resolved to $swoole_head, not the pinned commit c7e514509451beafd4d70ce5b78943d51e76381c — upstream moved the tag; verify it before compiling."
	(
		cd "$tmp/swoole"
		phpize
		# Flags copied verbatim from docker/Dockerfile.base — do not change here;
		# change them there (the source of truth) and re-sync, refs included,
		# except the divergence named below.
		#
		# ONE DELIBERATE DIVERGENCE (S163): the base image gates --enable-iouring /
		# --enable-uring-socket behind `ARG SWOOLE_IOURING`, default OFF, because
		# Docker's default seccomp profile blocks the io_uring syscalls and every
		# Workerman worker died with "Iouring::Iouring(): ... Operation not
		# permitted" in a refork loop. A HOST install has no such sandbox —
		# systemd/phlix-server.service even sets LimitMEMLOCK=infinity — so they
		# stay ON here.
		#
		# MEASURED FLOOR (S200 note in docker/Dockerfile.base — this supersedes
		# the refuted "kernel >= 5.6; older kernels silently fall back to epoll"
		# claim once recorded here): Swoole 6.2.1's io_uring path uses ops added
		# in kernel 6.9 and its ctor REJECTS anything older, FATAL at ring init
		# ("The Iouring::ftruncate() requires `6.9` or higher Linux kernel",
		# exit 1) — no silent epoll fallback exists once the flags are compiled
		# in. The seccomp blocker is container-only; the 6.9 floor is NOT, so a
		# pre-6.9 host carries the same fatal risk with these flags left ON
		# here. Kernel-gating the host build is a behavior change deliberately
		# not made in this docs cure (flagged as a code question in the
		# server__devops review).
		#
		# 2026-11-06 (HOST-5, pin moved to v6.2.4 above): the owner's rebuilt
		# production binary (v6.2.4 / c7e5145, kernel 7.0) carries both uring
		# flags ACTIVE and healthy — `php --ri swoole` reports io_uring +
		# uring_socket enabled, and the relay regime is unaffected either way.
		# v6.2.3 fixed the coroutine-socket io_uring×TLS read/write bug behind
		# the F-1 stall class, which corroborates KEEPING them. The 6.9 floor
		# above was measured on 6.2.1 and has NOT been re-measured on 6.2.4 —
		# treat it as binding on pre-6.9 hosts until a lane measures otherwise.
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
	echo "extension=swoole.so" >"$confd/zz-swoole.ini"
	rm -rf "$tmp"
	php -m 2>/dev/null | grep -qi '^swoole$' &&
		info "Swoole installed and loaded." ||
		die "Swoole built but is not loading — check $confd/zz-swoole.ini and 'php -m'."
}

# Build + install php-uv from source, then write the conf.d ini. No-op if uv
# is already loaded.
phlix_build_uv() {
	if php -m 2>/dev/null | grep -qi '^uv$'; then
		info "php-uv already loaded — skipping build."
		return 0
	fi
	local confd tmp
	confd="$(phlix_php_confd_dir)"
	[ -n "$confd" ] || die "Could not determine PHP conf.d directory for php-uv."
	tmp="$(mktemp -d)"
	log "Building php-uv from source"
	# Pinned (S482) to the exact commit docker/Dockerfile.base ARG PHP_UV_REF
	# compiles, in S309's durable form: FULL clone (NOT --depth=1 — a shallow
	# clone reaches only the tip, so the fixed SHA would go uncheckoutable the
	# moment the 0.3.x branch moves), detached checkout, and a rev-parse
	# self-verify so a bad pin fails loud before anything is installed.
	# 2026-11-06 (HOST-5 addendum #5, owner): pin rotated from 670a609 (2023-12-03,
	# nearly three years stale at the time) to upstream HEAD e56d640 = tag v0.3.1 —
	# see the PHP_UV rotation note in docker/Dockerfile.base for the delta taken.
	git clone https://github.com/bwoebi/php-uv.git "$tmp/php-uv"
	(
		cd "$tmp/php-uv"
		git checkout --quiet --detach e56d640371a5d99eea2e9437cf4739c82e82d99e
		test "$(git rev-parse HEAD)" = e56d640371a5d99eea2e9437cf4739c82e82d99e ||
			die "php-uv did not detach to the pinned commit e56d640371a5d99eea2e9437cf4739c82e82d99e."
		phpize
		./configure --with-uv
		make -j"$(nproc)"
		make install
	)
	echo "extension=uv.so" >"$confd/zz-uv.ini"
	rm -rf "$tmp"
	php -m 2>/dev/null | grep -qi '^uv$' &&
		info "php-uv installed and loaded." ||
		die "php-uv built but is not loading — check $confd/zz-uv.ini and 'php -m'."
}

# Idempotent entry point: only installs build deps + compiles when at least
# one extension is missing. Safe to call on every run.
phlix_install_swoole_uv() {
	local need_swoole="no" need_uv="no"
	php -m 2>/dev/null | grep -qi '^swoole$' || need_swoole="yes"
	php -m 2>/dev/null | grep -qi '^uv$' || need_uv="yes"

	if [ "$need_swoole" = "no" ] && [ "$need_uv" = "no" ]; then
		info "Swoole and php-uv already loaded — nothing to build."
		return 0
	fi

	phlix_install_ext_build_deps
	[ "$need_swoole" = "yes" ] && phlix_build_swoole
	[ "$need_uv" = "yes" ] && phlix_build_uv
}

# Xdebug — production disarm (2026-11-06, owner ruling on HOST-5 Q6/Q8;
# HOST-6 verified the same one-command change live: 0 xdebug segments across
# all 38 server+hub processes, 0 new faults). Idempotent, runs on BOTH the
# fresh-install path and `--update`, so hosts provisioned by a release that
# enabled Xdebug get repaired and hosts that never had it are untouched.
#
# WHY: every observed production fault in the swoole era — 6/6 post-rebuild
# scratch-probe traps and the 3 pre-rebuild exit-139s alike — was a general
# protection fault INSIDE xdebug.so at coroutine shutdown (a coroutine-SHUTDOWN
# interaction: Socket::peek → ReactorEpoll::del → ssl_shutdown under op-line
# instrumentation), and the vendored xdebug was `mode=develop` in EVERY
# production process, including the hub's SSL listener workers. xdebug is the
# host's only demonstrated fault generator in this era; develop mode is pure
# overhead on production SSL workers.
#
# LAW: remove the ENABLEMENT files (apt's auto-linked `20-xdebug.ini` and this
# installer's own legacy `zz-xdebug.ini`), never the PACKAGE — a dev box may
# keep php-xdebug installed and deliberately re-enable it (`phpenmod xdebug`).
# FAIL-SOFT by design: a missing phpdismod, an absent conf.d, or a permissions
# refusal WARNS and continues; an update must never abort over a debug-extension
# file. The one-time notice prints only when something was actually removed.
phlix_disarm_xdebug() {
	local sapi f removed=""
	for sapi in cli fpm; do
		for f in /etc/php/*/"$sapi"/conf.d/*xdebug*.ini; do
			# Unmatched glob and dangling links: -L still sees the symlink itself.
			[ -e "$f" ] || [ -L "$f" ] || continue
			if rm -f "$f" 2>/dev/null; then
				removed="$removed $f"
			else
				warn "Could not remove xdebug enablement $f (permissions?) — production must not boot with xdebug; remove it manually: sudo rm '$f'"
			fi
		done
	done
	if command -v phpdismod >/dev/null 2>&1; then
		# Debian's own un-enablement, belt-and-braces (also clears SAPIs the
		# glob above cannot see, e.g. phpdbg). Refusal here is a warning only:
		# the conf.d sweep has already done the production-critical work.
		phpdismod xdebug >/dev/null 2>&1 ||
			warn "phpdismod xdebug returned non-zero (permissions?) — continuing; conf.d sweep above already removed what it could."
	fi
	if [ -n "$removed" ]; then
		info "Xdebug disarmed on this host — removed enablement(s):$removed"
		info "Reason: every observed production crash (both swoole eras) was a general protection fault inside xdebug.so at coroutine teardown (HOST-5 Q6/Q8; HOST-6 live verification). Production workers must not boot with xdebug. The package stays installed: re-enable deliberately with 'phpenmod xdebug' on a dev box only, and remove it again before that box serves traffic."
	fi
}

# Preflight: Workerman needs process-control / posix / socket primitives that
# some hardened php.ini setups disable. Fail loudly (and early) if any are
# listed in disable_functions so the operator gets an actionable message
# instead of a cryptic runtime crash.
phlix_check_disabled_functions() {
	local disabled missing="" fn
	disabled="$(php -r 'echo (string) ini_get("disable_functions");' 2>/dev/null)"
	# Normalise to a comma/space-delimited haystack for word matching.
	for fn in pcntl_fork pcntl_wait pcntl_signal pcntl_alarm pcntl_async_signals \
		posix_getpid posix_kill posix_setuid posix_setgid \
		proc_open proc_close proc_get_status proc_terminate \
		exec shell_exec \
		stream_socket_server stream_socket_client stream_socket_accept; do
		case ",${disabled//[[:space:]]/}," in
		*",${fn},"*) missing="${missing:+$missing }$fn" ;;
		esac
	done
	if [ -n "$missing" ]; then
		die "PHP disable_functions blocks functions Workerman requires: ${missing}.
    Remove them from the 'disable_functions' directive in your php.ini
    (and php-fpm pool config if present) before installing Phlix.
    Workerman needs pcntl_*, posix_*, proc_*, exec/shell_exec and
    stream_socket_* to fork workers and manage sockets."
	fi
	info "disable_functions preflight passed (no required functions disabled)."
}

# ---------------------------------------------------------------------------
# Kernel / resource tuning
# ---------------------------------------------------------------------------
# Implements the applicable parts of Workerman's kernel-optimization appendix:
#   https://www.workerman.net/doc/workerman/appendices/kernel-optimization.html
#
# Run on BOTH fresh install and `--update`, so an install that predates this
# function picks the tuning up on the next update. Idempotent by construction:
# we own two drop-in files outright and rewrite them wholesale rather than
# editing /etc/sysctl.conf or /etc/security/limits.conf (shared with the distro
# and with other packages).
#
# NOTE ON nofile: the limits.conf half is PAM-only and therefore does NOT
# affect the daemon — systemd ignores /etc/security/limits.conf entirely. The
# daemon's file-descriptor ceiling comes from LimitNOFILE= in the unit (see the
# unit template in section 7 and the `--update` retrofit in do_update).
PHLIX_SYSCTL_FILE="/etc/sysctl.d/99-phlix-net.conf"
PHLIX_LIMITS_FILE="/etc/security/limits.d/99-phlix.conf"

# $1 (optional): the account to grant the PAM nofile limits to. Defaults to
# $SERVICE_USER. do_update passes the User= parsed out of the live unit, which
# can differ (older installs run as www-data) — it is passed as an ARGUMENT
# rather than by reassigning the global, because $SERVICE_USER is also read by
# the chown steps on either side of the call in do_update and moving it there
# would silently re-target them.
phlix_apply_kernel_tuning() {
	local limits_user="${1:-$SERVICE_USER}"

	# Containers (docker/LXC) get a read-only or namespaced /proc/sys. Tuning is
	# the host's job there, so detect and skip rather than failing the install.
	if [ ! -w /proc/sys/net/core/somaxconn ] 2>/dev/null; then
		warn "/proc/sys is not writable (container?) — skipping kernel tuning."
		warn "Apply these on the HOST instead: see $PHLIX_SYSCTL_FILE in the repo docs."
		return 0
	fi

	log "Applying kernel network tuning ($PHLIX_SYSCTL_FILE)"
	mkdir -p "$(dirname "$PHLIX_SYSCTL_FILE")"
	cat >"$PHLIX_SYSCTL_FILE" <<'SYSCTL_EOF'
# Managed by phlix-server scripts/install.sh — rewritten on every --update.
# Local edits WILL be lost; put operator overrides in a later-sorting file
# (e.g. /etc/sysctl.d/99-zz-local.conf).
#
# Source: https://www.workerman.net/doc/workerman/appendices/kernel-optimization.html
# Only the knobs that actually improve on a modern kernel's defaults are set
# here. The ones from that page we deliberately SKIP are listed at the bottom
# with the reason — please read it before "restoring" any of them.

# Accept-queue cap. The kernel silently clamps EVERY listen() backlog to this
# value, so without it Workerman's large backlog is truncated to the 4096
# default (visible as Send-Q=4096 on the listen sockets in `ss -ltn`).
net.core.somaxconn = 65535

# Half-open (SYN_RECV) queue depth. Absorbs connection bursts and SYN floods
# instead of dropping SYNs. Entries are allocated on demand, so a large cap
# costs nothing at idle.
net.ipv4.tcp_max_syn_backlog = 262144

# Per-CPU ingress queue between the NIC and the protocol stack. The 1000
# default is sized for 1GbE; raise it so bursts are queued, not dropped.
net.core.netdev_max_backlog = 30000

# Ephemeral port range for OUTBOUND sockets — relay tunnels to the hub, MySQL,
# and metadata/artwork fetches all consume these.
#
# This WIDENS the range. Count is (high - low + 1), so dropping the floor adds
# ports rather than removing them:
#     kernel default  32768-60999 = 28232 ports
#     this setting    16384-65535 = 49152 ports   (+20920, +74%)
#     Workerman page  10240-65000 = 54761 ports
#
# We stop at 16384 instead of the page's 10240 floor. That trades 5609 ports
# for keeping the whole registered-port band below 16384 free, so an ephemeral
# outbound socket can never squat on a port some other daemon on this box wants
# to bind later. It also keeps every port Phlix itself listens on (2206, 8096,
# 8097, 8800, 8802-8805) outside the ephemeral range by construction — no
# net.ipv4.ip_local_reserved_ports entry needed. Going below 16384 would
# require one.
net.ipv4.ip_local_port_range = 16384 65535

# ---------------------------------------------------------------------------
# Deliberately NOT set (all four are on the Workerman page). Do not add them:
#
#   fs.file-max = 6815744
#     Modern kernels derive a far larger default from RAM. Writing the page's
#     value LOWERS the system-wide cap by orders of magnitude.
#
#   net.ipv4.tcp_max_tw_buckets = 20000
#     The kernel default is 262144. This is a CAP, not a target: once exceeded
#     the kernel destroys TIME_WAIT sockets early and logs "TCP: time wait
#     bucket table overflow". 20000 would be a 13x downgrade.
#
#   net.ipv4.tcp_tw_recycle = 0
#     REMOVED from Linux in 4.12 (2017). The key does not exist, and listing a
#     nonexistent key makes `sysctl --system` exit non-zero. It is also already
#     off by definition. (The page itself flags this.)
#
#   net.netfilter.nf_conntrack_max = 2621440
#     Only meaningful when a stateful firewall/NAT actually tracks this box's
#     traffic. Each entry pins ~376 B of unswappable kernel memory (~1 GB at
#     2.6M), and raising max WITHOUT also raising nf_conntrack_buckets just
#     lengthens the hash chains. Raise both, together, only if
#     /proc/sys/net/netfilter/nf_conntrack_count approaches nf_conntrack_max.
# ---------------------------------------------------------------------------
SYSCTL_EOF
	chmod 644 "$PHLIX_SYSCTL_FILE"

	# Apply just our file. Tolerate failure: a hardened/containerised kernel may
	# refuse individual keys, and that must not abort an otherwise good install.
	if sysctl -p "$PHLIX_SYSCTL_FILE" >/dev/null 2>&1; then
		info "sysctl applied: somaxconn=$(sysctl -n net.core.somaxconn 2>/dev/null)," \
			"syn_backlog=$(sysctl -n net.ipv4.tcp_max_syn_backlog 2>/dev/null)," \
			"netdev_backlog=$(sysctl -n net.core.netdev_max_backlog 2>/dev/null)"
	else
		warn "sysctl -p $PHLIX_SYSCTL_FILE reported an error — values may not be live."
		warn "Re-check with: sysctl -p $PHLIX_SYSCTL_FILE"
	fi

	# PAM limits: covers LOGIN SHELLS and hand-run CLI scripts
	# (php scripts/run-library-scan-worker.php, backfill-*.php, …). It does NOT
	# cover the systemd service — that is LimitNOFILE= in the unit.
	log "Applying PAM nofile limits ($PHLIX_LIMITS_FILE)"
	mkdir -p "$(dirname "$PHLIX_LIMITS_FILE")"
	cat >"$PHLIX_LIMITS_FILE" <<LIMITS_EOF
# Managed by phlix-server scripts/install.sh — rewritten on every --update.
#
# PAM-only: applies to login shells and CLI invocations, NOT to the
# phlix-server systemd unit (systemd never reads limits.conf — the daemon's
# ceiling is LimitNOFILE= in /etc/systemd/system/phlix-server.service).
#
# Soft is 65536 rather than the hard value: a very large SOFT limit makes any
# tool that still loops 0..RLIMIT_NOFILE to close inherited descriptors crawl.
# Anything that needs more can raise itself to the hard limit with ulimit -n.
root                soft    nofile  65536
root                hard    nofile  1048576
${limits_user}      soft    nofile  65536
${limits_user}      hard    nofile  1048576
LIMITS_EOF
	chmod 644 "$PHLIX_LIMITS_FILE"
	info "PAM nofile: soft 65536 / hard 1048576 for root and '$limits_user'."
}

# Parse the User= line from a systemd unit. Empty when missing.
phlix_systemd_unit_user() {
	[ -f "$1" ] || {
		printf ''
		return
	}
	awk -F= '/^User=/{print $2; exit}' "$1" 2>/dev/null
}

# True when the other Phlix service is installed AND its systemd unit's
# User= matches the supplied name.
phlix_other_service_uses_user() {
	local our_user="$1" other_service="$2"
	local other_user
	other_user="$(phlix_systemd_unit_user "$other_service")"
	[ -n "$other_user" ] && [ "$other_user" = "$our_user" ]
}

# True when a username looks like a dedicated Phlix-created system account.
phlix_user_is_dedicated() {
	case "$1" in
	phlix | phlix-hub | phlix-server) return 0 ;;
	*) return 1 ;;
	esac
}

# One-off repair (M15): retrofit ExecReload from SIGUSR1 to SIGUSR2.
# Workerman's master treats SIGUSR2 as the GRACEFUL reload (children drain
# in-flight requests) and SIGUSR1 as the NON-graceful one (children stop
# immediately, SIGKILL armed after stopTimeout). The admin restart endpoint
# has sent SIGUSR2 since the AdminRestartController fix, but units generated
# by installer revisions predating this one still hard-cut the :8097 WS
# workers, in-flight HLS segment delivery and the hub relay tunnel on every
# `systemctl reload` — including the live prod deploy path. Only the exact
# known-generated value is rewritten, so an operator customisation survives;
# idempotent (after the flip the pattern no longer matches).
phlix_repair_exec_reload() {
	local unit="$1"
	[ -f "$unit" ] || return 0
	if grep -q '^ExecReload=/bin/kill -SIGUSR1 \$MAINPID$' "$unit" 2>/dev/null; then
		log "Retrofitting ExecReload in $unit from SIGUSR1 to SIGUSR2 (graceful reload)"
		sed -i 's|^ExecReload=/bin/kill -SIGUSR1 \$MAINPID$|ExecReload=/bin/kill -SIGUSR2 $MAINPID|' "$unit"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi
}

# ---------------------------------------------------------------------------
# Shared HAProxy management
#
# Both phlix-hub and phlix-server can be installed on the same host. Each
# install drops a fragment file into /etc/haproxy/phlix-managed/ and then
# a shared rebuilder assembles /etc/haproxy/haproxy.cfg from every
# fragment it finds. Uninstall removes one fragment and rebuilds — when
# the last fragment is gone, the pre-Phlix backup is restored (or the
# config is removed outright).
# ---------------------------------------------------------------------------
PHLIX_HAPROXY_MGR_DIR="/etc/haproxy/phlix-managed"
PHLIX_HAPROXY_CFG="/etc/haproxy/haproxy.cfg"
PHLIX_HAPROXY_BAK="/etc/haproxy/haproxy.cfg.pre-phlix.bak"
PHLIX_HAPROXY_CERTS_DIR="/etc/haproxy/certs"
PHLIX_HAPROXY_MARKER="# phlix-managed: rebuilt by phlix install scripts — do not edit"

# Consolidate older Phlix backup names into the shared one.
phlix_haproxy_migrate_backup() {
	if [ ! -f "$PHLIX_HAPROXY_BAK" ]; then
		for old in /etc/haproxy/haproxy.cfg.phlix.bak /etc/haproxy/haproxy.cfg.phlix-server.bak; do
			if [ -f "$old" ]; then
				mv "$old" "$PHLIX_HAPROXY_BAK"
				return 0
			fi
		done
	fi
	rm -f /etc/haproxy/haproxy.cfg.phlix.bak /etc/haproxy/haproxy.cfg.phlix-server.bak 2>/dev/null || true
}

# Extract one section (`fe_http` | `fe_https` | `backends`) from a fragment.
phlix_haproxy_extract_section() {
	awk -v target="$2" '
    /^## section: / { in_sec = ($3 == target) ? 1 : 0; next }
    /^## end$/      { in_sec = 0; next }
    in_sec          { print }
  ' "$1"
}

# Rebuild /etc/haproxy/haproxy.cfg from all *.cfg.fragment files in the
# manager dir. If none remain, restore the pre-Phlix backup or remove the
# file entirely.
phlix_haproxy_rebuild() {
	phlix_haproxy_migrate_backup

	local fragments=()
	if [ -d "$PHLIX_HAPROXY_MGR_DIR" ]; then
		while IFS= read -r -d '' f; do
			fragments+=("$f")
		done < <(find "$PHLIX_HAPROXY_MGR_DIR" -maxdepth 1 -name '*.cfg.fragment' -print0 2>/dev/null | sort -z)
	fi

	if [ ${#fragments[@]} -eq 0 ]; then
		if [ -f "$PHLIX_HAPROXY_BAK" ]; then
			mv "$PHLIX_HAPROXY_BAK" "$PHLIX_HAPROXY_CFG"
			info "Restored pre-Phlix HAProxy configuration."
		elif [ -f "$PHLIX_HAPROXY_CFG" ] &&
			grep -qE 'phlix-managed|^backend be_hub\b|^backend be_client_relay\b|^backend be_phlix_server\b' \
				"$PHLIX_HAPROXY_CFG" 2>/dev/null; then
			rm -f "$PHLIX_HAPROXY_CFG"
			info "Removed Phlix-managed HAProxy configuration (no pre-Phlix config to restore)."
		fi
		rmdir "$PHLIX_HAPROXY_MGR_DIR" 2>/dev/null || true
		return 0
	fi

	if [ -f "$PHLIX_HAPROXY_CFG" ] && [ ! -f "$PHLIX_HAPROXY_BAK" ] &&
		! grep -qF "phlix-managed" "$PHLIX_HAPROXY_CFG" 2>/dev/null; then
		cp "$PHLIX_HAPROXY_CFG" "$PHLIX_HAPROXY_BAK"
	fi

	# --- fe_https bind selection (audit AUDIT-wildcard-cert-rotation.md §3.1) --
	# Collect annotated tls domains from EVERY project's fragment: the hub and
	# server installers share the `# Mode    : tls` + `# Domain  : <dom>` header
	# law, so one rebuild cures every annotated domain on the host.
	local tls_domains=() live_domains=() f dom mode
	for f in "${fragments[@]}"; do
		mode="$(sed -n 's/^# Mode[[:space:]]*: *//p' "$f" | head -n1)"
		[ "$mode" = "tls" ] || continue
		dom="$(sed -n 's/^# Domain[[:space:]]*: *//p' "$f" | head -n1)"
		case "$dom" in '' | '<none>') continue ;; esac
		tls_domains+=("$dom")
	done

	# MEASURE (never assume) whether this box's haproxy can bind the split
	# crt/key-file form against the certbot live/ symlinks.
	local shape="merged"
	if [ ${#tls_domains[@]} -gt 0 ] && phlix_haproxy_supports_tls_key_file; then
		shape="live"
	fi

	if [ "$shape" = "live" ]; then
		for dom in "${tls_domains[@]}"; do
			if [ -f "${PHLIX_LE_LIVE_DIR}/${dom}/fullchain.pem" ] &&
				[ -f "${PHLIX_LE_LIVE_DIR}/${dom}/privkey.pem" ]; then
				live_domains+=("$dom")
				# A live-bound domain's merge copy must not linger: it is exactly the
				# §3.1 stale-bytes artifact and would silently resurrect a dir bind on
				# the next pre-2.9-style rebuild.
				if [ -f "$PHLIX_HAPROXY_CERTS_DIR/${dom}.pem" ]; then
					rm -f "$PHLIX_HAPROXY_CERTS_DIR/${dom}.pem"
					info "Removed stale merge copy ${PHLIX_HAPROXY_CERTS_DIR}/${dom}.pem (live/ paths are bound directly)."
				fi
			elif [ -f "$PHLIX_HAPROXY_CERTS_DIR/${dom}.pem" ]; then
				warn "TLS fragment for ${dom} has no ${PHLIX_LE_LIVE_DIR}/${dom} material; the merged copy in ${PHLIX_HAPROXY_CERTS_DIR} stays as best-effort fallback — it goes stale if that cert rotates outside certbot on THIS host (§3.1 class)."
			fi
		done
	fi

	local dir_pems="no"
	if [ -d "$PHLIX_HAPROXY_CERTS_DIR" ] &&
		[ -n "$(find "$PHLIX_HAPROXY_CERTS_DIR" -maxdepth 1 -name '*.pem' -print -quit 2>/dev/null)" ]; then
		dir_pems="yes"
	fi

	# fe_https exists when there is something to bind: live material, or certs
	# in the legacy dir (stray copies, pre-2.9 merges, operator PEMs).
	local emit_https="no" https_binds=""
	if { [ "$shape" = "live" ] && [ ${#live_domains[@]} -gt 0 ]; } ||
		[ "$dir_pems" = "yes" ]; then
		emit_https="yes"
		https_binds="$(phlix_haproxy_render_https_binds "$shape" "$PHLIX_HAPROXY_CERTS_DIR" "$dir_pems" ${live_domains[@]+"${live_domains[@]}"})"
	fi

	{
		printf '%s\n' "$PHLIX_HAPROXY_MARKER"
		cat <<'BASE'
# Per-project sections under /etc/haproxy/phlix-managed/*.cfg.fragment.
# Stop Phlix from managing this file by running --uninstall on every
# installed Phlix project.

global
    log /dev/log local0
    maxconn 4096
    tune.ssl.default-dh-param 2048

defaults
    log     global
    mode    http
    option  httplog
    option  forwardfor
    timeout connect 5s
    timeout client  1h
    timeout server  1h
    timeout tunnel  1h

frontend fe_http
    bind :80
    # ACME HTTP-01 challenges for Phlix-managed certs (audit §3.4): renewals
    # authenticate against the RUNNING proxy from /var/www/phlix-acme (see
    # /etc/cron.d/phlix-server-certbot), so a renewal is a content swap + reload,
    # never a connection tear. `http-request return file` needs HAProxy >= 2.2;
    # the Phlix support matrix ships >= 2.9 (off-matrix ancient hosts fail the
    # haproxy -c validation at reload — loudly, at install time).
    # The token regex is an anchored SINGLE segment over [A-Za-z0-9._~-]: no
    # slash, no percent — traversal out of the webroot is structurally
    # impossible; anything else under the ACME path gets a plain 404.
    acl is_acme path_beg /.well-known/acme-challenge/
    acl acme_safe path_reg ^/\.well-known/acme-challenge/[A-Za-z0-9._~-]+$
    http-request return file "/var/www/phlix-acme%[path]" status 200 content-type "text/plain" if is_acme acme_safe
    http-request return status 404 content-type "text/plain" string "not an ACME token\n" if is_acme
BASE

		local f section
		for f in "${fragments[@]}"; do
			section="$(phlix_haproxy_extract_section "$f" fe_http)"
			if [ -n "$section" ]; then
				printf '\n    # --- %s ---\n%s\n' "$(basename "$f" .cfg.fragment)" "$section"
			fi
		done

		printf '\n    default_backend be_phlix_default\n'

		if [ "$emit_https" = "yes" ]; then
			# Bind lines come from phlix_haproxy_render_https_binds (shape-selected
			# live/ split binds, legacy dir bind, or both — see §3.1 block above).
			printf '\nfrontend fe_https\n%s\n    http-request set-header X-Forwarded-Proto https\n' "$https_binds"
			for f in "${fragments[@]}"; do
				section="$(phlix_haproxy_extract_section "$f" fe_https)"
				if [ -n "$section" ]; then
					printf '\n    # --- %s ---\n%s\n' "$(basename "$f" .cfg.fragment)" "$section"
				fi
			done

			printf '\n    default_backend be_phlix_default\n'
		fi

		cat <<'DEFAULT_BACKEND'

backend be_phlix_default
    http-request return status 404 content-type "text/plain" string "No Phlix backend matched.\n"
DEFAULT_BACKEND

		for f in "${fragments[@]}"; do
			section="$(phlix_haproxy_extract_section "$f" backends)"
			if [ -n "$section" ]; then
				printf '\n# === %s backends ===\n%s\n' "$(basename "$f" .cfg.fragment)" "$section"
			fi
		done
	} >"$PHLIX_HAPROXY_CFG"
}

# Write the phlix-server fragment. Args: <mode tls|http> <domain or empty>
phlix_haproxy_write_fragment_server() {
	local mode="$1" dom="$2"
	mkdir -p "$PHLIX_HAPROXY_MGR_DIR"
	local fragment="$PHLIX_HAPROXY_MGR_DIR/phlix-server.cfg.fragment"
	{
		cat <<EOF
# Phlix Media Server HAProxy fragment — managed by phlix-server install.sh
# Project : phlix-server
# Mode    : ${mode}
# Domain  : ${dom:-<none>}
# Written : $(date -u +%FT%TZ)

EOF
		if [ "$mode" = "tls" ] && [ -n "$dom" ]; then
			cat <<EOF
## section: fe_http
    acl is_phlix_server_host hdr(host) -i ${dom}
    redirect scheme https code 301 if is_phlix_server_host
## end

## section: fe_https
    acl is_phlix_server_host hdr(host) -i ${dom}
    use_backend be_phlix_server if is_phlix_server_host
## end

EOF
		elif [ -n "$dom" ]; then
			cat <<EOF
## section: fe_http
    acl is_phlix_server_host hdr(host) -i ${dom}
    use_backend be_phlix_server if is_phlix_server_host
## end

EOF
		else
			cat <<EOF
## section: fe_http
    use_backend be_phlix_server
## end

EOF
		fi

		cat <<EOF
## section: backends
backend be_phlix_server
    # WebSocket upgrade is detected per-request; both REST and WS traffic
    # share the single HTTP port on the Workerman side.
    option http-server-close
    server phlix 127.0.0.1:${HTTP_PORT}
## end
EOF
	} >"$fragment"
}

# Validate + reload haproxy (called after every rebuild).
phlix_haproxy_reload() {
	[ "$SKIP_HAPROXY" = "yes" ] && return 0
	command -v haproxy >/dev/null 2>&1 || return 0
	if [ ! -f "$PHLIX_HAPROXY_CFG" ]; then
		systemctl stop haproxy >/dev/null 2>&1 || true
		systemctl disable haproxy >/dev/null 2>&1 || true
		return 0
	fi
	if ! haproxy -c -f "$PHLIX_HAPROXY_CFG" >/dev/null 2>&1; then
		haproxy -c -f "$PHLIX_HAPROXY_CFG" || true
		die "HAProxy config validation failed — see message above."
	fi
	systemctl enable haproxy >/dev/null 2>&1 || true
	systemctl reload haproxy >/dev/null 2>&1 || systemctl restart haproxy
}

# Convert an older (pre-fragment) phlix-server install into the fragment-based
# layout. Idempotent.
phlix_haproxy_migrate_if_needed_server() {
	[ "$SKIP_HAPROXY" = "yes" ] && return 0
	[ -f "$PHLIX_HAPROXY_MGR_DIR/phlix-server.cfg.fragment" ] && return 0
	[ -f /etc/haproxy/haproxy.cfg ] || return 0
	if ! grep -qE '^backend be_phlix_server\b' /etc/haproxy/haproxy.cfg 2>/dev/null &&
		! grep -qF "phlix-managed" /etc/haproxy/haproxy.cfg 2>/dev/null; then
		return 0
	fi

	log "Migrating phlix-server HAProxy config to the fragment-based layout"

	local migrated_domain="" migrated_mode="http"
	if [ -f "$ENV_FILE" ]; then
		migrated_domain="$(grep -m1 -E '^PHLIX_DOMAIN=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
	fi
	if [ -n "$migrated_domain" ] && [ -f "/etc/haproxy/certs/${migrated_domain}.pem" ]; then
		migrated_mode="tls"
	fi
	info "Detected mode: $migrated_mode (domain: ${migrated_domain:-<none>})"

	phlix_haproxy_write_fragment_server "$migrated_mode" "$migrated_domain"
	phlix_haproxy_rebuild
	phlix_haproxy_reload
	info "HAProxy migrated. The legacy snapshot was renamed to $PHLIX_HAPROXY_BAK."
}

# ---------------------------------------------------------------------------
# Uninstall
# ---------------------------------------------------------------------------
do_uninstall() {
	log "Phlix Media Server uninstaller"

	# Prefer the install path recorded in the systemd unit (the operator may
	# have used a non-default --install-path on the original run). An explicit
	# --install-path flag on this invocation overrides everything.
	local svc_workdir=""
	if [ -f "$SERVICE_FILE" ] && [ "$INSTALL_PATH" = "/var/www/phlix" ]; then
		svc_workdir="$(awk -F= '/^WorkingDirectory=/{print $2; exit}' "$SERVICE_FILE" 2>/dev/null || true)"
		[ -n "$svc_workdir" ] && INSTALL_PATH="$svc_workdir"
	fi

	# Pull DB / domain details from the env file so we can clean up the
	# matching MySQL grants and HAProxy/Let's Encrypt artefacts.
	local env_db_name="" env_db_user="" env_db_host="" env_domain=""
	if [ -f "$ENV_FILE" ]; then
		env_db_name="$(grep -m1 -E '^PHLIX_DATABASE_NAME=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
		env_db_user="$(grep -m1 -E '^PHLIX_DATABASE_USER=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
		env_db_host="$(grep -m1 -E '^PHLIX_DATABASE_HOST=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
		env_domain="$(grep -m1 -E '^PHLIX_DOMAIN=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
	fi
	local U_DB_NAME="${env_db_name:-$DB_NAME}"
	local U_DB_USER="${env_db_user:-$DB_USER}"
	local U_DB_HOST="${env_db_host:-$DB_HOST}"
	local U_DOMAIN="${DOMAIN:-$env_domain}"

	# Detect artefacts.
	local found=0
	local svc="" envf="" envdir="" instdir=""
	local hap_fragment="" hap_other_fragments="no" hap_backup="" hapcert=""
	local cron="" hook="" le_dir=""
	local data_root="" log_dir="" run_dir=""
	[ -f "$SERVICE_FILE" ] && svc="$SERVICE_FILE" && found=1
	[ -f "$ENV_FILE" ] && envf="$ENV_FILE" && found=1
	[ -d "$(dirname "$ENV_FILE")" ] && envdir="$(dirname "$ENV_FILE")"
	[ -d "$INSTALL_PATH" ] && instdir="$INSTALL_PATH" && found=1
	[ -d "$DATA_ROOT" ] && data_root="$DATA_ROOT" && found=1
	[ -d "$LOG_DIR" ] && log_dir="$LOG_DIR" && found=1
	[ -d "$RUN_DIR" ] && run_dir="$RUN_DIR"
	if [ -f "$PHLIX_HAPROXY_MGR_DIR/phlix-server.cfg.fragment" ]; then
		hap_fragment="$PHLIX_HAPROXY_MGR_DIR/phlix-server.cfg.fragment"
		found=1
	fi
	# Note whether any *other* Phlix project fragments would remain.
	if [ -d "$PHLIX_HAPROXY_MGR_DIR" ] &&
		[ -n "$(find "$PHLIX_HAPROXY_MGR_DIR" -maxdepth 1 -name '*.cfg.fragment' \
			! -name 'phlix-server.cfg.fragment' -print -quit 2>/dev/null)" ]; then
		hap_other_fragments="yes"
	fi
	# Pre-Phlix backup (new shared name + legacy server-specific name).
	for b in "$PHLIX_HAPROXY_BAK" /etc/haproxy/haproxy.cfg.phlix-server.bak; do
		[ -f "$b" ] && hap_backup="$b" && break
	done
	if [ -n "$U_DOMAIN" ] && [ -f "/etc/haproxy/certs/${U_DOMAIN}.pem" ]; then
		hapcert="/etc/haproxy/certs/${U_DOMAIN}.pem"
		found=1
	fi
	[ -f /etc/cron.d/phlix-server-certbot ] && cron="/etc/cron.d/phlix-server-certbot" && found=1
	[ -f /etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh ] &&
		hook="/etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh" && found=1
	if [ -n "$U_DOMAIN" ] && [ -d "/etc/letsencrypt/live/${U_DOMAIN}" ]; then
		le_dir="/etc/letsencrypt/live/${U_DOMAIN}"
		found=1
	fi

	# Database lookup is best-effort: requires mysql client + running server.
	local has_db="no"
	if [ -n "$U_DB_NAME" ] && command -v mysql >/dev/null 2>&1; then
		if mysql -N -e "SHOW DATABASES LIKE '${U_DB_NAME}';" 2>/dev/null |
			grep -qx "${U_DB_NAME}"; then
			has_db="yes"
			found=1
		fi
	fi

	# Detect the actual service user from the systemd unit (falling back to
	# the current default).
	local svc_user=""
	if [ -n "$svc" ]; then
		svc_user="$(phlix_systemd_unit_user "$svc")"
	fi
	[ -n "$svc_user" ] || svc_user="$SERVICE_USER"
	local svc_user_present="no"
	id -u "$svc_user" >/dev/null 2>&1 && svc_user_present="yes" && found=1

	if [ "$found" -eq 0 ]; then
		info "No Phlix Media Server artefacts found — nothing to uninstall."
		return 0
	fi

	echo
	log "Found the following Phlix Media Server artefacts:"
	[ -n "$svc" ] && info " - systemd service      : $svc"
	[ -n "$envf" ] && info " - environment file     : $envf"
	[ -n "$instdir" ] && info " - install directory    : $instdir"
	[ -n "$data_root" ] && info " - data directory       : $data_root"
	[ -n "$log_dir" ] && info " - log directory        : $log_dir"
	if [ "$svc_user_present" = "yes" ]; then
		if phlix_other_service_uses_user "$svc_user" /etc/systemd/system/phlix-hub.service; then
			info " - service user         : $svc_user (shared with phlix-hub — will NOT be removed)"
		elif ! phlix_user_is_dedicated "$svc_user"; then
			info " - service user         : $svc_user (shared OS account — will NOT be removed)"
		else
			info " - service user         : $svc_user (dedicated; removable with --purge or interactive confirm)"
		fi
	fi
	[ "$has_db" = "yes" ] && info " - MySQL database       : ${U_DB_NAME} (user '${U_DB_USER}'@'${U_DB_HOST}')"
	if [ -n "$hap_fragment" ]; then
		if [ "$hap_other_fragments" = "yes" ]; then
			info " - HAProxy fragment     : $hap_fragment (will be removed; haproxy.cfg rebuilt for remaining Phlix projects)"
		elif [ -n "$hap_backup" ]; then
			info " - HAProxy fragment     : $hap_fragment (last Phlix fragment; pre-Phlix config restored from $hap_backup)"
		else
			info " - HAProxy fragment     : $hap_fragment (last Phlix fragment; haproxy.cfg removed entirely)"
		fi
	fi
	[ -n "$hapcert" ] && info " - HAProxy TLS cert     : $hapcert"
	[ -n "$hook" ] && info " - Certbot deploy hook  : $hook"
	[ -n "$cron" ] && info " - Certbot renewal cron : $cron"
	[ -n "$le_dir" ] && info " - Let's Encrypt cert   : $le_dir"
	echo

	# Destructive opt-ins (DB drop, cert delete). --purge says yes to both;
	# otherwise we only ask interactively. -y alone keeps the data.
	local drop_db="no"
	if [ "$has_db" = "yes" ]; then
		if [ "$PURGE" = "yes" ]; then
			drop_db="yes"
		elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
			confirm "Drop MySQL database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'? (DATA LOSS)" &&
				drop_db="yes"
		fi
	fi

	local drop_data="no"
	if [ -n "$data_root" ]; then
		if [ "$PURGE" = "yes" ]; then
			drop_data="yes"
		elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
			confirm "Delete data directory '$data_root' (config, library cache, backups)? (DATA LOSS)" &&
				drop_data="yes"
		fi
	fi

	local revoke_cert="no"
	if [ -n "$le_dir" ]; then
		if [ "$PURGE" = "yes" ]; then
			revoke_cert="yes"
		elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
			confirm "Delete Let's Encrypt certificate for '${U_DOMAIN}' via 'certbot delete'?" &&
				revoke_cert="yes"
		fi
	fi

	# Opt-in to system-user removal. Only offered when the user is dedicated
	# (phlix / phlix-hub / phlix-server) AND the other Phlix project isn't
	# also using it.
	local drop_user="no"
	if [ "$svc_user_present" = "yes" ] &&
		phlix_user_is_dedicated "$svc_user" &&
		! phlix_other_service_uses_user "$svc_user" /etc/systemd/system/phlix-hub.service; then
		if [ "$PURGE" = "yes" ]; then
			drop_user="yes"
		elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
			confirm "Delete the dedicated system user '$svc_user'?" &&
				drop_user="yes"
		fi
	fi

	[ "$has_db" = "yes" ] && { [ "$drop_db" = "yes" ] && info "Will DROP database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'." ||
		info "Will KEEP MySQL database and user."; }
	[ -n "$data_root" ] && { [ "$drop_data" = "yes" ] && info "Will DELETE data directory '$data_root'." ||
		info "Will KEEP data directory '$data_root'."; }
	[ -n "$le_dir" ] && { [ "$revoke_cert" = "yes" ] && info "Will DELETE Let's Encrypt certificate '${U_DOMAIN}'." ||
		info "Will KEEP Let's Encrypt certificate '${U_DOMAIN}'."; }
	if [ "$svc_user_present" = "yes" ] && phlix_user_is_dedicated "$svc_user" &&
		! phlix_other_service_uses_user "$svc_user" /etc/systemd/system/phlix-hub.service; then
		[ "$drop_user" = "yes" ] && info "Will DELETE system user '$svc_user'." ||
			info "Will KEEP system user '$svc_user'."
	fi
	echo

	# Final gate. Piped/non-interactive runs require explicit -y.
	if [ "$ASSUME_YES" != "yes" ]; then
		if [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
			confirm "Proceed with uninstall?" || die "Aborted by user."
		else
			die "Refusing to uninstall non-interactively without -y."
		fi
	fi

	# ---- Execute ----

	# 1. systemd
	if [ -n "$svc" ]; then
		log "Stopping and removing $SERVICE_NAME service"
		systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
		systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
		rm -f "$svc"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 2. HAProxy fragment + rebuild. Removing this project's fragment and
	# rebuilding will either drop the phlix-server frontend/backend (other
	# Phlix projects remain) or restore the pre-Phlix config / delete the
	# file outright (we were the last fragment).
	if [ -n "$hap_fragment" ]; then
		log "Removing HAProxy fragment and rebuilding shared config"
		rm -f "$hap_fragment"
		[ -n "$hapcert" ] && {
			log "Removing HAProxy TLS certificate"
			rm -f "$hapcert"
		}
		phlix_haproxy_rebuild
		phlix_haproxy_reload
	else
		[ -n "$hapcert" ] && {
			log "Removing HAProxy TLS certificate"
			rm -f "$hapcert"
		}
	fi

	# 4z. Kernel-tuning drop-ins. Removing the files un-declares the tuning for
	# the next boot, which is the correct uninstall behaviour. We deliberately do
	# NOT restore the previous LIVE sysctl values: we never recorded them, and
	# phlix-hub may be co-installed and still relying on the same knobs (it ships
	# its own identically-valued drop-in, so its file survives this).
	for f in "$PHLIX_SYSCTL_FILE" "$PHLIX_LIMITS_FILE"; do
		[ -f "$f" ] && {
			log "Removing tuning drop-in $f"
			rm -f "$f"
		}
	done

	# 5. Certbot artefacts
	[ -n "$cron" ] && {
		log "Removing certbot cron entry"
		rm -f "$cron"
	}
	[ -n "$hook" ] && {
		log "Removing certbot deploy hook"
		rm -f "$hook"
	}
	if [ -n "$cron" ] || [ -n "$hook" ]; then
		# §3.5 symmetry: the install masked the distro certbot.timer because Phlix
		# owned renewal. Once the LAST Phlix renewal owner on this box is gone,
		# hand the timer back to its distro default state. (phlix-hub writes its
		# own differently-named cron + hook; their presence keeps the timer retired
		# for the surviving owner's benefit.)
		if [ ! -f /etc/cron.d/phlix-hub-certbot ] && [ ! -f /etc/cron.d/phlix-server-certbot ]; then
			systemctl unmask certbot.timer >/dev/null 2>&1 || true
			systemctl preset certbot.timer >/dev/null 2>&1 || true
			info "Restored the distro certbot.timer to its package default (no Phlix renewal owner remains)."
		fi
	fi
	if [ "$revoke_cert" = "yes" ] && command -v certbot >/dev/null 2>&1; then
		log "Deleting Let's Encrypt certificate for ${U_DOMAIN}"
		certbot delete --non-interactive --cert-name "${U_DOMAIN}" >/dev/null 2>&1 ||
			warn "certbot delete failed — remove /etc/letsencrypt/live/${U_DOMAIN}/ manually if needed."
	fi

	# 6. Database
	if [ "$drop_db" = "yes" ]; then
		log "Dropping MySQL database and user"
		if mysql <<SQL >/dev/null 2>&1; then
DROP DATABASE IF EXISTS \`${U_DB_NAME}\`;
DROP USER IF EXISTS '${U_DB_USER}'@'${U_DB_HOST}';
FLUSH PRIVILEGES;
SQL

			info "Dropped database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'."
		else
			warn "MySQL cleanup failed — drop the database and user manually if needed."
		fi
	fi

	# 7. Install directory (sanity-check the path before rm -rf).
	if [ -n "$instdir" ]; then
		case "$instdir" in
		"" | / | /bin | /boot | /dev | /etc | /home | /lib* | /opt | /proc | /root | /run | /sbin | /srv | /sys | /tmp | /usr | /var | /var/www)
			warn "Refusing to remove suspicious install path: $instdir"
			;;
		*)
			log "Removing install directory $instdir"
			rm -rf "$instdir"
			;;
		esac
	fi

	# 8. Data + log + run dirs (data only when --purge or interactively confirmed).
	if [ "$drop_data" = "yes" ] && [ -n "$data_root" ]; then
		case "$data_root" in
		"" | / | /bin | /boot | /dev | /etc | /home | /lib* | /opt | /proc | /root | /run | /sbin | /srv | /sys | /tmp | /usr | /var)
			warn "Refusing to remove suspicious data path: $data_root"
			;;
		*)
			log "Removing data directory $data_root"
			rm -rf "$data_root"
			;;
		esac
	fi
	[ -n "$log_dir" ] && {
		log "Removing log directory $log_dir"
		rm -rf "$log_dir"
	}
	[ -n "$run_dir" ] && rm -rf "$run_dir"

	# 9. Env file (before user removal — chown depends on user existing).
	[ -n "$envf" ] && {
		log "Removing environment file $envf"
		rm -f "$envf"
	}
	# Remove /etc/phlix/ if it's now empty.
	if [ -n "$envdir" ] && [ -d "$envdir" ]; then
		rmdir "$envdir" 2>/dev/null || true
	fi

	# 10. System user (after every owned artefact is gone).
	if [ "$drop_user" = "yes" ]; then
		log "Removing system user '$svc_user'"
		userdel "$svc_user" >/dev/null 2>&1 ||
			warn "userdel '$svc_user' failed — remove it manually with 'sudo userdel $svc_user'."
	fi

	echo
	log "Phlix Media Server uninstallation complete."
	info "System packages (PHP, MySQL, HAProxy, certbot, ffmpeg) were left installed."
	info "Remove them with 'sudo apt remove ...' if you no longer need them."
	[ "$has_db" = "yes" ] && [ "$drop_db" != "yes" ] &&
		info "MySQL database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}' were preserved."
	[ -n "$data_root" ] && [ "$drop_data" != "yes" ] &&
		info "Data directory '$data_root' was preserved."
	[ -n "$le_dir" ] && [ "$revoke_cert" != "yes" ] &&
		info "Let's Encrypt certificate '${U_DOMAIN}' was preserved at $le_dir."
	[ "$svc_user_present" = "yes" ] && [ "$drop_user" != "yes" ] && phlix_user_is_dedicated "$svc_user" &&
		info "System user '$svc_user' was preserved."
}

if [ "$ACTION" = "uninstall" ]; then
	do_uninstall
	exit 0
fi

# ---------------------------------------------------------------------------
# Update
# ---------------------------------------------------------------------------
do_update() {
	log "Phlix Media Server updater"

	# Prefer the install path recorded in the systemd unit (unless the user
	# passed --install-path explicitly).
	if [ -f "$SERVICE_FILE" ] && [ "$INSTALL_PATH" = "/var/www/phlix" ]; then
		local svc_workdir=""
		svc_workdir="$(awk -F= '/^WorkingDirectory=/{print $2; exit}' "$SERVICE_FILE" 2>/dev/null || true)"
		[ -n "$svc_workdir" ] && INSTALL_PATH="$svc_workdir"
	fi

	# Sanity-check: we need a real install to update.
	[ -f "$ENV_FILE" ] || die "No env file at $ENV_FILE — run a fresh install first."
	[ -d "$INSTALL_PATH" ] || die "Install path '$INSTALL_PATH' not found — run a fresh install first."
	[ -d "$INSTALL_PATH/.git" ] || die "Install path '$INSTALL_PATH' is not a git checkout — cannot fast-forward updates."

	# Read existing env so we can run migrations with the right credentials.
	local env_db_pass env_db_name
	env_db_pass="$(grep -m1 -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2- || true)"
	env_db_name="$(grep -m1 -E '^PHLIX_DATABASE_NAME=' "$ENV_FILE" | cut -d= -f2- || true)"
	[ -n "$env_db_pass" ] || warn "DB_PASSWORD missing from $ENV_FILE — migrations may fail."

	# The initial install chowns the tree to $SERVICE_USER. Running git as
	# root against a non-root-owned worktree trips CVE-2022-24765 ("dubious
	# ownership"), so detect the owner and run git as that user.
	local repo_owner current_user
	repo_owner="$(stat -c '%U' "$INSTALL_PATH" 2>/dev/null || true)"
	[ -n "$repo_owner" ] || repo_owner="root"
	current_user="$(id -un)"
	local -a as_owner=()
	if [ "$repo_owner" != "$current_user" ]; then
		command -v sudo >/dev/null 2>&1 ||
			die "Install dir owned by '$repo_owner' but sudo is not available."
		as_owner=(sudo -H -u "$repo_owner" --)
	fi

	local prev_commit current_branch
	prev_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
	# Re-exec'd child: the parent already confirmed and fetched; report the
	# commit the update started from, not the (already-updated) HEAD.
	if [ "${PHLIX_INSTALL_REEXEC:-}" = "1" ] && [ -n "${PHLIX_UPDATE_PREV_COMMIT:-}" ]; then
		prev_commit="$PHLIX_UPDATE_PREV_COMMIT"
	fi
	current_branch="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

	if [ -n "$("${as_owner[@]}" git -C "$INSTALL_PATH" status --porcelain 2>/dev/null)" ]; then
		warn "Uncommitted local changes in $INSTALL_PATH will be discarded by 'git reset --hard'."
	fi

	if [ "${PHLIX_INSTALL_REEXEC:-}" = "1" ]; then
		log "Continuing update with the installer from the updated release"
		local new_commit
		new_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
		info "Code: $prev_commit -> $new_commit"
	else
		echo
		log "Update summary"
		info "Install path : $INSTALL_PATH"
		info "Owned by     : $repo_owner"
		info "Env file     : $ENV_FILE  (DB password + secrets preserved)"
		info "Database     : ${env_db_name:-$DB_NAME}"
		info "Branch       : $current_branch  ->  $BRANCH"
		info "Commit       : $prev_commit  ->  (fetching…)"
		info "Repo         : $REPO_URL"
		echo
		confirm "Proceed with update?" || die "Aborted by user."

		# 1. Pull updated code as the install dir owner.
		log "Fetching code"
		"${as_owner[@]}" git -C "$INSTALL_PATH" fetch --depth 1 origin "$BRANCH"
		"${as_owner[@]}" git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 ||
			"${as_owner[@]}" git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
		"${as_owner[@]}" git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
		local new_commit
		new_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
		info "Code: $prev_commit -> $new_commit"

		# 1b. Self-update: hand over to the NEWLY fetched installer so the rest
		# of the update (composer, extensions, migrations, systemd/HAProxy
		# rewrites) runs the new release's logic, not this (previous) copy's.
		# PHLIX_INSTALL_REEXEC=1 stops the child from re-exec'ing again.
		local new_installer="$INSTALL_PATH/scripts/install.sh"
		if phlix_update_should_reexec "${PHLIX_INSTALL_REEXEC:-}" "$new_installer"; then
			log "Re-executing the updated installer ($new_installer)"
			export PHLIX_INSTALL_REEXEC=1 PHLIX_UPDATE_PREV_COMMIT="$prev_commit"
			exec bash "$new_installer" "${PHLIX_ORIG_ARGS[@]}"
		fi
	fi

	# 2. Refresh PHP deps (composer.lock-driven, no surprise upgrades).
	log "Updating PHP dependencies"
	(cd "$INSTALL_PATH" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction)

	# 2b. Re-assert the Workerman preflight and ensure Swoole + php-uv are
	# present (repairs an install that pre-dates them). Idempotent no-op when
	# both extensions already load.
	log "Checking PHP disable_functions for Workerman compatibility"
	phlix_check_disabled_functions
	log "Ensuring Swoole + php-uv extensions are installed"
	phlix_install_swoole_uv

	# 2c. Xdebug production disarm (2026-11-06, owner ruling on HOST-5 Q6/Q8):
	# hosts provisioned by a release that enabled xdebug (apt php-xdebug +
	# legacy zz-xdebug.ini) get the enablements removed here, so an update can
	# no longer leave the coroutine-teardown GPF generator loaded in production
	# workers. Fail-soft + idempotent; the package itself is never purged.
	log "Ensuring Xdebug is disarmed on the production path"
	phlix_disarm_xdebug

	# 3. Clear Smarty compile cache so templates pick up changes immediately.
	if [ -d "$INSTALL_PATH/templates_c" ]; then
		log "Clearing Smarty compile cache"
		find "$INSTALL_PATH/templates_c" -mindepth 1 -delete 2>/dev/null || true
	fi

	# Runtime-writable dirs under the install path. The plugin installer and the
	# ui-theme extractor write here at runtime, but ProtectSystem=strict makes the
	# install tree read-only EXCEPT the unit's ReadWritePaths — which lists
	# /var/www/phlix/var. These must EXIST before the service starts (systemd
	# binds ReadWritePaths at start), so create them here, ahead of the chown.
	mkdir -p "$INSTALL_PATH/.logs" "$LOG_DIR" "$RUN_DIR" \
		"$INSTALL_PATH/var/plugins" "$INSTALL_PATH/var/themes" "$INSTALL_PATH/var/cache" \
		"$ARTWORK_DIR" "$SUBTITLE_DIR" "$ANIDB_CACHE_DIR" "$SEGMENT_DIR"
	# Restore ownership the install was running with.
	if [ "$repo_owner" != "root" ] && id -u "$repo_owner" >/dev/null 2>&1; then
		chown -R "$repo_owner:$repo_owner" "$INSTALL_PATH"
		chown -R "$repo_owner:$repo_owner" "$LOG_DIR" "$RUN_DIR" 2>/dev/null || true
		chown "$repo_owner:$repo_owner" "$ARTWORK_DIR" 2>/dev/null || true
		chown "$repo_owner:$repo_owner" "$SUBTITLE_DIR" 2>/dev/null || true
		chown -R "$repo_owner:$repo_owner" "$ANIDB_CACHE_DIR" 2>/dev/null || true
		chown "$repo_owner:$repo_owner" "$SEGMENT_DIR" 2>/dev/null || true
	fi

	# 4. Apply migrations. Re-running on each update is safe: the runner keeps a
	# `schema_migrations` ledger (SV-4.9) and skips a file it already recorded
	# with a matching checksum, and any file it does re-run tolerates the replay
	# because statements use `IF NOT EXISTS` and the runner downgrades duplicate
	# column/key errors to notes.
	#
	# ⚠ S159: run-migrations.php now exits 1 when a GENUINE (non-idempotent)
	# migration error was recorded — it used to always exit 0. Under this script's
	# `set -euo pipefail` that ABORTS the update here, deliberately and with no
	# `|| true`: this is an attended, operator-driven path, and continuing would
	# restart the service against a half-migrated schema. State after such an
	# abort is recoverable: the new code and vendor/ are on disk but the service
	# has NOT been restarted, so the running process keeps serving the old code
	# until the operator fixes the migration and re-runs the update.
	log "Running migrations"
	DB_PASSWORD="$env_db_pass" \
		php "$INSTALL_PATH/scripts/run-migrations.php"

	# 4b. One-off migration: if this install pre-dates the fragment-based
	# HAProxy layout, convert it now. Idempotent on subsequent updates.
	phlix_haproxy_migrate_if_needed_server

	# 4c. Migrate the systemd unit if it's pre-`start.php`. Older installs
	# had `ExecStart=… public/index.php start`; the new entry point is
	# start.php at the repo root. Touches only the ExecStart line so any
	# operator customisation elsewhere in the unit is preserved.
	if [ -f "$SERVICE_FILE" ] && grep -q 'public/index\.php start' "$SERVICE_FILE" 2>/dev/null; then
		log "Migrating systemd unit ExecStart to start.php"
		sed -i 's|public/index\.php start|start.php start|' "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4d. One-off migration: ensure the install dir's writable var/ (plugin
	# installs + ui-theme extraction land in var/plugins and var/themes) is in
	# ReadWritePaths. Without it, ProtectSystem=strict keeps /var/www/phlix/var
	# read-only and plugin install fails with "Cannot create plugins base
	# directory". Appends the exact path to the existing ReadWritePaths line only
	# when not already present, preserving any operator customisation. Idempotent.
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^ReadWritePaths=' "$SERVICE_FILE" 2>/dev/null &&
		! awk -v p="$INSTALL_PATH/var" '
          /^ReadWritePaths=/ { s=$0; sub(/^ReadWritePaths=/,"",s); n=split(s,a,/[[:space:]]+/);
            for (i=1;i<=n;i++) if (a[i]==p) f=1 } END { exit f?0:1 }' "$SERVICE_FILE"; then
		log "Adding $INSTALL_PATH/var to systemd ReadWritePaths (plugin/theme writes)"
		sed -i "s|^\(ReadWritePaths=.*\)\$|\1 $INSTALL_PATH/var|" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4e. One-off migration: ensure the install dir's config/ is in ReadWritePaths.
	# The hub-pairing flow persists runtime state there — hub-enrollment.json and
	# hub-subdomain.json, plus hub-server-key.pem (the Ed25519 private key) for
	# installs PAIRED BEFORE SH-019, whose legacy key keeps winning the resolver's
	# compat probe; fresh pairings now birth the key under var/ (4d above). But
	# ProtectSystem=strict keeps config/ read-only unless listed, so "Initiate
	# pairing" fails 500 with "Failed to write Ed25519 private key: config/..."
	# whenever anything still must land there.
	# Appends the exact path only when absent, preserving operator customisation.
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^ReadWritePaths=' "$SERVICE_FILE" 2>/dev/null &&
		! awk -v p="$INSTALL_PATH/config" '
          /^ReadWritePaths=/ { s=$0; sub(/^ReadWritePaths=/,"",s); n=split(s,a,/[[:space:]]+/);
            for (i=1;i<=n;i++) if (a[i]==p) f=1 } END { exit f?0:1 }' "$SERVICE_FILE"; then
		log "Adding $INSTALL_PATH/config to systemd ReadWritePaths (hub pairing key/state writes)"
		sed -i "s|^\(ReadWritePaths=.*\)\$|\1 $INSTALL_PATH/config|" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4g. One-off migration: ensure the local artwork cache root is in
	# ReadWritePaths. The scanner downloads TMDB posters into $ARTWORK_DIR
	# (config/artwork.php's ARTWORK_STORAGE_PATH, default /var/artwork), but
	# ProtectSystem=strict keeps it read-only unless listed, so mkdir fails with
	# "Read-only file system". Worse, because nothing ever caches, every scan
	# re-downloads every poster via blocking cURL across the concurrent matcher —
	# eventually tripping Swoole's "all coroutines are asleep - deadlock" abort.
	# The path must EXIST before it is added (a missing ReadWritePath fails the
	# unit at 226/NAMESPACE), so create + chown it first. Idempotent.
	mkdir -p "$ARTWORK_DIR" 2>/dev/null || true
	chown "$SERVICE_USER:$SERVICE_USER" "$ARTWORK_DIR" 2>/dev/null || true
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^ReadWritePaths=' "$SERVICE_FILE" 2>/dev/null &&
		! awk -v p="$ARTWORK_DIR" '
          /^ReadWritePaths=/ { s=$0; sub(/^ReadWritePaths=/,"",s); n=split(s,a,/[[:space:]]+/);
            for (i=1;i<=n;i++) if (a[i]==p) f=1 } END { exit f?0:1 }' "$SERVICE_FILE"; then
		log "Adding $ARTWORK_DIR to systemd ReadWritePaths (local artwork cache writes)"
		sed -i "s|^\(ReadWritePaths=.*\)\$|\1 $ARTWORK_DIR|" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4g-B. One-off migration: ensure the DOWNLOADED external-subtitle store is in
	# ReadWritePaths (F3). The on-demand subtitle download writes .vtt files into
	# $SUBTITLE_DIR (config/subtitles.php's SUBTITLE_STORAGE_PATH, default
	# /var/subtitles), but ProtectSystem=strict keeps it read-only unless listed —
	# the /var/artwork lesson. Create + chown it first (a missing ReadWritePath
	# fails the unit at 226/NAMESPACE), then append only when absent. Idempotent.
	mkdir -p "$SUBTITLE_DIR" 2>/dev/null || true
	chown "$SERVICE_USER:$SERVICE_USER" "$SUBTITLE_DIR" 2>/dev/null || true
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^ReadWritePaths=' "$SERVICE_FILE" 2>/dev/null &&
		! awk -v p="$SUBTITLE_DIR" '
          /^ReadWritePaths=/ { s=$0; sub(/^ReadWritePaths=/,"",s); n=split(s,a,/[[:space:]]+/);
            for (i=1;i<=n;i++) if (a[i]==p) f=1 } END { exit f?0:1 }' "$SERVICE_FILE"; then
		log "Adding $SUBTITLE_DIR to systemd ReadWritePaths (downloaded subtitle writes)"
		sed -i "s|^\(ReadWritePaths=.*\)\$|\1 $SUBTITLE_DIR|" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4h. One-off migration: ensure the anidb plugin's persistent title-dump cache
	# ($ANIDB_CACHE_DIR, default /var/cache/phlix/anidb) is in ReadWritePaths. The
	# plugin writes title_index.json there (PHLIX_ANIDB_CACHE_DIR), but
	# ProtectSystem=strict keeps it read-only unless listed — the /var/artwork
	# lesson. Create + chown it first (a missing ReadWritePath fails the unit at
	# 226/NAMESPACE), then append the exact path only when absent. Idempotent.
	mkdir -p "$ANIDB_CACHE_DIR" 2>/dev/null || true
	chown -R "$SERVICE_USER:$SERVICE_USER" "$ANIDB_CACHE_DIR" 2>/dev/null || true
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^ReadWritePaths=' "$SERVICE_FILE" 2>/dev/null &&
		! awk -v p="$ANIDB_CACHE_DIR" '
          /^ReadWritePaths=/ { s=$0; sub(/^ReadWritePaths=/,"",s); n=split(s,a,/[[:space:]]+/);
            for (i=1;i<=n;i++) if (a[i]==p) f=1 } END { exit f?0:1 }' "$SERVICE_FILE"; then
		log "Adding $ANIDB_CACHE_DIR to systemd ReadWritePaths (anidb title-cache writes)"
		sed -i "s|^\(ReadWritePaths=.*\)\$|\1 $ANIDB_CACHE_DIR|" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4h-B. Ensure the systemd unit exports PHLIX_ANIDB_CACHE_DIR. Older units
	# (generated before this key existed) have no Environment= line for it, so the
	# plugin would fall back to sys_get_temp_dir(); inject it right after the
	# existing PHLIX_ENV Environment line, only when absent. Idempotent.
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^Environment="PHLIX_ENV=' "$SERVICE_FILE" 2>/dev/null &&
		! grep -q '^Environment="PHLIX_ANIDB_CACHE_DIR=' "$SERVICE_FILE" 2>/dev/null; then
		log "Adding PHLIX_ANIDB_CACHE_DIR=$ANIDB_CACHE_DIR to systemd unit Environment"
		sed -i "/^Environment=\"PHLIX_ENV=/a Environment=\"PHLIX_ANIDB_CACHE_DIR=$ANIDB_CACHE_DIR\"" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4h-C. Ensure the systemd unit can WRITE the persistent HLS segment cache
	# ($SEGMENT_DIR, default /var/phlix/hls) AND exports its location (C5). Since
	# C5 config/server.php resolves PHLIX_SEGMENT_DIR into `hls.segment_dir`;
	# without the ReadWritePaths entry ProtectSystem=strict keeps the dir read-only
	# (the /var/artwork lesson), and without the Environment= line the daemon keeps
	# using the PrivateTmp-wiped temp dir — the cache dies on every restart and
	# cold first-plays re-pay the inline-transcode latency (FIELD-hub-phlix). An
	# unusable override aborts boot fail-loud (SegmentCacheDirectory), so provision
	# it here: create + chown first (a missing ReadWritePath fails the unit at
	# 226/NAMESPACE), then append the exact path only when absent, then inject the
	# env export right after the existing PHLIX_ENV line, only when absent.
	# Idempotent, mirrors 4h/4h-B.
	mkdir -p "$SEGMENT_DIR" 2>/dev/null || true
	chown -R "$SERVICE_USER:$SERVICE_USER" "$SEGMENT_DIR" 2>/dev/null || true
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^ReadWritePaths=' "$SERVICE_FILE" 2>/dev/null &&
		! awk -v p="$SEGMENT_DIR" '
          /^ReadWritePaths=/ { s=$0; sub(/^ReadWritePaths=/,"",s); n=split(s,a,/[[:space:]]+/);
            for (i=1;i<=n;i++) if (a[i]==p) f=1 } END { exit f?0:1 }' "$SERVICE_FILE"; then
		log "Adding $SEGMENT_DIR to systemd ReadWritePaths (persistent HLS segment cache)"
		sed -i "s|^\(ReadWritePaths=.*\)\$|\1 $SEGMENT_DIR|" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi
	if [ -f "$SERVICE_FILE" ] &&
		grep -q '^Environment="PHLIX_ENV=' "$SERVICE_FILE" 2>/dev/null &&
		! grep -q '^Environment="PHLIX_SEGMENT_DIR=' "$SERVICE_FILE" 2>/dev/null; then
		log "Adding PHLIX_SEGMENT_DIR=$SEGMENT_DIR to systemd unit Environment"
		sed -i "/^Environment=\"PHLIX_ENV=/a Environment=\"PHLIX_SEGMENT_DIR=$SEGMENT_DIR\"" "$SERVICE_FILE"
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4i. Kernel/resource tuning (Workerman kernel-optimization appendix). Two
	# halves, because they use two different mechanisms:
	#
	#   (a) sysctl + PAM limits — rewritten wholesale by phlix_apply_kernel_tuning,
	#       so this is a plain unconditional call.
	#   (b) LimitNOFILE= in the unit — systemd IGNORES /etc/security/limits.conf,
	#       so (a) does nothing for the daemon. Without this line the service
	#       inherits systemd's soft 1024, which is reachable under load (one fd
	#       per live connection + open HLS segment files) and surfaces as
	#       "Too many open files" with clients dropped mid-stream.
	#
	# Retrofit LimitNOFILE only when absent, so an operator who tuned it by hand
	# keeps their value. Anchored on LimitMEMLOCK= when present (both are
	# resource limits and belong together), else inserted before ExecStart=.
	#
	# Resolve the real service user from the unit: installs predating the
	# dedicated 'phlix' account run as www-data, and the PAM drop-in must name
	# whoever the unit actually uses rather than this script's default. Passed as
	# an argument — NOT by reassigning $SERVICE_USER, which the chown steps above
	# and below this block also read.
	local unit_user=""
	unit_user="$(phlix_systemd_unit_user "$SERVICE_FILE")"
	phlix_apply_kernel_tuning "${unit_user:-$SERVICE_USER}"

	if [ -f "$SERVICE_FILE" ] && ! grep -q '^LimitNOFILE=' "$SERVICE_FILE" 2>/dev/null; then
		log "Adding LimitNOFILE=1048576 to systemd unit (systemd default soft is 1024)"
		if grep -q '^LimitMEMLOCK=' "$SERVICE_FILE" 2>/dev/null; then
			sed -i "\\|^LimitMEMLOCK=|a\\
\\
# File-descriptor ceiling for the master and every worker it forks. systemd\\
# does NOT read /etc/security/limits.conf, so this line is what the daemon\\
# actually gets (default soft 1024 / hard 524288).\\
LimitNOFILE=1048576" "$SERVICE_FILE"
		else
			sed -i "\\|^ExecStart=|i\\
# File-descriptor ceiling for the master and every worker it forks. systemd\\
# does NOT read /etc/security/limits.conf, so this line is what the daemon\\
# actually gets (default soft 1024 / hard 524288).\\
LimitNOFILE=1048576\\
" "$SERVICE_FILE"
		fi
		systemctl daemon-reload >/dev/null 2>&1 || true
	fi

	# 4i-B. Retrofit ExecReload in an existing unit from SIGUSR1 to SIGUSR2
	# (M15). SIGUSR1 is Workerman's NON-graceful reload; every installer-
	# generated unit predating this repair hard-cuts WS/HLS/relay work on
	# `systemctl reload`. Only the exact known-generated value is rewritten.
	# Idempotent. See phlix_repair_exec_reload().
	phlix_repair_exec_reload "$SERVICE_FILE"

	# 4f. Backfill env keys that newer code requires but an older install never
	# wrote. JWT_SECRET became a hard boot requirement in 0.55.0
	# (AuthServicesProvider::assertSecretConfigured) — a legacy install that
	# predates it can carry only the docker-only PHLIX_SECRET_KEY alias (newer
	# installers write JWT_SECRET directly), so it would now refuse to start.
	# Generate a unique
	# secret and append it (idempotent: a real, non-empty JWT_SECRET is preserved).
	log "Ensuring required env keys are present"
	phlix_ensure_env_key "$ENV_FILE" JWT_SECRET "$(rand_hex 32)" \
		"JWT signing secret (REQUIRED) — auth JWTs + HMAC-derived media signed URLs."
	# C5: record the persistent HLS segment cache location. A real, non-empty
	# PHLIX_SEGMENT_DIR is preserved (an operator who pointed the cache elsewhere
	# keeps their path); installs predating C5 gain the provisioned default so the
	# cache starts surviving PrivateTmp restarts from the next boot.
	phlix_ensure_env_key "$ENV_FILE" PHLIX_SEGMENT_DIR "$SEGMENT_DIR" \
		"Persistent HLS segment cache dir — survives restarts (PrivateTmp wipes the temp default). Unusable values abort boot."

	# 4f-B. Backfill the DB_* keys config/database.php actually reads. Older
	# installs wrote ONLY DB_PASSWORD + the PHLIX_DATABASE_* aliases (which the
	# code does NOT read), so a non-default --db-host/--db-port/--db-name/--db-user
	# install silently ran against the DEFAULT database. Source each value from the
	# recorded PHLIX_DATABASE_* line so the operator's real settings are preserved.
	# Idempotent: phlix_ensure_env_key no-ops when a non-empty DB_* key already
	# exists (so an operator-set value is never clobbered).
	phlix_ensure_env_key "$ENV_FILE" DB_HOST \
		"$(grep -m1 '^PHLIX_DATABASE_HOST=' "$ENV_FILE" | cut -d= -f2-)" \
		"DB host (read by config/database.php)"
	phlix_ensure_env_key "$ENV_FILE" DB_PORT \
		"$(grep -m1 '^PHLIX_DATABASE_PORT=' "$ENV_FILE" | cut -d= -f2-)" \
		"DB port (read by config/database.php)"
	phlix_ensure_env_key "$ENV_FILE" DB_NAME \
		"$(grep -m1 '^PHLIX_DATABASE_NAME=' "$ENV_FILE" | cut -d= -f2-)" \
		"DB name (read by config/database.php)"
	phlix_ensure_env_key "$ENV_FILE" DB_USER \
		"$(grep -m1 '^PHLIX_DATABASE_USER=' "$ENV_FILE" | cut -d= -f2-)" \
		"DB user (read by config/database.php)"

	# 4f-C. Document TRUSTED_PROXIES (SV-4.15) for existing installs. This key
	# CANNOT be safely backfilled with phlix_ensure_env_key: that only appends an
	# UNCOMMENTED KEY=VALUE, and forcing a value here would either duplicate the
	# code's loopback-only default or, worse, guess wrong. Instead append the
	# commented guidance block ONLY when no TRUSTED_PROXIES line (commented or not)
	# exists yet — preserving any value the operator has already set.
	if ! grep -qE '^[[:space:]]*#?[[:space:]]*TRUSTED_PROXIES=' "$ENV_FILE" 2>/dev/null; then
		{
			printf '\n# TRUSTED_PROXIES (SV-4.15): comma-separated reverse-proxy IPs/CIDRs whose\n'
			printf '# X-Forwarded-For / X-Real-IP are trusted when deriving the real client IP used\n'
			printf '# to key auth rate-limiting. UNSET = loopback-only default (127.0.0.0/8,::1/128),\n'
			printf '# correct for the standard single-box loopback proxy. Set it to your proxy'"'"'s\n'
			printf '# IP/CIDR ONLY if a NON-loopback proxy/load-balancer fronts this server, else\n'
			printf '# every client is rate-limited under the proxy'"'"'s single IP. Do NOT add broad\n'
			printf '# RFC1918 ranges on a loopback-proxy box (re-enables XFF spoofing).\n'
			printf '#TRUSTED_PROXIES=127.0.0.0/8,::1/128\n'
		} >>"$ENV_FILE"
		info "Added TRUSTED_PROXIES guidance to $ENV_FILE"
	fi

	# Keep ownership/permissions consistent with the original install.
	chmod 640 "$ENV_FILE" 2>/dev/null || true
	chown root:"$SERVICE_USER" "$ENV_FILE" 2>/dev/null || true

	# 5. Restart the service.
	if [ -f "$SERVICE_FILE" ]; then
		log "Restarting $SERVICE_NAME service"
		systemctl daemon-reload >/dev/null 2>&1 || true
		systemctl restart "$SERVICE_NAME"
		sleep 2
		if systemctl is-active --quiet "$SERVICE_NAME"; then
			info "$SERVICE_NAME service is running."
		else
			warn "$SERVICE_NAME did not start cleanly — check 'journalctl -u $SERVICE_NAME -e'."
		fi
	else
		warn "No systemd unit at $SERVICE_FILE — start the server manually."
	fi

	# 6. Health check.
	if curl -fsS --max-time 5 "http://localhost:${HTTP_PORT}/health" >/dev/null 2>&1; then
		info "Health check OK: http://localhost:${HTTP_PORT}/health"
	else
		warn "Health check did not return success — inspect 'journalctl -u $SERVICE_NAME -e'."
	fi

	echo
	log "Phlix Media Server update complete."
	info "Branch : $BRANCH"
	info "Commit : $prev_commit -> $new_commit"
	if [ "$prev_commit" = "$new_commit" ]; then
		info "(already up to date)"
	fi

	# do_update runs as a bare statement under `set -euo pipefail` (see the
	# `if [ "$ACTION" = "update" ]` dispatch below), so its return value becomes
	# the script's exit status. Return success explicitly: without this the final
	# command above evaluates to 1 whenever the commit actually changed (i.e. a
	# real update — the common case), which under `set -e` aborts the script
	# before the trailing `exit 0` and makes a successful update look like a
	# failure to anyone checking $?.
	return 0
}

if [ "$ACTION" = "update" ]; then
	do_update
	exit 0
fi

# ---------------------------------------------------------------------------
# Gather configuration
# ---------------------------------------------------------------------------
log "Phlix Media Server installer"
[ "$INTERACTIVE" = "yes" ] && info "Interactive mode — press Enter to accept each default." ||
	info "Non-interactive mode — using defaults/flags."

prompt INSTALL_PATH "Install path" "$INSTALL_PATH"
prompt DB_NAME "Database name" "$DB_NAME"
prompt DB_USER "Database user" "$DB_USER"
if [ -z "$DB_PASS" ]; then
	if [ "$INTERACTIVE" = "yes" ]; then
		prompt DB_PASS "Database password (blank = generate random)" ""
	fi
	[ -z "$DB_PASS" ] && DB_PASS="$(rand_pass)" && info "Generated a random database password."
fi
prompt DOMAIN "Public hostname (blank = no TLS, serve plain HTTP)" "$DOMAIN"

if [ -n "$DOMAIN" ] && [ "$WANT_TLS" != "no" ]; then
	prompt ADMIN_EMAIL "Email for Let's Encrypt (blank = skip TLS)" "$ADMIN_EMAIL"
fi

# Resolve final TLS decision.
if [ "$WANT_TLS" = "yes" ]; then
	[ -n "$DOMAIN" ] && [ -n "$ADMIN_EMAIL" ] || die "--tls requires --domain and --admin-email."
	TLS_ENABLED="yes"
elif [ "$WANT_TLS" = "no" ]; then
	TLS_ENABLED="no"
else
	if [ -n "$DOMAIN" ] && [ -n "$ADMIN_EMAIL" ]; then TLS_ENABLED="yes"; else TLS_ENABLED="no"; fi
fi

# SV-4.15: when the operator runs their OWN reverse proxy (--no-proxy), ask for
# its IP/CIDR so the real client IP (not the proxy's) keys auth rate-limiting.
# The bundled HAProxy path fronts Phlix over loopback, where the code's
# loopback-only default is already correct, so this is skipped there.
if [ "$SKIP_HAPROXY" = "yes" ]; then
	if [ "$INTERACTIVE" = "yes" ]; then
		prompt TRUSTED_PROXIES \
			"Reverse-proxy IP/CIDR for X-Forwarded-For (blank = loopback default)" ""
	else
		warn "--no-proxy: if a NON-loopback proxy fronts this server, set TRUSTED_PROXIES in $ENV_FILE to its IP/CIDR, else every client is rate-limited under the proxy's single IP (see the commented block in the env file)."
	fi
fi

# Public domain for the env file.
PUBLIC_DOMAIN="${DOMAIN:-$(hostname -f 2>/dev/null || hostname)}"

# JWT signing secret. REQUIRED: src/Common/Container/Providers/AuthServicesProvider.php
# refuses to boot (assertSecretConfigured) when JWT_SECRET is unset or still the
# shipped 'default-secret-change-me' sentinel — JWTs and media signed URLs would
# otherwise be forgeable. Generate a unique high-entropy value per install.
JWT_SECRET="$(rand_hex 32)"

echo
log "Configuration summary"
info "Install path : $INSTALL_PATH"
info "Service user : $SERVICE_USER"
info "Database     : $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"
info "Public domain: $PUBLIC_DOMAIN"
info "HTTP port    : $HTTP_PORT  (DLNA $DLNA_PORT/udp, behind HAProxy 80/443)"
info "TLS / HAProxy: $TLS_ENABLED"
echo
confirm "Proceed with installation?" || die "Aborted by user."

# ---------------------------------------------------------------------------
# 1. System packages
# ---------------------------------------------------------------------------
log "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl git unzip openssl >/dev/null
# Distro PHP; Ubuntu 24.04 ships PHP 8.3 by default. ffmpeg is required for
# transcoding; mysql-server for the database; haproxy for the reverse proxy.
#
# php-ldap (S314): `ext-ldap` has been a HARD composer.json requirement since
# before S163, but this list never installed it — a fresh install's
# `composer install` (line ~1707) would refuse. The live box happens to have it,
# which is why the gap survived. Every OTHER member of the derived contract in
# scripts/required-php-extensions.php is already covered here: ctype, exif,
# fileinfo, iconv, posix and sockets ship in php8.3-common (pulled by php-cli);
# dom/libxml/simplexml come with php-xml; hash, json, filter, openssl, pcntl,
# random, sodium and zlib are built into the php8.3-cli binary on Ubuntu 24.04.
#
# php-xdebug REMOVED from this list (2026-11-06, owner ruling on HOST-5 Q6/Q8):
# the apt package self-enables (conf.d/20-xdebug.ini) on every SAPI it sees, and
# every observed production fault in the swoole era — 6/6 scratch-probe traps
# post-rebuild plus the 3 pre-rebuild exit-139s — was a general protection
# fault INSIDE xdebug.so at coroutine teardown, zero in swoole.so. Fresh
# installs no longer get it; hosts that have it from an earlier release are
# disarmed by phlix_disarm_xdebug (enablement files removed; the package itself
# is deliberately never purged — dev boxes keep it dormant and re-enable at
# will). Production workers must not boot with xdebug loaded.
apt-get install -y \
	php-cli php-mysql php-mbstring php-curl php-xml php-bcmath php-gd php-zip php-ldap \
	mysql-server ffmpeg >/dev/null
if [ "$SKIP_HAPROXY" = "yes" ]; then
	info "Skipping HAProxy install (--no-proxy)."
elif [ "$TLS_ENABLED" = "yes" ]; then
	apt-get install -y haproxy certbot >/dev/null
else
	apt-get install -y haproxy >/dev/null
fi

PHP_VER="$(php -r 'echo PHP_VERSION;')"
case "$PHP_VER" in
8.3* | 8.4* | 8.5* | 9.*) info "PHP $PHP_VER OK" ;;
*) warn "PHP $PHP_VER detected — Phlix Media Server requires 8.3+. Install may not run correctly." ;;
esac

# Composer
if ! command -v composer >/dev/null 2>&1; then
	log "Installing Composer"
	curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
	php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null
	rm -f /tmp/composer-setup.php
fi

# Workerman runtime preflight: make sure the process-control / socket
# functions it relies on are not disabled in php.ini.
log "Checking PHP disable_functions for Workerman compatibility"
phlix_check_disabled_functions

# Swoole + php-uv — compile from source with the canonical flag set
# (docker/Dockerfile.base). Idempotent: skipped if already loaded.
log "Ensuring Swoole + php-uv extensions are installed"
phlix_install_swoole_uv

# Xdebug — production disarm (was: install + configure). The apt list no longer
# carries php-xdebug (fresh installs never get the enablement), and
# phlix_disarm_xdebug repairs hosts that carry enablements from an earlier
# release (apt's auto-linked 20-xdebug.ini, this installer's legacy
# zz-xdebug.ini). Rationale + fail-soft law in the function's header; owner
# ruling 2026-11-06 on HOST-5 Q6/Q8. The old block shipped mode=off by default
# with a random per-install trigger (M-SERVER-73 / W-min104 era) — the fault
# class is the LOAD itself at coroutine teardown (every crash in the HOST-5
# evidence set, both build eras), so a dormant-by-config gate was not enough:
# the extension must not load in production workers at all.
log "Ensuring Xdebug is disarmed on the production path"
phlix_disarm_xdebug

# ---------------------------------------------------------------------------
# 2. Service user + directories
# ---------------------------------------------------------------------------
log "Ensuring system user '$SERVICE_USER' exists"
# NOTE: phlix_apply_kernel_tuning runs AFTER this block — it writes a PAM
# limits drop-in naming $SERVICE_USER, so the account must exist first.
if ! id -u "$SERVICE_USER" >/dev/null 2>&1; then
	useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
	info "Created user '$SERVICE_USER'."
fi

# Kernel/resource tuning (Workerman kernel-optimization appendix). Needs
# $SERVICE_USER to exist (PAM drop-in names it) and must land before the unit
# is written in section 7. Also re-applied by do_update.
phlix_apply_kernel_tuning

log "Creating runtime directories"
mkdir -p "$DATA_ROOT"/{config,data,logs,backups} "$LOG_DIR" "$RUN_DIR" "$(dirname "$ENV_FILE")"
chown -R "$SERVICE_USER:$SERVICE_USER" "$DATA_ROOT" "$LOG_DIR" "$RUN_DIR"

# ---------------------------------------------------------------------------
# 3. Application code
# ---------------------------------------------------------------------------
log "Fetching application code into $INSTALL_PATH"
mkdir -p "$(dirname "$INSTALL_PATH")"
if [ -d "$INSTALL_PATH/.git" ]; then
	# Existing checkout — refresh in place. Honour file ownership to avoid
	# CVE-2022-24765 "dubious ownership" when run on top of a prior install.
	current_owner="$(stat -c '%U' "$INSTALL_PATH" 2>/dev/null || echo root)"
	if [ "$current_owner" != "root" ] && [ "$current_owner" != "$(id -un)" ]; then
		sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" fetch --depth 1 origin "$BRANCH"
		sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 ||
			sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
		sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
	else
		git -C "$INSTALL_PATH" fetch --depth 1 origin "$BRANCH"
		git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 ||
			git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
		git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
	fi
else
	mkdir -p "$INSTALL_PATH"
	git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_PATH"
fi

log "Installing PHP dependencies"
(cd "$INSTALL_PATH" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction)
# var/{plugins,themes,cache} are runtime-writable (plugin installs + theme
# extraction); create them up front so they exist for the unit's ReadWritePaths.
# The artwork cache root must likewise exist before start: a ReadWritePath that
# does not exist makes systemd fail the unit at 226/NAMESPACE.
mkdir -p "$INSTALL_PATH/.logs" "$INSTALL_PATH/templates_c" \
	"$INSTALL_PATH/var/plugins" "$INSTALL_PATH/var/themes" "$INSTALL_PATH/var/cache" \
	"$ARTWORK_DIR" "$SUBTITLE_DIR" "$ANIDB_CACHE_DIR"
chown -R "$SERVICE_USER:$SERVICE_USER" "$INSTALL_PATH"
chown -R "$SERVICE_USER:$SERVICE_USER" "$ARTWORK_DIR" 2>/dev/null || true
chown -R "$SERVICE_USER:$SERVICE_USER" "$SUBTITLE_DIR" 2>/dev/null || true
chown -R "$SERVICE_USER:$SERVICE_USER" "$ANIDB_CACHE_DIR" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 4. Database
# ---------------------------------------------------------------------------
log "Configuring MySQL database and user"
systemctl enable --now mysql >/dev/null 2>&1 || systemctl enable --now mysqld >/dev/null 2>&1 || true
# Runs as root via the local socket. Idempotent.
#
# Create grants for BOTH '${DB_HOST}' (the TCP target) and 'localhost'
# (what MySQL reports when reverse-DNS resolves 127.0.0.1 → localhost via
# /etc/hosts, which is the default Ubuntu behaviour). Without the
# 'localhost' grant, PDO connections fail with "Access denied for user
# 'phlix'@'localhost'" even though the configured host is 127.0.0.1.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
ALTER  USER             '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER  USER             '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Connection budget (R19-16): the fleet's documented pool math demands ~140
# connections at shipped defaults (14 HTTP workers × DB_POOL_SIZE 8, plus the
# count=1 pool workers, CLI and co-tenants) against MySQL 8's stock max_
# connections of 151 — zero headroom, surfacing as a "Too many connections"
# 500-storm exactly when the server is busiest. Where this script owns the
# server (same root-local-socket assumption as the grant block above), raise
# the ceiling now. SET PERSIST is MySQL 8: online, survives restart, and
# idempotent. Best-effort deliberately — an external DB host or a non-MySQL-8
# server (MariaDB has no PERSIST) must not fail the install; it gets a loud
# warning instead. See docker/examples/README.md "MySQL Connection Budget".
log "Raising MySQL max_connections for the documented pool budget"
if mysql -N -e "SET PERSIST max_connections = 300;" >/dev/null 2>&1; then
	info "max_connections raised to 300 (persisted, survives restart)"
else
	warn "Could not raise MySQL max_connections (external DB or non-MySQL-8 server?)."
	warn "  Verify the ceiling covers the pool budget (~140 at defaults, R19-16):"
	warn "  mysql -e \"SHOW VARIABLES LIKE 'max_connections'\""
fi

# ---------------------------------------------------------------------------
# 5. Environment file
# ---------------------------------------------------------------------------
log "Writing environment file $ENV_FILE"
cat >"$ENV_FILE" <<EOF
# Phlix Media Server environment — generated by install.sh on $(date -u +%FT%TZ)
# Loaded via the systemd unit's EnvironmentFile= directive.

# Database credentials. config/database.php reads DB_HOST / DB_PORT /
# DB_NAME (|| DB_DATABASE) / DB_USER (|| DB_USERNAME) / DB_PASSWORD — these are
# the keys that actually drive the connection, so a non-default --db-* install
# MUST write them (older installs wrote only the PHLIX_DATABASE_* aliases below,
# which the code does NOT read, and silently ran against the default DB).
# The PHLIX_DATABASE_* lines are recorded aliases the uninstaller reads to
# locate the database/user — keep them in sync with the DB_* values above.
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASS}
PHLIX_DATABASE_HOST=${DB_HOST}
PHLIX_DATABASE_PORT=${DB_PORT}
PHLIX_DATABASE_NAME=${DB_NAME}
PHLIX_DATABASE_USER=${DB_USER}

# Public hostname for hub-paired subdomains / DLNA / CORS.
PHLIX_DOMAIN=${PUBLIC_DOMAIN}

# Whether the server is served over TLS (set from --tls/--no-tls). Drives the
# scheme of the public URL advertised to the hub during pairing.
PHLIX_TLS_ENABLED=$([ "$TLS_ENABLED" = "yes" ] && echo 1 || echo 0)

# The port the daemon binds (rider R-SERVER-22). Pinned to HTTP_PORT above on
# purpose: HAProxy's backend and the installer healthcheck dial HTTP_PORT,
# while the Workerman listener binds whatever ListenEndpoints.php reads from
# this file (EnvironmentFile= in the unit). Without this row a --http-port
# install leaves the daemon on 8096 behind a proxy pointed at the custom port.
PHLIX_HTTP_PORT=${HTTP_PORT}

# JWT signing secret (REQUIRED). The server refuses to boot without a unique,
# non-default value: it signs auth JWTs and (HMAC-derived) media signed URLs,
# so a missing/guessable key makes both forgeable. Rotate with
# \`openssl rand -hex 32\` to invalidate all existing tokens.
JWT_SECRET=${JWT_SECRET}

# TRUSTED_PROXIES (SV-4.15): comma-separated reverse-proxy IPs/CIDRs whose
# X-Forwarded-For / X-Real-IP headers are TRUSTED when deriving the real client
# IP used to key auth rate-limiting. UNSET = loopback-only default
# (127.0.0.0/8,::1/128), which is CORRECT for the standard single-box install
# where nginx/HAProxy front Phlix over 127.0.0.1. Set it to your proxy's
# IP/CIDR ONLY if a NON-loopback reverse proxy / load-balancer fronts this
# server — otherwise every client is rate-limited under the proxy's single IP.
# Do NOT add broad RFC1918 ranges on a loopback-proxy box: that re-enables
# X-Forwarded-For spoofing by LAN clients.
$([ -n "$TRUSTED_PROXIES" ] && printf 'TRUSTED_PROXIES=%s\n' "$TRUSTED_PROXIES" ||
	printf '#TRUSTED_PROXIES=127.0.0.0/8,::1/128\n')

PHLIX_LOG_LEVEL=info
PHLIX_ENV=production

# Persistent, writable cache dir for the anidb plugin's offline title-dump
# index (title_index.json). Also a systemd ReadWritePath (below). The plugin
# reads this after an explicit ctor arg / cache_dir setting, before falling
# back to sys_get_temp_dir().
PHLIX_ANIDB_CACHE_DIR=${ANIDB_CACHE_DIR}

# Persistent home for the HLS segment cache (transcoded variants + segments;
# config/server.php hls.segment_dir). Unset, the default
# sys_get_temp_dir()/phlix_hls lives INSIDE the unit's PrivateTmp namespace and
# is wiped on every restart — each cold first-play then re-pays 3–6.6 s of
# inline segment transcode. Keep this line so the cache survives restarts; the
# 8 GiB / 3 h budget governs it identically wherever it lives. An unusable
# value (relative, non-writable, uncreatable) aborts boot with a clear message
# rather than silently degrading playback.
PHLIX_SEGMENT_DIR=${SEGMENT_DIR}

# --- Optional integrations (uncomment / fill in to enable) ---
$([ -n "$TMDB_API_KEY" ] && printf 'TMDB_API_KEY=%s\n' "$TMDB_API_KEY" || printf '#TMDB_API_KEY=\n')

# Hub relay — set PHLIX_RELAY_ENABLED=1 and PHLIX_HUB_URL once paired via
# scripts/pair-with-hub.php; see docs/install/linux.md.
$([ -n "$HUB_URL" ] && printf 'PHLIX_HUB_URL=%s\nPHLIX_RELAY_ENABLED=1\n' "$HUB_URL" ||
		printf '#PHLIX_HUB_URL=\n#PHLIX_RELAY_ENABLED=0\n')

# --- Optional tunables (commented; all have safe code defaults) ---
# Minimum free bytes on the HLS segment-cache filesystem before an on-demand
# encode is attempted (below this => fast 503 + Retry-After + a sweep, instead
# of FFmpeg hitting ENOSPC). Default 500 MiB (config/server.php).
#HLS_MIN_DISK_SPACE_BYTES=524288000
#
# Per-surface auth rate limits (SV-4.15): *_MAX = attempts, *_WINDOW = seconds.
# Absent => RateLimitProfiles::defaults(). Surfaces: register, refresh,
# webauthn_start, webauthn_finish, jwks, ws_connect.
#RATE_LIMIT_REGISTER_MAX=
#RATE_LIMIT_REGISTER_WINDOW=
#RATE_LIMIT_REFRESH_MAX=
#RATE_LIMIT_REFRESH_WINDOW=
#RATE_LIMIT_WEBAUTHN_START_MAX=
#RATE_LIMIT_WEBAUTHN_START_WINDOW=
#RATE_LIMIT_WEBAUTHN_FINISH_MAX=
#RATE_LIMIT_WEBAUTHN_FINISH_WINDOW=
#RATE_LIMIT_JWKS_MAX=
#RATE_LIMIT_JWKS_WINDOW=
#RATE_LIMIT_WS_CONNECT_MAX=
#RATE_LIMIT_WS_CONNECT_WINDOW=
EOF
chmod 640 "$ENV_FILE"
chown root:"$SERVICE_USER" "$ENV_FILE"

# ---------------------------------------------------------------------------
# 6. Database migrations
#
# ⚠ S159: run-migrations.php exits 1 on a genuine (non-idempotent) migration
# error, so `set -euo pipefail` aborts the install here. Deliberate and
# un-suppressed — a fresh install that cannot build its schema must not go on to
# install and start a systemd service against it.
# ---------------------------------------------------------------------------
log "Running database migrations"
DB_PASSWORD="$DB_PASS" \
	php "$INSTALL_PATH/scripts/run-migrations.php"

# ---------------------------------------------------------------------------
# 7. systemd service
# ---------------------------------------------------------------------------
log "Installing systemd service"
cat >"$SERVICE_FILE" <<EOF
[Unit]
Description=Phlix Media Server
Documentation=https://docs.phlix.media
After=network.target mysql.service
Wants=mysql.service
StartLimitIntervalSec=500
StartLimitBurst=5

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_USER}
WorkingDirectory=${INSTALL_PATH}
EnvironmentFile=${ENV_FILE}
Environment="PHLIX_ENV=production"
# Swoole's RemoteObject bridge (used when a coroutine runs a hooked stream op,
# e.g. the plugin catalog/install HTTPS fetch) writes a lock to \$HOME/.swoole.
# The user's real home (/home/${SERVICE_USER}) is hidden by ProtectHome=true, so
# point HOME at a ReadWritePath (${INSTALL_PATH}/var) — otherwise Swoole throws
# "failed to open lock file[…/.swoole/remote-object-server.lock]" and the Plugins
# page blanks out.
Environment="HOME=${INSTALL_PATH}/var"
# Workerman daemon — start.php is the root-level bootstrap (mirrors
# webman's pattern: thin entry → boot HTTP/WebSocket workers from
# config). public/ remains the document root for static assets.
ExecStart=/usr/bin/php ${INSTALL_PATH}/start.php start
# SIGUSR2 is Workerman's GRACEFUL reload: children drain in-flight requests
# before exiting. SIGUSR1 is the NON-graceful one (children stop immediately
# and a SIGKILL arms after stopTimeout) — a reload on it hard-cuts the :8097
# SyncPlay WS workers, in-flight HLS/transcode segment delivery and the hub
# relay tunnel. The admin restart endpoint (AdminRestartController) sends
# SIGUSR2; systemctl reload must not be the worse path of the two.
ExecReload=/bin/kill -SIGUSR2 \$MAINPID
ExecStop=/bin/kill -SIGTERM \$MAINPID
Restart=on-failure
RestartSec=5s
TimeoutStopSec=30
TimeoutStartSec=30

# Swoole 6's io_uring event loop pins locked memory; the default 8 MB
# RLIMIT_MEMLOCK is too small for the worker pool, so io_uring init fails
# with ENOMEM and workers intermittently drop connections (random 502s).
# Lift the cap so io_uring initialises cleanly.
LimitMEMLOCK=infinity

# File-descriptor ceiling for the master and every worker it forks. systemd
# does NOT read /etc/security/limits.conf, so this line — not the PAM drop-in
# scripts/install.sh also writes — is what the daemon actually gets. The
# systemd default is soft 1024 / hard 524288, and Workerman keeps one fd per
# live connection: an HTTP worker serving HLS holds a socket per client plus
# the open segment files, so a 1024 soft cap is reachable under load and
# surfaces as "Too many open files" with clients dropped mid-stream.
LimitNOFILE=1048576

StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${DATA_ROOT} ${LOG_DIR} ${RUN_DIR} ${INSTALL_PATH}/.logs ${INSTALL_PATH}/templates_c ${INSTALL_PATH}/var ${ARTWORK_DIR} ${ANIDB_CACHE_DIR}
RestrictNamespaces=true
LockPersonality=true
RemoveIPC=true
# Extra kernel/privilege protections (parity with phlix-hub). All safe for this
# service: it does software transcoding (ffmpeg shells out) and optional DVB/DLNA,
# none of which need to load kernel modules, change the clock/hostname, manage
# cgroups, create suid files, or use realtime scheduling. Deliberately NOT setting
# PrivateDevices (would hide /dev/dvb tuners + any /dev/dri), MemoryDenyWriteExecute
# (breaks PHP JIT/opcache), or SystemCallFilter (Swoole io_uring is syscall-sensitive
# — cf. the io_uring reboot incident).
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
ProtectHostname=true
ProtectClock=true
RestrictSUIDSGID=true
RestrictRealtime=true

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"
sleep 2
systemctl is-active --quiet "$SERVICE_NAME" && info "$SERVICE_NAME service is running." ||
	warn "$SERVICE_NAME service did not start — check 'journalctl -u $SERVICE_NAME'."

# ---------------------------------------------------------------------------
# 8. Reverse proxy (HAProxy) + TLS (certbot)
# ---------------------------------------------------------------------------
if [ "$SKIP_HAPROXY" = "yes" ]; then
	# R7-08: --no-proxy means THIS box answers on $HTTP_PORT in plain HTTP — JWTs
	# (including SyncPlay ?token= handshakes), credentials and signed media URLs
	# ride unencrypted, and the ufw block below opens that port to every network
	# it permits. Still a supported posture (your own TLS proxy in front, or a
	# trusted LAN) — but it must never happen silently. -y does NOT bypass this;
	# only the explicit ack flag or a typed "yes" does.
	if [ "$PLAINTEXT_ACK" != "yes" ]; then
		if [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
			printf '%sWARNING:%s --no-proxy leaves Phlix serving PLAIN-TEXT HTTP on port %s\n' "$C_YELLOW" "$C_RESET" "$HTTP_PORT" >&2
			printf '         to every network your firewall allows. TLS termination is\n' >&2
			printf '         the job of YOUR reverse proxy — the daemon serves plain HTTP.\n' >&2
			read -r __plaintext_ok -p "Really proceed with --no-proxy in plaintext? Type 'yes' to continue: " </dev/tty || true
			[ "${__plaintext_ok:-}" = "yes" ] || die "aborted: --no-proxy needs the typed 'yes' above, or --i-know-plaintext-internet-facing if you accept plaintext exposure."
		else
			die "--no-proxy refuses to run silently when non-interactive: it serves PLAIN-TEXT HTTP on port ${HTTP_PORT}. If that is intended, re-run with --no-proxy --i-know-plaintext-internet-facing (and put your own TLS proxy in front)."
		fi
	fi
	log "Skipping HAProxy / certbot setup (--no-proxy)"
	info "Run your own reverse proxy in front of 127.0.0.1:${HTTP_PORT} (HTTP/WebSocket)."
	info "See README.md → 'Running alongside phlix-hub' for a sample shared HAProxy config."

	# Best-effort: open the HTTP port directly on the firewall.
	if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
		ufw allow "$HTTP_PORT"/tcp comment 'Phlix Media Server HTTP' >/dev/null 2>&1 || true
		ufw allow "$DLNA_PORT"/udp comment 'Phlix DLNA discovery' >/dev/null 2>&1 || true
		info "Opened ports ${HTTP_PORT}/tcp and ${DLNA_PORT}/udp in ufw."
	fi
else
	mkdir -p /etc/haproxy/certs

	if [ "$TLS_ENABLED" = "yes" ]; then
		log "Obtaining TLS certificate for $DOMAIN via certbot"
		# ACME webroot dir for the tear-free renewal path (audit §3.4): the
		# managed fe_http's BASE leg answers /.well-known/acme-challenge/ from
		# here while haproxy keeps serving, so renewals never stop the proxy.
		mkdir -p /var/www/phlix-acme/.well-known/acme-challenge
		chmod 755 /var/www/phlix-acme /var/www/phlix-acme/.well-known /var/www/phlix-acme/.well-known/acme-challenge
		# standalone binds :80, which a RUNNING managed proxy owns; a cached
		# valid cert (`--keep-until-expiring`) never binds at all. Stop haproxy
		# ONLY when the first attempt fails (audit §3.4: an update run must not
		# tear every connection just to ask certbot a question whose answer is
		# "unchanged"). The stop path self-heals: the rebuild+reload sequence at
		# the end of this section brings the proxy back up on every success, and
		# the failure branch below starts it again before bailing out.
		phlix_certbot_standalone() {
			certbot certonly --standalone --non-interactive --agree-tos \
				-m "$ADMIN_EMAIL" -d "$DOMAIN" --keep-until-expiring
		}
		CERTBOT_OK="no"
		if phlix_certbot_standalone; then
			CERTBOT_OK="yes"
		else
			info "(certbot could not bind :80 — managed HAProxy owns it; stopping it for one retry)"
			systemctl stop haproxy >/dev/null 2>&1 || true
			if phlix_certbot_standalone; then CERTBOT_OK="yes"; fi
		fi
		if [ "$CERTBOT_OK" = "yes" ]; then
			# §3.1 shape decision, MEASURED here and baked into this run's
			# deploy-hook text; phlix_haproxy_rebuild re-measures independently
			# at every rebuild.
			if phlix_haproxy_supports_tls_key_file; then
				PHLIX_HAPROXY_TLS_SHAPE="live"
				info "HAProxy $(phlix_haproxy_installed_version) supports 'crt ... key-file ...': fe_https will read /etc/letsencrypt/live/${DOMAIN} directly (rotated bytes are adopted by the deploy-hook reload; no merge copy exists to go stale)."
				rm -f "/etc/haproxy/certs/${DOMAIN}.pem" 2>/dev/null || true
			else
				PHLIX_HAPROXY_TLS_SHAPE="merged"
				warn "HAProxy $(phlix_haproxy_installed_version) predates 2.9 — keeping the merged-copy shape. Rotations of ${DOMAIN} outside certbot on THIS host strand /etc/haproxy/certs/${DOMAIN}.pem with stale bytes (§3.1 class); renewals by this host's certbot stay covered by the deploy hook below."
				cat "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" \
					"/etc/letsencrypt/live/${DOMAIN}/privkey.pem" >"/etc/haproxy/certs/${DOMAIN}.pem"
				chmod 600 "/etc/haproxy/certs/${DOMAIN}.pem"
			fi

			# Deploy hook: adopt rotated material after each renewal — shape-split
			# (audit §3.1). certbot scans this directory automatically on every
			# renewal, so the cron line below carries no --deploy-hook arg: one
			# mechanism, not two. NOTE (honest server/hub divergence): no
			# private-key perms leg here — unlike the hub, the phlix-server daemon
			# holds NO inbound TLS listener (plain :8096/:8097; TLS termination is
			# the proxy's job — see README), so nothing this project runs ever
			# reads the key material. And nothing here points the outbound CA
			# anchors at these files: they are LEAF material, never trust anchors
			# (§5.2 — leaves rotate every ~60 days; pinning one reintroduces the
			# silent-expiry class the anchors' refusal texts guard).
			mkdir -p /etc/letsencrypt/renewal-hooks/deploy
			{
				if [ "$PHLIX_HAPROXY_TLS_SHAPE" = "live" ]; then
					cat <<'HOOK_HEAD'
#!/bin/sh
# Managed by the Phlix Media Server installer; rewritten on every install/update.
# Shape: LIVE (audit AUDIT-wildcard-cert-rotation.md §3.1, haproxy >= 2.9).
HOOK_HEAD
					cat <<HOOK_LIVE_TAIL
# fe_https binds read ${DOMAIN}'s live/ symlinks directly — there is nothing
# to re-merge. Remove any stale merge copy so a fallback rebuild can never
# serve it, then RELOAD (audit §3.4: reload is what adopts the rotated bytes
# at the stable path; the old stop/start machinery is gone).
rm -f "/etc/haproxy/certs/${DOMAIN}.pem" 2>/dev/null || true
systemctl reload haproxy 2>/dev/null || systemctl restart haproxy
HOOK_LIVE_TAIL
				else
					cat <<'HOOK_HEAD_M'
#!/bin/sh
# Managed by the Phlix Media Server installer; rewritten on every install/update.
# Shape: MERGED (legacy pre-2.9 haproxy — audit §3.1 best-effort path).
HOOK_HEAD_M
					cat <<HOOK_MERGED_TAIL
# fe_https reads the CAT-MERGED copy below, so every renewal on THIS host
# MUST re-merge it — a rotation outside this pipeline leaves the copy serving
# stale bytes (that is the §3.1 outage class; upgrade HAProxy into the
# support matrix, which ships >= 2.9, to retire this path).
cat "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" \
    "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" > "/etc/haproxy/certs/${DOMAIN}.pem"
chmod 600 "/etc/haproxy/certs/${DOMAIN}.pem"
systemctl reload haproxy 2>/dev/null || systemctl restart haproxy
HOOK_MERGED_TAIL
				fi
			} >/etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh
			chmod +x /etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh

			# Monthly auto-renewal (1st of the month, 03:00) — audit §3.4 cure:
			# the managed fe_http answers ACME challenges from the webroot while
			# HAProxy keeps running, so NO --pre-hook stop / --post-hook start
			# anymore: a renewal is a content swap + deploy-hook reload, never a
			# connection tear. The deploy hook is discovered via certbot's
			# automatic scan of /etc/letsencrypt/renewal-hooks/deploy/, so this
			# line carries no --deploy-hook arg. NOTE: `--webroot -w ...` on the
			# CLI overrides the recorded authenticator for EVERY certificate this
			# command renews on the host — by design, because the distro timer is
			# retired below and this cron line is the single scheduled renewal
			# path Phlix depends on.
			cat >/etc/cron.d/phlix-server-certbot <<CRON
# Phlix Media Server: monthly Let's Encrypt renewal (webroot + reload, no tear — §3.4)
0 3 1 * * root certbot renew --quiet --webroot -w /var/www/phlix-acme
CRON

			# Audit §3.5: the installer owns renewal on this host, so retire the
			# distro certbot.timer — two schedulers racing (with different hook
			# sets) was the silent-expiry trap. Idempotent; `|| true` keeps
			# minimal images without the unit happy.
			systemctl disable --now certbot.timer >/dev/null 2>&1 || true
			systemctl mask certbot.timer >/dev/null 2>&1 || true
			info "Disabled + masked the distro certbot.timer: the Phlix cron line and the global deploy hook are the single renewal mechanism."

			log "Writing phlix-server HAProxy fragment (TLS)"
			phlix_haproxy_write_fragment_server tls "$DOMAIN"
		else
			# Self-heal the §3.4 stop-retry path before the loud bail: a down
			# proxy must not be the residue of a failed cert run.
			systemctl start haproxy >/dev/null 2>&1 || true
			# R7-08: an operator who asked for TLS must not silently land on plain
			# HTTP. Fail loudly with the exact retry; re-running this installer is
			# safe, and the plaintext postures stay available deliberately (no --tls
			# with HAProxy, or --no-proxy with the explicit ack flag).
			warn "certbot failed for $DOMAIN — NOT falling back to plain HTTP silently."
			warn "Check that DNS for $DOMAIN points at this host and inbound port 80 is free for the ACME standalone challenge, then retry the certificate directly:"
			warn "  sudo certbot certonly --standalone --non-interactive --agree-tos -m $ADMIN_EMAIL -d $DOMAIN --keep-until-expiring"
			warn "or re-run this installer. To deliberately serve without TLS: re-run without --tls (HAProxy plain HTTP), or with --no-proxy --i-know-plaintext-internet-facing (your own proxy)."
			die "TLS requested but certificate issuance failed for $DOMAIN; no plain-HTTP fallback was configured."
		fi
	else
		log "Writing phlix-server HAProxy fragment (plain HTTP)"
		phlix_haproxy_write_fragment_server http "$DOMAIN"
	fi

	log "Rebuilding /etc/haproxy/haproxy.cfg from Phlix fragments"
	phlix_haproxy_rebuild
	phlix_haproxy_reload

	# Best-effort firewall openings.
	if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
		for p in 80 443; do ufw allow "$p"/tcp >/dev/null 2>&1 || true; done
		ufw allow "$DLNA_PORT"/udp comment 'Phlix DLNA discovery' >/dev/null 2>&1 || true
		info "Opened ports 80, 443, and $DLNA_PORT/udp in ufw."
	fi
fi # SKIP_HAPROXY guard

# ---------------------------------------------------------------------------
# 9. Done
# ---------------------------------------------------------------------------
echo
log "Phlix Media Server installation complete"
if [ "$SKIP_HAPROXY" = "yes" ]; then
	info "URL          : http://<server-ip>:${HTTP_PORT}/  (configure your own reverse proxy in front)"
	info "Health check : curl http://localhost:${HTTP_PORT}/health"
	warn "=================================================================="
	warn "TLS NOT CONFIGURED — this install serves PLAIN-TEXT HTTP on ${HTTP_PORT}."
	warn "Terminate TLS in your own reverse proxy (see reverse-proxy/ and"
	warn "deploy/haproxy.cfg) before exposing it beyond a trusted network."
	warn "=================================================================="
elif [ "$TLS_ENABLED" = "yes" ]; then
	info "URL          : https://${DOMAIN}/"
	info "Health check : curl https://${DOMAIN}/health"
else
	info "URL          : http://${PUBLIC_DOMAIN}/  (or http://<server-ip>/)"
	info "Health check : curl http://localhost:${HTTP_PORT}/health"
	[ -n "$DOMAIN" ] || info "Re-run with --domain and --admin-email to enable HTTPS."
	warn "=================================================================="
	warn "TLS NOT CONFIGURED — HAProxy is fronting PLAIN-TEXT HTTP."
	warn "Re-run with --tls --domain HOST --admin-email EMAIL to enable HTTPS."
	warn "=================================================================="
fi
info "Service       : systemctl status $SERVICE_NAME"
info "Env file      : ${ENV_FILE}  (DB_PASSWORD + JWT_SECRET stored here)"
# R7-09: never echo secret material into terminal scrollback / CI logs /
# screenshot captures — point at the 0640 env file instead.
info "Database pass : stored in ${ENV_FILE} (mode 640) — show with: sudo grep '^DB_PASSWORD=' ${ENV_FILE}"
echo
info "Next:"
info "  - Sign in at the URL. There is no setup wizard — create the first admin from the CLI:"
info "      php $INSTALL_PATH/bin/phlix user:create <name> --email=<email> --password-file=-"
# SH-032: --password-file=- reads the password from stdin, keeping the secret
# out of shell history and every local /proc/<pid>/cmdline reader (an
# explicit --password= on argv is visible to both until the process exits).
info "        (the password is read from stdin — never pass it as --password= on the command line)"
info "      php $INSTALL_PATH/bin/phlix user:promote <name>"
info "  - Pair with a Phlix Hub via 'php $INSTALL_PATH/scripts/pair-with-hub.php' if needed."
info "  - Drop a TMDB_API_KEY into $ENV_FILE for metadata enrichment."

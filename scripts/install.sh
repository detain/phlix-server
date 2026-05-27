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

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="phlix"
DB_USER="phlix"
DB_PASS=""              # generated if empty

HTTP_PORT="8096"        # config/server.php default
DLNA_PORT="1900"        # UDP multicast for DLNA discovery (optional)

DOMAIN=""               # public hostname; enables TLS when paired with --admin-email
ADMIN_EMAIL=""          # Let's Encrypt registration email

# Optional external service keys. Left blank by default; the operator can
# fill them in /etc/phlix/env later.
TMDB_API_KEY=""
HUB_URL=""              # PHLIX_HUB_URL — paired separately via scripts/pair-with-hub.php

WANT_TLS="auto"         # auto|yes|no
SKIP_HAPROXY="no"       # --no-proxy: skip HAProxy install + config entirely
INTERACTIVE="auto"      # auto|yes|no
ASSUME_YES="no"
ACTION="install"        # install|update|uninstall
PURGE="no"              # uninstall: also drop the DB and delete the Let's Encrypt cert

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
  C_BOLD=$'\033[1m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_RESET=$'\033[0m'
else
  C_BOLD=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_RESET=""
fi
log()  { printf '%s==>%s %s\n' "$C_GREEN$C_BOLD" "$C_RESET" "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '%s[error]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

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
  --tmdb-api-key KEY      TMDB API key (metadata)         (optional)
  --hub-url URL           PHLIX_HUB_URL for hub relay     (optional)
  --service-user USER     System user to run as           (default: phlix)
  --branch NAME           Git branch / tag to install     (default: master)
  --repo URL              Git repository URL              (default: detain/phlix-server)
  --tls                   Force TLS / HAProxy + certbot setup
  --no-tls                Skip TLS; HAProxy serves plain HTTP on :80
  --no-proxy              Skip HAProxy and certbot entirely (use when you
                          run your own reverse proxy or are co-hosting
                          phlix-hub on the same box — see README)
  --update                Update an existing install (reuses env file, pulls
                          new code, runs composer + migrations, restarts service)
  --uninstall             Remove an existing (possibly partial) install
                          (prompts before each destructive step)
  --purge                 With --uninstall, also DROP the database and DELETE
                          the Let's Encrypt certificate (data loss)
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

  # Update and switch to a different branch / tag
  sudo bash install.sh --update --branch v0.2.0 -y
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    --install-path) INSTALL_PATH="$2"; shift 2;;
    --domain)       DOMAIN="$2"; shift 2;;
    --admin-email)  ADMIN_EMAIL="$2"; shift 2;;
    --db-name)      DB_NAME="$2"; shift 2;;
    --db-user)      DB_USER="$2"; shift 2;;
    --db-pass)      DB_PASS="$2"; shift 2;;
    --db-host)      DB_HOST="$2"; shift 2;;
    --db-port)      DB_PORT="$2"; shift 2;;
    --http-port)    HTTP_PORT="$2"; shift 2;;
    --tmdb-api-key) TMDB_API_KEY="$2"; shift 2;;
    --hub-url)      HUB_URL="$2"; shift 2;;
    --service-user) SERVICE_USER="$2"; shift 2;;
    --branch)       BRANCH="$2"; shift 2;;
    --repo)         REPO_URL="$2"; shift 2;;
    --tls)          WANT_TLS="yes"; shift;;
    --no-tls)       WANT_TLS="no"; shift;;
    --no-proxy)     SKIP_HAPROXY="yes"; WANT_TLS="no"; shift;;
    --update)       ACTION="update"; shift;;
    --uninstall)    ACTION="uninstall"; shift;;
    --purge)        PURGE="yes"; ACTION="uninstall"; shift;;
    -y|--non-interactive|--yes) INTERACTIVE="no"; ASSUME_YES="yes"; shift;;
    --interactive)  INTERACTIVE="yes"; shift;;
    -h|--help)      usage; exit 0;;
    *) die "Unknown option: $1 (try --help)";;
  esac
done

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
  case "${__ans:-y}" in [Nn]*) return 1;; *) return 0;; esac
}

rand_hex() { openssl rand -hex "${1:-32}"; }
rand_pass() { openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24; }

# Parse the User= line from a systemd unit. Empty when missing.
phlix_systemd_unit_user() {
  [ -f "$1" ] || { printf ''; return; }
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
    phlix|phlix-hub|phlix-server) return 0 ;;
    *) return 1 ;;
  esac
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
    elif [ -f "$PHLIX_HAPROXY_CFG" ] \
         && grep -qE 'phlix-managed|^backend be_hub\b|^backend be_client_relay\b|^backend be_phlix_server\b' \
              "$PHLIX_HAPROXY_CFG" 2>/dev/null; then
      rm -f "$PHLIX_HAPROXY_CFG"
      info "Removed Phlix-managed HAProxy configuration (no pre-Phlix config to restore)."
    fi
    rmdir "$PHLIX_HAPROXY_MGR_DIR" 2>/dev/null || true
    return 0
  fi

  if [ -f "$PHLIX_HAPROXY_CFG" ] && [ ! -f "$PHLIX_HAPROXY_BAK" ] \
     && ! grep -qF "phlix-managed" "$PHLIX_HAPROXY_CFG" 2>/dev/null; then
    cp "$PHLIX_HAPROXY_CFG" "$PHLIX_HAPROXY_BAK"
  fi

  local emit_https="no"
  if [ -d "$PHLIX_HAPROXY_CERTS_DIR" ] \
     && [ -n "$(find "$PHLIX_HAPROXY_CERTS_DIR" -maxdepth 1 -name '*.pem' -print -quit 2>/dev/null)" ]; then
    emit_https="yes"
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
      cat <<'HTTPS_TOP'

frontend fe_https
    bind :443 ssl crt /etc/haproxy/certs/
    http-request set-header X-Forwarded-Proto https
HTTPS_TOP
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
  } > "$PHLIX_HAPROXY_CFG"
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
  } > "$fragment"
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
  if ! grep -qE '^backend be_phlix_server\b' /etc/haproxy/haproxy.cfg 2>/dev/null \
     && ! grep -qF "phlix-managed" /etc/haproxy/haproxy.cfg 2>/dev/null; then
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
    env_db_name="$(grep -m1 -E '^PHLIX_DATABASE_NAME='     "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
    env_db_user="$(grep -m1 -E '^PHLIX_DATABASE_USER='     "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
    env_db_host="$(grep -m1 -E '^PHLIX_DATABASE_HOST='     "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
    env_domain="$(grep   -m1 -E '^PHLIX_DOMAIN='           "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
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
  [ -f "$SERVICE_FILE" ]                && svc="$SERVICE_FILE"             && found=1
  [ -f "$ENV_FILE" ]                    && envf="$ENV_FILE"                && found=1
  [ -d "$(dirname "$ENV_FILE")" ]       && envdir="$(dirname "$ENV_FILE")"
  [ -d "$INSTALL_PATH" ]                && instdir="$INSTALL_PATH"         && found=1
  [ -d "$DATA_ROOT" ]                   && data_root="$DATA_ROOT"          && found=1
  [ -d "$LOG_DIR" ]                     && log_dir="$LOG_DIR"              && found=1
  [ -d "$RUN_DIR" ]                     && run_dir="$RUN_DIR"
  if [ -f "$PHLIX_HAPROXY_MGR_DIR/phlix-server.cfg.fragment" ]; then
    hap_fragment="$PHLIX_HAPROXY_MGR_DIR/phlix-server.cfg.fragment"; found=1
  fi
  # Note whether any *other* Phlix project fragments would remain.
  if [ -d "$PHLIX_HAPROXY_MGR_DIR" ] \
     && [ -n "$(find "$PHLIX_HAPROXY_MGR_DIR" -maxdepth 1 -name '*.cfg.fragment' \
                  ! -name 'phlix-server.cfg.fragment' -print -quit 2>/dev/null)" ]; then
    hap_other_fragments="yes"
  fi
  # Pre-Phlix backup (new shared name + legacy server-specific name).
  for b in "$PHLIX_HAPROXY_BAK" /etc/haproxy/haproxy.cfg.phlix-server.bak; do
    [ -f "$b" ] && hap_backup="$b" && break
  done
  if [ -n "$U_DOMAIN" ] && [ -f "/etc/haproxy/certs/${U_DOMAIN}.pem" ]; then
    hapcert="/etc/haproxy/certs/${U_DOMAIN}.pem"; found=1
  fi
  [ -f /etc/cron.d/phlix-server-certbot ] && cron="/etc/cron.d/phlix-server-certbot" && found=1
  [ -f /etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh ] \
      && hook="/etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh" && found=1
  if [ -n "$U_DOMAIN" ] && [ -d "/etc/letsencrypt/live/${U_DOMAIN}" ]; then
    le_dir="/etc/letsencrypt/live/${U_DOMAIN}"; found=1
  fi

  # Database lookup is best-effort: requires mysql client + running server.
  local has_db="no"
  if [ -n "$U_DB_NAME" ] && command -v mysql >/dev/null 2>&1; then
    if mysql -N -e "SHOW DATABASES LIKE '${U_DB_NAME}';" 2>/dev/null \
         | grep -qx "${U_DB_NAME}"; then
      has_db="yes"; found=1
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
  [ -n "$svc" ]                  && info " - systemd service      : $svc"
  [ -n "$envf" ]                 && info " - environment file     : $envf"
  [ -n "$instdir" ]              && info " - install directory    : $instdir"
  [ -n "$data_root" ]            && info " - data directory       : $data_root"
  [ -n "$log_dir" ]              && info " - log directory        : $log_dir"
  if [ "$svc_user_present" = "yes" ]; then
    if phlix_other_service_uses_user "$svc_user" /etc/systemd/system/phlix-hub.service; then
      info " - service user         : $svc_user (shared with phlix-hub — will NOT be removed)"
    elif ! phlix_user_is_dedicated "$svc_user"; then
      info " - service user         : $svc_user (shared OS account — will NOT be removed)"
    else
      info " - service user         : $svc_user (dedicated; removable with --purge or interactive confirm)"
    fi
  fi
  [ "$has_db" = "yes" ]          && info " - MySQL database       : ${U_DB_NAME} (user '${U_DB_USER}'@'${U_DB_HOST}')"
  if [ -n "$hap_fragment" ]; then
    if [ "$hap_other_fragments" = "yes" ]; then
      info " - HAProxy fragment     : $hap_fragment (will be removed; haproxy.cfg rebuilt for remaining Phlix projects)"
    elif [ -n "$hap_backup" ]; then
      info " - HAProxy fragment     : $hap_fragment (last Phlix fragment; pre-Phlix config restored from $hap_backup)"
    else
      info " - HAProxy fragment     : $hap_fragment (last Phlix fragment; haproxy.cfg removed entirely)"
    fi
  fi
  [ -n "$hapcert" ]              && info " - HAProxy TLS cert     : $hapcert"
  [ -n "$hook" ]                 && info " - Certbot deploy hook  : $hook"
  [ -n "$cron" ]                 && info " - Certbot renewal cron : $cron"
  [ -n "$le_dir" ]               && info " - Let's Encrypt cert   : $le_dir"
  echo

  # Destructive opt-ins (DB drop, cert delete). --purge says yes to both;
  # otherwise we only ask interactively. -y alone keeps the data.
  local drop_db="no"
  if [ "$has_db" = "yes" ]; then
    if [ "$PURGE" = "yes" ]; then
      drop_db="yes"
    elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Drop MySQL database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'? (DATA LOSS)" \
        && drop_db="yes"
    fi
  fi

  local drop_data="no"
  if [ -n "$data_root" ]; then
    if [ "$PURGE" = "yes" ]; then
      drop_data="yes"
    elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Delete data directory '$data_root' (config, library cache, backups)? (DATA LOSS)" \
        && drop_data="yes"
    fi
  fi

  local revoke_cert="no"
  if [ -n "$le_dir" ]; then
    if [ "$PURGE" = "yes" ]; then
      revoke_cert="yes"
    elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Delete Let's Encrypt certificate for '${U_DOMAIN}' via 'certbot delete'?" \
        && revoke_cert="yes"
    fi
  fi

  # Opt-in to system-user removal. Only offered when the user is dedicated
  # (phlix / phlix-hub / phlix-server) AND the other Phlix project isn't
  # also using it.
  local drop_user="no"
  if [ "$svc_user_present" = "yes" ] \
     && phlix_user_is_dedicated "$svc_user" \
     && ! phlix_other_service_uses_user "$svc_user" /etc/systemd/system/phlix-hub.service; then
    if [ "$PURGE" = "yes" ]; then
      drop_user="yes"
    elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Delete the dedicated system user '$svc_user'?" \
        && drop_user="yes"
    fi
  fi

  [ "$has_db" = "yes" ] && { [ "$drop_db"     = "yes" ] && info "Will DROP database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'." \
                                                       || info "Will KEEP MySQL database and user."; }
  [ -n "$data_root" ]   && { [ "$drop_data"   = "yes" ] && info "Will DELETE data directory '$data_root'." \
                                                       || info "Will KEEP data directory '$data_root'."; }
  [ -n "$le_dir" ]      && { [ "$revoke_cert" = "yes" ] && info "Will DELETE Let's Encrypt certificate '${U_DOMAIN}'." \
                                                       || info "Will KEEP Let's Encrypt certificate '${U_DOMAIN}'."; }
  if [ "$svc_user_present" = "yes" ] && phlix_user_is_dedicated "$svc_user" \
     && ! phlix_other_service_uses_user "$svc_user" /etc/systemd/system/phlix-hub.service; then
    [ "$drop_user" = "yes" ] && info "Will DELETE system user '$svc_user'." \
                             || info "Will KEEP system user '$svc_user'."
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
    systemctl stop "$SERVICE_NAME"      >/dev/null 2>&1 || true
    systemctl disable "$SERVICE_NAME"   >/dev/null 2>&1 || true
    rm -f "$svc"
    systemctl daemon-reload             >/dev/null 2>&1 || true
  fi

  # 2. HAProxy fragment + rebuild. Removing this project's fragment and
  # rebuilding will either drop the phlix-server frontend/backend (other
  # Phlix projects remain) or restore the pre-Phlix config / delete the
  # file outright (we were the last fragment).
  if [ -n "$hap_fragment" ]; then
    log "Removing HAProxy fragment and rebuilding shared config"
    rm -f "$hap_fragment"
    [ -n "$hapcert" ] && { log "Removing HAProxy TLS certificate"; rm -f "$hapcert"; }
    phlix_haproxy_rebuild
    phlix_haproxy_reload
  else
    [ -n "$hapcert" ] && { log "Removing HAProxy TLS certificate"; rm -f "$hapcert"; }
  fi

  # 5. Certbot artefacts
  [ -n "$cron" ] && { log "Removing certbot cron entry"; rm -f "$cron"; }
  [ -n "$hook" ] && { log "Removing certbot deploy hook"; rm -f "$hook"; }
  if [ "$revoke_cert" = "yes" ] && command -v certbot >/dev/null 2>&1; then
    log "Deleting Let's Encrypt certificate for ${U_DOMAIN}"
    certbot delete --non-interactive --cert-name "${U_DOMAIN}" >/dev/null 2>&1 \
      || warn "certbot delete failed — remove /etc/letsencrypt/live/${U_DOMAIN}/ manually if needed."
  fi

  # 6. Database
  if [ "$drop_db" = "yes" ]; then
    log "Dropping MySQL database and user"
    if mysql <<SQL >/dev/null 2>&1
DROP DATABASE IF EXISTS \`${U_DB_NAME}\`;
DROP USER IF EXISTS '${U_DB_USER}'@'${U_DB_HOST}';
FLUSH PRIVILEGES;
SQL
    then
      info "Dropped database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'."
    else
      warn "MySQL cleanup failed — drop the database and user manually if needed."
    fi
  fi

  # 7. Install directory (sanity-check the path before rm -rf).
  if [ -n "$instdir" ]; then
    case "$instdir" in
      ""|/|/bin|/boot|/dev|/etc|/home|/lib*|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var|/var/www)
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
      ""|/|/bin|/boot|/dev|/etc|/home|/lib*|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var)
        warn "Refusing to remove suspicious data path: $data_root"
        ;;
      *)
        log "Removing data directory $data_root"
        rm -rf "$data_root"
        ;;
    esac
  fi
  [ -n "$log_dir" ] && { log "Removing log directory $log_dir"; rm -rf "$log_dir"; }
  [ -n "$run_dir" ] && rm -rf "$run_dir"

  # 9. Env file (before user removal — chown depends on user existing).
  [ -n "$envf" ] && { log "Removing environment file $envf"; rm -f "$envf"; }
  # Remove /etc/phlix/ if it's now empty.
  if [ -n "$envdir" ] && [ -d "$envdir" ]; then
    rmdir "$envdir" 2>/dev/null || true
  fi

  # 10. System user (after every owned artefact is gone).
  if [ "$drop_user" = "yes" ]; then
    log "Removing system user '$svc_user'"
    userdel "$svc_user" >/dev/null 2>&1 \
      || warn "userdel '$svc_user' failed — remove it manually with 'sudo userdel $svc_user'."
  fi

  echo
  log "Phlix Media Server uninstallation complete."
  info "System packages (PHP, MySQL, HAProxy, certbot, ffmpeg) were left installed."
  info "Remove them with 'sudo apt remove ...' if you no longer need them."
  [ "$has_db" = "yes" ]            && [ "$drop_db"     != "yes" ] \
    && info "MySQL database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}' were preserved."
  [ -n "$data_root" ]              && [ "$drop_data"   != "yes" ] \
    && info "Data directory '$data_root' was preserved."
  [ -n "$le_dir" ]                 && [ "$revoke_cert" != "yes" ] \
    && info "Let's Encrypt certificate '${U_DOMAIN}' was preserved at $le_dir."
  [ "$svc_user_present" = "yes" ]  && [ "$drop_user"   != "yes" ] && phlix_user_is_dedicated "$svc_user" \
    && info "System user '$svc_user' was preserved."
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
  [ -f "$ENV_FILE" ]          || die "No env file at $ENV_FILE — run a fresh install first."
  [ -d "$INSTALL_PATH" ]      || die "Install path '$INSTALL_PATH' not found — run a fresh install first."
  [ -d "$INSTALL_PATH/.git" ] || die "Install path '$INSTALL_PATH' is not a git checkout — cannot fast-forward updates."

  # Read existing env so we can run migrations with the right credentials.
  local env_db_pass env_db_name
  env_db_pass="$(grep -m1 -E '^DB_PASSWORD='     "$ENV_FILE" | cut -d= -f2- || true)"
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
    command -v sudo >/dev/null 2>&1 \
      || die "Install dir owned by '$repo_owner' but sudo is not available."
    as_owner=(sudo -H -u "$repo_owner" --)
  fi

  local prev_commit current_branch
  prev_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  current_branch="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

  if [ -n "$("${as_owner[@]}" git -C "$INSTALL_PATH" status --porcelain 2>/dev/null)" ]; then
    warn "Uncommitted local changes in $INSTALL_PATH will be discarded by 'git reset --hard'."
  fi

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
  "${as_owner[@]}" git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 \
    || "${as_owner[@]}" git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
  "${as_owner[@]}" git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
  local new_commit
  new_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  info "Code: $prev_commit -> $new_commit"

  # 2. Refresh PHP deps (composer.lock-driven, no surprise upgrades).
  log "Updating PHP dependencies"
  ( cd "$INSTALL_PATH" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction )

  # 3. Clear Smarty compile cache so templates pick up changes immediately.
  if [ -d "$INSTALL_PATH/templates_c" ]; then
    log "Clearing Smarty compile cache"
    find "$INSTALL_PATH/templates_c" -mindepth 1 -delete 2>/dev/null || true
  fi

  mkdir -p "$INSTALL_PATH/.logs" "$LOG_DIR" "$RUN_DIR"
  # Restore ownership the install was running with.
  if [ "$repo_owner" != "root" ] && id -u "$repo_owner" >/dev/null 2>&1; then
    chown -R "$repo_owner:$repo_owner" "$INSTALL_PATH"
    chown -R "$repo_owner:$repo_owner" "$LOG_DIR" "$RUN_DIR" 2>/dev/null || true
  fi

  # 4. Apply migrations. run-migrations.php currently has no tracking table
  # (statements use `IF NOT EXISTS` and the runner swallows duplicate-key
  # warnings), so re-running on each update is safe.
  log "Running migrations"
  DB_PASSWORD="$env_db_pass" \
    php "$INSTALL_PATH/scripts/run-migrations.php"

  # 4b. One-off migration: if this install pre-dates the fragment-based
  # HAProxy layout, convert it now. Idempotent on subsequent updates.
  phlix_haproxy_migrate_if_needed_server

  # 5. Restart the service. We don't touch the env file or the systemd unit.
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
  [ "$prev_commit" = "$new_commit" ] && info "(already up to date)"
}

if [ "$ACTION" = "update" ]; then
  do_update
  exit 0
fi

# ---------------------------------------------------------------------------
# Gather configuration
# ---------------------------------------------------------------------------
log "Phlix Media Server installer"
[ "$INTERACTIVE" = "yes" ] && info "Interactive mode — press Enter to accept each default." \
                           || info "Non-interactive mode — using defaults/flags."

prompt INSTALL_PATH "Install path" "$INSTALL_PATH"
prompt DB_NAME      "Database name" "$DB_NAME"
prompt DB_USER      "Database user" "$DB_USER"
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

# Public domain for the env file.
PUBLIC_DOMAIN="${DOMAIN:-$(hostname -f 2>/dev/null || hostname)}"

# Generate a secret key for HMAC-signed cookies / future use. Read by the
# code via getenv('PHLIX_SECRET_KEY') where applicable.
SECRET_KEY="$(rand_hex 32)"

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
apt-get install -y \
  php-cli php-mysql php-mbstring php-curl php-xml php-bcmath php-gd php-zip \
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
  8.3*|8.4*|8.5*|9.*) info "PHP $PHP_VER OK";;
  *) warn "PHP $PHP_VER detected — Phlix Media Server requires 8.3+. Install may not run correctly.";;
esac

# Composer
if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null
  rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------------------
# 2. Service user + directories
# ---------------------------------------------------------------------------
log "Ensuring system user '$SERVICE_USER' exists"
if ! id -u "$SERVICE_USER" >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
  info "Created user '$SERVICE_USER'."
fi

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
    sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 \
      || sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
    sudo -H -u "$current_owner" -- git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
  else
    git -C "$INSTALL_PATH" fetch --depth 1 origin "$BRANCH"
    git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 \
      || git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
    git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
  fi
else
  mkdir -p "$INSTALL_PATH"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_PATH"
fi

log "Installing PHP dependencies"
( cd "$INSTALL_PATH" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction )
mkdir -p "$INSTALL_PATH/.logs" "$INSTALL_PATH/templates_c"
chown -R "$SERVICE_USER:$SERVICE_USER" "$INSTALL_PATH"

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
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER  USER             '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
# 5. Environment file
# ---------------------------------------------------------------------------
log "Writing environment file $ENV_FILE"
cat > "$ENV_FILE" <<EOF
# Phlix Media Server environment — generated by install.sh on $(date -u +%FT%TZ)
# Loaded via the systemd unit's EnvironmentFile= directive.

# Database credentials.
# Only DB_PASSWORD is currently read by config/database.php; the PHLIX_DATABASE_*
# entries are recorded here for the uninstaller and for future use.
DB_PASSWORD=${DB_PASS}
PHLIX_DATABASE_HOST=${DB_HOST}
PHLIX_DATABASE_PORT=${DB_PORT}
PHLIX_DATABASE_NAME=${DB_NAME}
PHLIX_DATABASE_USER=${DB_USER}

# Public hostname for hub-paired subdomains / DLNA / CORS.
PHLIX_DOMAIN=${PUBLIC_DOMAIN}

# 32-byte hex secret for HMAC-signed cookies / future use.
PHLIX_SECRET_KEY=${SECRET_KEY}

PHLIX_LOG_LEVEL=info
PHLIX_ENV=production

# --- Optional integrations (uncomment / fill in to enable) ---
$( [ -n "$TMDB_API_KEY" ] && printf 'TMDB_API_KEY=%s\n' "$TMDB_API_KEY" || printf '#TMDB_API_KEY=\n' )

# Hub relay — set PHLIX_RELAY_ENABLED=1 and PHLIX_HUB_URL once paired via
# scripts/pair-with-hub.php; see docs/install/linux.md.
$( [ -n "$HUB_URL" ] && printf 'PHLIX_HUB_URL=%s\nPHLIX_RELAY_ENABLED=1\n' "$HUB_URL" \
                    || printf '#PHLIX_HUB_URL=\n#PHLIX_RELAY_ENABLED=0\n' )
EOF
chmod 640 "$ENV_FILE"
chown root:"$SERVICE_USER" "$ENV_FILE"

# ---------------------------------------------------------------------------
# 6. Database migrations
# ---------------------------------------------------------------------------
log "Running database migrations"
DB_PASSWORD="$DB_PASS" \
  php "$INSTALL_PATH/scripts/run-migrations.php"

# ---------------------------------------------------------------------------
# 7. systemd service
# ---------------------------------------------------------------------------
log "Installing systemd service"
cat > "$SERVICE_FILE" <<EOF
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
ExecStart=/usr/bin/php ${INSTALL_PATH}/public/index.php start
ExecReload=/bin/kill -SIGUSR1 \$MAINPID
ExecStop=/bin/kill -SIGTERM \$MAINPID
Restart=on-failure
RestartSec=5s
TimeoutStopSec=30
TimeoutStartSec=30

StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${DATA_ROOT} ${LOG_DIR} ${RUN_DIR} ${INSTALL_PATH}/.logs ${INSTALL_PATH}/templates_c
RestrictNamespaces=true
LockPersonality=true
RemoveIPC=true

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"
sleep 2
systemctl is-active --quiet "$SERVICE_NAME" && info "$SERVICE_NAME service is running." \
                                            || warn "$SERVICE_NAME service did not start — check 'journalctl -u $SERVICE_NAME'."

# ---------------------------------------------------------------------------
# 8. Reverse proxy (HAProxy) + TLS (certbot)
# ---------------------------------------------------------------------------
if [ "$SKIP_HAPROXY" = "yes" ]; then
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
  systemctl stop haproxy >/dev/null 2>&1 || true
  if certbot certonly --standalone --non-interactive --agree-tos \
        -m "$ADMIN_EMAIL" -d "$DOMAIN" --keep-until-expiring; then
    cat "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" \
        "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" > "/etc/haproxy/certs/${DOMAIN}.pem"
    chmod 600 "/etc/haproxy/certs/${DOMAIN}.pem"

    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh <<HOOK
#!/bin/sh
cat "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" \\
    "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" > "/etc/haproxy/certs/${DOMAIN}.pem"
chmod 600 "/etc/haproxy/certs/${DOMAIN}.pem"
systemctl reload haproxy 2>/dev/null || systemctl restart haproxy
HOOK
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh

    cat > /etc/cron.d/phlix-server-certbot <<CRON
# Phlix Media Server: monthly Let's Encrypt renewal
0 3 1 * * root certbot renew --quiet --pre-hook "systemctl stop haproxy" --post-hook "systemctl start haproxy" --deploy-hook /etc/letsencrypt/renewal-hooks/deploy/phlix-server-haproxy.sh
CRON

    log "Writing phlix-server HAProxy fragment (TLS)"
    phlix_haproxy_write_fragment_server tls "$DOMAIN"
  else
    warn "certbot failed (is DNS for $DOMAIN pointed here and port 80 reachable?). Falling back to plain HTTP."
    TLS_ENABLED="no"
    log "Writing phlix-server HAProxy fragment (plain HTTP)"
    phlix_haproxy_write_fragment_server http "$DOMAIN"
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
fi  # SKIP_HAPROXY guard

# ---------------------------------------------------------------------------
# 9. Done
# ---------------------------------------------------------------------------
echo
log "Phlix Media Server installation complete"
if [ "$SKIP_HAPROXY" = "yes" ]; then
  info "URL          : http://<server-ip>:${HTTP_PORT}/  (configure your own reverse proxy in front)"
  info "Health check : curl http://localhost:${HTTP_PORT}/health"
elif [ "$TLS_ENABLED" = "yes" ]; then
  info "URL          : https://${DOMAIN}/"
  info "Health check : curl https://${DOMAIN}/health"
else
  info "URL          : http://${PUBLIC_DOMAIN}/  (or http://<server-ip>/)"
  info "Health check : curl http://localhost:${HTTP_PORT}/health"
  [ -n "$DOMAIN" ] || info "Re-run with --domain and --admin-email to enable HTTPS."
fi
info "Service       : systemctl status $SERVICE_NAME"
info "Env file      : ${ENV_FILE}  (DB_PASSWORD + PHLIX_SECRET_KEY stored here)"
info "Database pass : ${DB_PASS}"
echo
info "Next:"
info "  - Open the URL and complete the first-run setup wizard."
info "  - Pair with a Phlix Hub via 'php $INSTALL_PATH/scripts/pair-with-hub.php' if needed."
info "  - Drop a TMDB_API_KEY into $ENV_FILE for metadata enrichment."

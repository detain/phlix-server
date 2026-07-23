# Phlix Media Server

[![PHPUnit](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml)
[![Web UI](https://github.com/detain/phlix-server/actions/workflows/web-ui.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/web-ui.yml)
[![codecov](https://codecov.io/gh/detain/phlix-server/graph/badge.svg)](https://codecov.io/gh/detain/phlix-server)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![Code style](https://img.shields.io/badge/code%20style-PSR--12-blueviolet)](https://www.php-fig.org/psr/psr-12/)

A comprehensive media server platform built with PHP 8.3+, featuring real-time WebSocket communication, HTTP REST APIs, and support for multiple client platforms including Roku, Samsung Tizen, and Windows.

> **Repository moved 2026-05-17:** this codebase migrated from `github.com/detain/phlix` to [`github.com/detain/phlix-server`](https://github.com/detain/phlix-server) as part of the Phase B repo split (see `PHLIX_EXPANSION_PLAN.md`). Update existing local clones with `git remote set-url origin git@github.com:detain/phlix-server.git`. The old repo is being archived in step B.4b.

## Overview

Phlix Media Server provides a complete media management and streaming solution:

- **Media Library Management**: Organize and browse media collections with automatic scanning
- **User Authentication**: JWT-based auth with refresh tokens
- **Real-time SyncPlay**: Watch content together with friends
- **Live TV Support**: DVR and guide integration
- **DLNA Streaming**: Standard protocol support for compatible devices
- **Transcoding**: On-the-fly media conversion via FFmpeg with automatic quality selection
- **HLS Streaming**: Adaptive bitrate streaming for web clients with multi-quality playlists. Transcoded
  output is normalized for browser playback: 8-bit H.264 video, **stereo AAC audio** (surround layouts like
  AC-3 5.1(side) are downmixed — a `channel_configuration=0` PCE otherwise breaks hls.js), and even scale
  widths (`force_divisible_by=2`)
- **WebSocket Events**: Real-time progress and notification delivery
- **Multi-Source Metadata**: Automatic metadata fetching from TMDB (movies), TVDB (TV series), Fanart.tv (artwork), and local NFO files with 24-hour cache and provider fallback
- **Content Filtering**: Parental controls with rating and genre-based filtering

## Architecture

```
src/
├── Server/
│   ├── Core/           # Application bootstrap and core
│   ├── Http/            # HTTP REST API layer
│   │   ├── Controllers/ # Request handlers
│   │   ├── Request.php  # HTTP request representation
│   │   ├── Response.php # HTTP response builder
│   │   └── Router.php  # Route dispatching
│   ├── WebSocket/       # Real-time communication
│   │   ├── Connection.php      # Client connection wrapper
│   │   ├── ConnectionPool.php  # Connection management
│   │   ├── MessageHandler.php  # Event routing
│   │   ├── WebSocketServer.php # Server implementation
│   │   └── Events.php          # Event type constants
│   └── WebPortal/       # Web portal
│       ├── WebPortalRouter.php # REST API for the /app Vue SPA (JSON only)
│       └── Controllers/SharedUiController.php # serves the /app SPA shell (+ ViteAssets.php)
├── Session/            # Playback session management
├── Media/              # Media library and metadata
│   ├── Library/        # Library management (LibraryManager, ItemRepository, MediaScanner)
│   ├── Metadata/      # Metadata fetching (TMDB, TVDB, Fanart, NFO providers)
│   ├── Transcoding/    # FFmpeg transcoding (FfmpegRunner, TranscodeManager, EncodeSettings)
│   └── Streaming/      # HLS streaming with adaptive bitrate
├── Auth/               # Authentication services
└── Common/             # Shared utilities

public/
├── index.php           # Web portal entry point (redirects legacy pages to /app)
├── templates/emails/   # Smarty newsletter email template (only remaining .tpl)
└── assets/             # Static assets (css, js) + assets/app/ (built Vue SPA bundle)
```

## Requirements

- **PHP**: 8.3 or higher
- **MySQL**: 8.0+ or MariaDB 10.6+
- **Workerman**: 5.0+ (bundled via Composer)
- **FFmpeg**: For transcoding (optional)

## Features

### Foundation
- **PSR-11 DI container (PHP-DI 7)**: auto-wired services with provider-based
  composition; see [phlix-docs / dev / architecture-server](https://detain.github.io/phlix-docs/dev/architecture-server).
- **PSR-14 event dispatcher (Tukio)**: playback, library-scan, and auth
  lifecycle events with typed `readonly` DTOs. Plugins subscribe by event
  class FQCN; see [phlix-docs / dev / event-reference](https://detain.github.io/phlix-docs/dev/event-reference).
- **Plugin system**: install / enable / disable / uninstall lifecycle,
  sandboxed per-plugin `vendor/` directories, signature-checked
  manifests, and PSR-14 event subscription via
  `Phlix\Shared\Plugin\LifecycleInterface` (the
  `Phlix\Plugins\Contract\LifecycleInterface` FQCN remains a
  deprecated bridge through 0.11.x). **Plugin developer guide:**
  [phlix-docs / plugins / developer-guide](https://detain.github.io/phlix-docs/plugins/developer-guide).
  Server-internals reference for contributors extending the loader:
  [phlix-docs / dev / plugin-sdk](https://detain.github.io/phlix-docs/dev/plugin-sdk). Reference
  plugin: [`detain/phlix-plugin-example`](https://github.com/detain/phlix-plugin-example).
- **Shared interfaces / DTOs in `detain/phlix-shared`**: framework-neutral
  Composer package shared with `phlix-hub`. `Phlix\Shared\Plugin\*`,
  `Phlix\Shared\Events\*`, `Phlix\Shared\Auth\JwtClaims`,
  `Phlix\Shared\Metadata\MetadataSourceInterface` (the typed metadata-source
  plugin contract, since `phlix-shared` 0.15.0), and
  `Phlix\Shared\Hub\*` DTOs live there since `phlix-server` 0.11.0.
- **Library matching & de-duplication**: filename→title cleaning strips
  multi-word noise suffixes (`Directors Cut`, `YIFY`, …) before metadata
  matching (admin-tunable via `matching.noise_suffixes`); a canonical-key
  resolver prevents duplicate top-level series/movies at scan time; and the
  admin **Duplicates** page + `scripts/dedup-series.php` merge historical
  duplicates. Metadata source order is configurable per media type via the
  `metadata.provider_priority` setting. See
  [phlix-docs / admin / library-management](https://detain.github.io/phlix-docs/admin/library-management)
  and [phlix-docs / admin / server-settings](https://detain.github.io/phlix-docs/admin/server-settings).

### Web Portal
- **Vue SPA (`/app`) — the ONLY web UI**: The web UI is the shared `@phlix/ui` Vue SPA, served at `/app`
  (SPA shell via `SharedUiController` + `ViteAssets`; built bundle under `public/assets/app/`). Routes +
  nav are registered in `web-ui/src/main.ts`, covering **Browse/Library**, **Player**, **Music** sub-pages
  (`/app/music/*`), **Books** (`/app/books/*`), **Audiobooks** (`/app/audiobooks/*`), **Photos**
  (`/app/photo/*`), **Search** (`/app/search`), **Settings** (incl. the **Security**/passkey tab at
  `/app/settings/security`), and **Admin** (`/app/admin/*`).
- **Legacy Smarty page rendering removed**: The old server-rendered (Smarty) portal pages —
  `PageRenderer`, the page controllers, and all `public/templates/**/*.tpl` page templates — have been
  **deleted**. Their old paths now **302-redirect** to the `/app` equivalent (e.g. `/login` →
  `/app/login`, `/library` → `/app`, `/player/{id}` → `/app/player/{id}`, `/music|/books|/audiobooks|/photo`
  → their `/app` pages), dispatched in `public/index.php`. Smarty (`smarty/smarty`) is retained on the
  server **only** for the newsletter email (`src/Admin/NewsletterGenerator.php` +
  `public/templates/emails/newsletter.tpl`).
- **REST API Endpoints**: Complete API for library browsing, media info, and user data
- **JWT Authentication**: Integrated token-based auth with refresh support
- **Responsive Design**: CSS-first approach with utility classes
- **JavaScript Client**: ApiClient helper with auth, library, and player helpers
- **Continue Watching**: Track and display in-progress media
- **Library Browser**: Browse media by library with item counts

### Authentication & Security
- **JWT-based Authentication**: Stateless auth with access tokens (1 hour TTL) and refresh tokens (7 days TTL)
- **Secure Password Hashing**: Argon2ID for password storage
- **Multi-Device Sessions**: Track and manage sessions across devices
- **User Profiles**: Multiple profiles per account with parental controls
  - Up to 5 profiles per user account
  - Profile-specific content rating restrictions (G, PG, PG-13, R, NC-17, X, UNRATED)
  - PIN protection (4 or 6 digits) for profile settings
  - Genre-based filtering (allowed/blocked genre lists)
  - Daily watch time limits per profile
- **Content Rating Filters**: Age-based access restrictions
- **Audit Logging**: Complete security event logging
- **Security Headers / CSP**: Every response carries `X-Content-Type-Options`, `X-Frame-Options`, HSTS,
  and a strict Content-Security-Policy (`SecurityHeaders`). The SPA CSP allows `media-src`/`worker-src
  'self' blob:` so hls.js can attach its MSE `blob:` object URL and transmux Web Worker (required for
  browser HLS playback), and the `/app` shell's inline bootstrap `<script>` runs under a **per-request
  script nonce** rather than `'unsafe-inline'`; non-`/app` responses keep the strict default policy.
  `img-src` additionally allowlists the two TMDB image CDN hosts (`https://image.tmdb.org` and
  `https://tmdb.org`, no wildcard) so poster/backdrop/cast artwork served directly from TMDB renders
  rather than being blocked. This is a **stopgap** until the generic image caching/loader work
  (updates.md #47 / S71-S73) proxies all remote artwork through our own origin, at which point the
  explicit TMDB hosts are removed.

### SyncPlay - Group Watching
- **Synchronized Playback**: Watch content together with friends across devices with sub-second sync accuracy
- **Host-Controlled Playback**: Only the host can control play/pause/seek; all members receive synchronized commands
- **NTP-Style Time Sync**: Network time synchronization with latency compensation and drift correction
- **In-Group Chat**: Real-time messaging with typing indicators and message history
- **Playback Queue**: Host-managed queue with media info (title, thumbnail)
- **Host Election**: Automatic host election when current host leaves (oldest member becomes host)
- **Password Protection**: Optional password protection for private watch parties
- **Position Tolerance**: Configurable sync tolerance (default 2s) to prevent excessive seeking

### Session Management
- **Device Sessions**: Track authenticated devices with activity timestamps
- **Playback Progress**: Resume where you left off across sessions
- **Continue Watching**: Track items in progress per profile
- **Watch History**: Complete viewing history per profile with:
  - Automatic completion detection at 90% progress threshold
  - Watch time statistics (total, daily, by period)
  - Resume position tracking for seamless playback continuation

### Live TV & DVR
- **Multi-Tuner Support**: DVB-T, DVB-S, DVB-C, and ATSC tuner types
- **Channel Scanning**: Automatic discovery of broadcast services
- **Electronic Program Guide**: Full EPG with program info, categories, and search
- **DVR Scheduling**: Schedule recordings with priority management
- **Time-Shifting**: Pause and rewind live TV with buffer
- **Channel Lineups**: Custom channel lineups per user
- **Favorites**: Personal favorite channels per user
- **Storage Management**: Recording storage tracking and limits

## Installation

### One-line install (Ubuntu/Debian)

On a fresh Ubuntu/Debian host, [`scripts/install.sh`](scripts/install.sh) does the whole thing:
system packages (PHP 8.3+, MySQL, ffmpeg), a dedicated `phlix` system user, MySQL database +
user, application code, env file at `/etc/phlix/env`, generated `PHLIX_SECRET_KEY`, database
migrations, a systemd `phlix-server` service, and an HAProxy reverse proxy with an
auto-renewing Let's Encrypt certificate.

> The installer also compiles the **Swoole + php-uv** extensions from source (the coroutine
> runtime Workerman uses), idempotently skipping the build when they already load, and runs a
> `disable_functions` preflight — see
> [Swoole & php-uv on Linux](https://detain.github.io/phlix-docs/install/linux#swoole-php-uv-coroutine-runtime).

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh | sudo bash
```

Provision HTTPS in the same run by passing your domain and a Let's Encrypt contact email:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --domain phlix.example.com --admin-email you@example.com
```

The script prompts for the install path, database user/password, and hostname when run in a
terminal (with sensible defaults), and runs **fully unattended** when piped or given `-y`. Run
`sudo bash scripts/install.sh --help` for every flag. Default ports: HTTP on `:8096` behind
HAProxy on `:80`/`:443`; DLNA discovery on `1900/udp`.

### Install flags

`sudo bash scripts/install.sh --help` lists every option. The most useful:

| Flag | Effect |
|---|---|
| `--domain HOST` | Public hostname for the server (enables TLS when paired with `--admin-email`) |
| `--admin-email EMAIL` | Email registered with Let's Encrypt |
| `--db-name`, `--db-user`, `--db-pass`, `--db-host`, `--db-port` | MySQL identity (random password if `--db-pass` omitted). Note: `config/database.php` hardcodes host/port/db/user; only the password is env-driven. |
| `--http-port PORT` | HTTP listen port (default `8096`) |
| `--tmdb-api-key KEY` | TMDB API key for metadata (optional, recorded in `/etc/phlix/env`) |
| `--hub-url URL` | `PHLIX_HUB_URL` for hub relay (optional) |
| `--service-user USER` | System user to run as (default `phlix` — dedicated system account, created if missing) |
| `--branch NAME` | Git branch or tag to install (default `master`) |
| `--repo URL` | Git repository URL (default `detain/phlix-server`) |
| `--tls` / `--no-tls` | Force or skip Let's Encrypt + HAProxy TLS |
| `--no-proxy` | Skip the managed HAProxy entirely (use your own reverse proxy) |
| `--update` | Pull new code + run migrations on an existing install (preserves env + secrets) |
| `--uninstall` | Remove the install — interactive prompts before each destructive step |
| `--purge` | With `--uninstall`, also drop the DB, delete the Let's Encrypt cert, wipe `/var/phlix`, and remove the dedicated system user |
| `-y`, `--non-interactive` | Never prompt; use defaults/flags |
| `--interactive` | Force prompts even when piped |

### Updating an existing install

The same `scripts/install.sh` updates an in-place install **without rotating any secrets**. It
reads the existing `/etc/phlix/env` (so `DB_PASSWORD` and `PHLIX_SECRET_KEY` are preserved),
pulls the latest code, refreshes Composer dependencies, runs migrations, and restarts the
service:

```bash
sudo bash /var/www/phlix/scripts/install.sh --update -y
```

Pin to a specific tag or branch with `--branch`:

```bash
sudo bash /var/www/phlix/scripts/install.sh --update --branch v0.2.0 -y
```

`--update` discovers the install path from the systemd unit's `WorkingDirectory`, fetches code
as the install dir owner (so it doesn't trip Git's CVE-2022-24765 dubious-ownership check),
runs `composer install --no-dev --optimize-autoloader`, clears `templates_c/`, runs
`scripts/run-migrations.php`, restarts `phlix-server`, and curl-checks `/health`. It
deliberately leaves the env file, MySQL grants, HAProxy config, and Let's Encrypt cert alone.

### Uninstalling

`scripts/install.sh --uninstall` removes an existing install. It is **interactive by default**
and prompts separately before each destructive step. The MySQL database, the `/var/phlix` data
directory, and the Let's Encrypt certificate are **kept** unless you opt in:

```bash
sudo bash /var/www/phlix/scripts/install.sh --uninstall
```

Add `--purge` to also drop the database (and user), wipe `/var/phlix` (config, library cache,
backups), and delete the Let's Encrypt certificate via `certbot delete`. Combine with `-y` for
a fully unattended teardown:

```bash
sudo bash /var/www/phlix/scripts/install.sh --uninstall --purge -y
```

What it removes when present:

1. The `phlix-server` systemd unit (`stop`, `disable`, remove file, `daemon-reload`).
2. HAProxy fragment at `/etc/haproxy/phlix-managed/phlix-server.cfg.fragment`, and
   `/etc/haproxy/haproxy.cfg` is rebuilt. If phlix-hub is still installed, its frontend +
   backend stay. If phlix-server was the last Phlix project, the pre-Phlix snapshot at
   `/etc/haproxy/haproxy.cfg.pre-phlix.bak` is restored (or `haproxy.cfg` is removed and
   haproxy is stopped + disabled if no snapshot exists).
3. The combined PEM at `/etc/haproxy/certs/<domain>.pem`.
4. `/etc/cron.d/phlix-server-certbot` and the certbot deploy hook.
5. The Let's Encrypt cert via `certbot delete` — only with `--purge` or interactive confirm.
6. The MySQL database + user — only with `--purge` or interactive confirm.
7. The install dir (`/var/www/phlix` by default; system paths refused).
8. `/var/phlix` (config, library cache, backups) — only with `--purge` or interactive confirm.
9. `/var/log/phlix` and `/var/run/phlix`.
10. `/etc/phlix/env` (env file).
11. The dedicated system user `phlix` via `userdel` — only with `--purge` or interactive
    confirm. Refuses to touch shared OS accounts (`www-data`, `root`, etc.). Cross-detects
    phlix-hub's systemd unit and refuses to remove a user that's still being used by it.

System packages (`php-*`, `mysql-server`, `ffmpeg`, `haproxy`, `certbot`) and `ufw` rules are
left in place — `sudo apt remove …` / `sudo ufw delete …` to remove them.

### Running alongside phlix-hub on the same server

Both installers can share a single HAProxy instance — they auto-merge into one
`/etc/haproxy/haproxy.cfg`. Just run both installers normally; the second one detects the
first's fragment and rebuilds a combined config that routes by `Host:` header.

```bash
# 1. Install phlix-hub first (with TLS).
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh \
  | sudo bash -s -- --domain hub.example.com --admin-email you@example.com -y

# 2. Install phlix-server, also with TLS, on a different hostname.
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --domain phlix.example.com --admin-email you@example.com -y
```

After both finish, `/etc/haproxy/haproxy.cfg` looks like:

```haproxy
# phlix-managed: rebuilt by phlix install scripts — do not edit
...
frontend fe_https
    bind :443 ssl crt /etc/haproxy/certs/
    http-request set-header X-Forwarded-Proto https

    # --- phlix-hub ---
    acl is_phlix_hub_host hdr(host) -i hub.example.com
    use_backend be_hub_client_relay if is_phlix_hub_host { path_beg /client/ }
    use_backend be_hub if is_phlix_hub_host

    # --- phlix-server ---
    acl is_phlix_server_host hdr(host) -i phlix.example.com
    use_backend be_phlix_server if is_phlix_server_host
    ...
```

**How the merge works.** Each install drops a fragment at
`/etc/haproxy/phlix-managed/<project>.cfg.fragment` with `fe_http`, `fe_https`, and `backends`
sections. A rebuilder function then assembles the final `haproxy.cfg` from every fragment it
finds. HAProxy's `crt /etc/haproxy/certs/` directive auto-loads every `.pem` in that directory
and picks the right one per SNI hostname.

The first install snapshots any pre-Phlix `haproxy.cfg` to
`/etc/haproxy/haproxy.cfg.pre-phlix.bak`.

**Uninstall behaviour**: `--uninstall` removes only that project's fragment and rebuilds. If
other Phlix projects remain, their frontend stays untouched. When the **last** Phlix project
is uninstalled, the rebuilder restores the pre-Phlix snapshot (or removes `haproxy.cfg`
outright if there was no pre-Phlix config) and stops/disables `haproxy`.

The **hub server-tunnel port** (`:8802`) is a separate listener — servers connect to that port
directly. Open it on the firewall but don't put it behind the HAProxy 80/443 frontend.

If you'd rather use your own reverse proxy (nginx, Caddy, Traefik, etc.) instead of the
managed HAProxy, pass `--no-proxy` to either install script. Each service then listens on its
own port (8096 for phlix-server, 8800 for phlix-hub) and you point your proxy at those.

Everything else is already namespaced: env files (`/etc/phlix-hub.env` vs `/etc/phlix/env`),
systemd units (`phlix-hub.service` vs `phlix-server.service`), install dirs (`/opt/phlix-hub`
vs `/var/www/phlix`), service users (`www-data` vs `phlix`), MySQL DBs (`phlix_hub` vs
`phlix`), backend ports (8800/8802/8803 vs 8096), and certbot artefacts.

### Manual install (from source)

```bash
# Clone the repository
git clone https://github.com/detain/phlix-server.git
cd phlix-server

# Install dependencies
composer install

# Run database migrations (reads config/database.php; password from DB_PASSWORD env var)
DB_PASSWORD=your_strong_password php bin/phlix migrate    # or: php scripts/run-migrations.php

# Start the server (HTTP + WebSocket on port 8096 from config/server.php)
php public/index.php start
```

## Configuration

Configuration is managed via PHP files in `config/`:

```php
// config/server.php
return [
    'server' => [
        'name' => 'Phlix Media Server',
        'host' => '0.0.0.0',
        'port' => 8080,
    ],
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8097,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'phlix',
        'username' => 'phlix',
        'password' => 'secure-password',
    ],
    'debug' => false,
];
```

### Environment variables

Most operators only set `DB_PASSWORD` and `JWT_SECRET`; the following knobs cover
the streaming and auth-hardening features and all have safe defaults.

| Variable | Default | Description |
|----------|---------|-------------|
| `HLS_MIN_DISK_SPACE_BYTES` | `524288000` (500 MiB) | Free-space floor for the HLS segment-cache directory (`config/server.php` → `hls.min_disk_space_bytes`). When free space drops below this, the server sweeps the cache and returns `503` with `Retry-After: 3` instead of failing an encode with `ENOSPC`. |
| `RATE_LIMIT_<SURFACE>_MAX` | per-surface default | Max attempts per window for an auth surface before it rate-limits. `<SURFACE>` is one of `REGISTER`, `REFRESH`, `WEBAUTHN_START`, `WEBAUTHN_FINISH`, `JWKS`, `WS_CONNECT` (defaults: register 5, refresh 30, webauthn_start/finish 10, jwks 120, ws_connect 30). See `config/server.php` → `rate_limit`. |
| `RATE_LIMIT_<SURFACE>_WINDOW` | per-surface default | Window length in seconds for the matching surface (defaults: register 600, refresh 60, webauthn_start/finish 60, jwks 60, ws_connect 60). |
| `TRUSTED_PROXIES` | loopback only (`127.0.0.1`, `::1`) | Comma-separated IP/CIDR list of reverse-proxy hops. Used to derive the **real** client IP from `X-Forwarded-For`/`X-Real-IP` for rate-limit keys. **This must reflect your nginx/HAProxy hops** — if a non-loopback proxy fronts the server and is not listed here, IP-keyed limits will bucket every request under the proxy address (or trust a client-forged header). The stock install fronts Phlix over loopback, so the default is correct there. |
| `PHLIX_DEBUG_EVENTS` | `0` (off) | Diagnostic toggle. When truthy (`1`/`true`/`yes`/`on`) it (1) wraps the PSR-14 dispatcher in a debug decorator that logs every dispatched event class, and (2) enables the `events` log handler so those records are written to `.logs/events.log`. When off, `events.log` stays empty and no per-event debug logging happens. Leave off in production. `events.log` only ever receives `EVENTS`-channel records; `plugins.log` only `PLUGINS`-channel records; `app.log`/`error.log` still capture everything / all errors. See `config/logger.php`. |

> **Rate limiting.** The auth surfaces above (`register` / `refresh` / WebAuthn
> `start`+`finish` / public JWKS / WS-connect on `:8097`) are rate-limited.
> HTTP surfaces reply `429 Too Many Requests` + `Retry-After` with body
> `{"error":"Too Many Requests","code":"rate_limited"}`; WS-connect rejects the
> handshake. `login` keeps its own DB-backed IP limiter (migration 074).

> **Loudness normalization.** `config/ffmpeg.php` → `loudness` (disabled by
> default) applies an EBU R128 `loudnorm` filter to **re-encoded** audio when
> enabled. Copy-audio rungs (the `original` variant) and direct-play sessions
> are not normalized — you cannot filter a copied (non-decoded) stream.

> **Migration required on deploy.** SV-4.15 adds
> `migrations/085_rate_limit_buckets.sql` (the shared DB-backed rate-limit bucket
> table). Run `php bin/phlix migrate` (or `php scripts/run-migrations.php`) after
> pulling — migrations are idempotent, so re-running is safe.

> **Migration + one-time cleanup required on deploy (S29, updates.md #29).** Adds
> `migrations/090_playback_state_session_media_unique.sql` (documentation-only —
> reserves the number). After `php bin/phlix migrate` (or `php scripts/run-migrations.php`),
> run **once**: `php migrations/cleanup_090.php`. It merges any duplicate
> `(session_id, media_item_id)` rows in `playback_state` (keeping the max `updated_at`,
> tie-break max `id`; batched for large tables) then adds the
> `uq_playback_state_session_media` unique key, so playback-progress upserts update the
> existing row instead of inserting a new one every ~15s. **`scripts/install.sh` / the
> Docker entrypoint do NOT run it automatically** (same as the migration-072
> `cleanup_072.php` step); until it runs, the unique key does not exist and progress
> writes keep duplicating. The script is idempotent, so re-running is safe.

## API Reference

### HTTP Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Health check |
| GET | `/system/info` | Server information |
| POST | `/api/v1/auth/register` | User registration |
| POST | `/api/v1/auth/login` | User login |
| POST | `/api/v1/auth/refresh` | Token refresh |
| GET | `/api/v1/auth/me` | Current user profile |
| GET | `/api/v1/sessions` | List user sessions |
| DELETE | `/api/v1/sessions/{id}` | End a session |
| POST | `/api/v1/sessions/{id}/progress` | Report playback progress |
| GET | `/api/v1/sessions/{id}/progress` | Get playback state |
| POST | `/api/v1/sessions/{id}/complete` | Signal that playback finished — body `{media_item_id: string (required), reached_end?: bool = true}`; `reached_end:true` marks the item watched (leaves Continue Watching, finalizes watch-time/stats), `false` clears the resume point. Auth mirrors `/progress` (session owner). `→ {message, reached_end}`; `404` unknown session, `403` wrong owner, `400` missing `media_item_id`. The web SPA player + mini-player POST this on the media `ended` event; native clients do not call it yet |
| GET | `/api/v1/users/me/continue-watching` (also `/api/v1/me/continue-watching`) | Continue-Watching list for the authenticated user — `{items:[...]}` of `MediaItemShaper`-shaped rows with top-level `id` (= media item id, for detail navigation), `poster_url`/`poster_srcset` (series poster for episodes, falling back season → own), `runtime` (minutes), `year`, `rating`, plus re-attached `position_ticks`/`duration_ticks` (SPA resume) and preserved `media_item_id`, `parent_id`, `metadata` (console/rating-gate compatibility) |
| GET | `/api/v1/media/facets` | Distinct, sorted genre facet list for the media filter UI (`?libraryId=<uuid>` to scope) → `{genres: string[]}` |
| GET | `/api/v1/media/most-watched` | GLOBAL "Most Watched" trending rail — the media items most-watched across the WHOLE server (not per-user), reusing the same all-time cross-user aggregate as the admin Top Media report, ordered by play count. Optional `?limit=` (default 20, clamped to 100). Auth required (`AuthMiddleware`, same audience as `GET /api/v1/media`). Returns `{items, total, limit, offset}` with `MediaItemShaper`-shaped items (poster/artwork signed URLs re-minted at response time) |
| GET | `/api/v1/media/{id}` | Media item detail — includes `user_data: {favorite, rating, like_level}` when authenticated (`null` otherwise) |
| POST | `/api/v1/media/{id}/favorite` | Mark a media item as the user's favorite — auth required |
| DELETE | `/api/v1/media/{id}/favorite` | Remove a media item from the user's favorites — auth required |
| PUT | `/api/v1/media/{id}/rating` | Set the user's personal rating (body `{rating: int 1-10\|null}`; `null` clears) — auth required |
| DELETE | `/api/v1/media/{id}/rating` | Clear the user's personal rating — auth required |
| PUT | `/api/v1/media/{id}/like` | Set the user's thumbs value (body `{level: int -2..2}`, required; `-2`=strongly dislike, `-1`=dislike, `0`=not set, `1`=like, `2`=love) — auth required |
| GET | `/api/v1/users/me/favorites` | List the user's favorited items (shaped media items + `user_data` incl. `like_level`; `?limit=1-100&offset`) — auth required |
| GET | `/api/v1/admin/settings` | Effective server settings (config default + DB override) — admin-only |
| PUT | `/api/v1/admin/settings` | Persist server-setting overrides — admin-only |
| GET | `/api/v1/admin/fs/browse` | List subdirectories under allowed roots (library path picker) — admin-only |
| GET | `/api/v1/admin/users` | List all users — admin-only |
| GET | `/api/v1/admin/users/{id}` | Get a specific user — admin-only |
| POST | `/api/v1/admin/users` | Create a new user — admin-only |
| PUT | `/api/v1/admin/users/{id}` | Update a user (username, email, password) — admin-only |
| DELETE | `/api/v1/admin/users/{id}` | Delete a user — admin-only |
| POST | `/api/v1/admin/users/{id}/set-admin` | Promote or demote a user — admin-only |
| POST | `/api/v1/admin/users/{id}/reset-password` | Reset a user's password (returns new password) — admin-only |

**Rate limiting.** `POST /auth/register`, `POST /auth/refresh`, the WebAuthn
login `start`/`finish` endpoints, and the public JWKS endpoint reply
`429 Too Many Requests` + `Retry-After` (body
`{"error":"Too Many Requests","code":"rate_limited"}`) when a caller exceeds the
per-surface limit. Tune via `RATE_LIMIT_*` (see [Environment variables](#environment-variables)).

**JWKS key format.** `GET /.well-known/jwks.json` serves this server's Ed25519
public key from `config/hub-server-key.pem`. The reader accepts **both** the
app's native `-----BEGIN ED25519 PRIVATE KEY-----` format **and** a standard
PKCS#8 Ed25519 key (`-----BEGIN PRIVATE KEY-----`, e.g. from
`openssl genpkey -algorithm Ed25519`). If the key cannot be loaded, the endpoint
degrades to a valid empty keyset (`{"keys":[]}`, HTTP 200) and logs an error
rather than returning a 500.

**Client capability negotiation.** Send an `X-Phlix-Client-Capabilities` request
header (a JSON codec-support map, e.g. `{"eac3":false}`) on playback-info
requests. When present, the `direct_play` verdict is set from whether the client
can decode the item's audio codec; absent/empty header keeps the previous
always-`true` behavior.

### WebSocket Events

**Connection Events:**
- `connected` - Sent on successful connection
- `client_disconnected` - Broadcast when client disconnects

**Authentication Events:**
- `auth_request` - Request authentication
- `auth_success` - Authentication successful
- `auth_failure` - Authentication failed

**Playback Events:**
- `playback_start` - Playback started
- `playback_pause` - Playback paused
- `playback_stop` - Playback stopped
- `playback_progress` - Progress update
- `playback_seek` - Seek performed

**SyncPlay Events:**
- `syncplay_create_group` - Create watch group
- `syncplay_join_group` - Join watch group
- `syncplay_leave_group` - Leave watch group
- `syncplay_sync_state` - State synchronization

## Development

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
```

### Code Standards

This project follows PSR-12 coding standards and uses static analysis tools:

```bash
# Check code style
./vendor/bin/phpcs --standard=PSR12 src/

# Run static analysis
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/psalm
```

### CLI

Administrative tasks are exposed through the `bin/phlix` command-line tool (built on `webman/console` / Symfony Console):

```bash
php bin/phlix list       # list every available command
php bin/phlix migrate    # apply migrations/*.sql against config/database.php
php bin/phlix media:dedupe-paths           # preview duplicate-path merges (dry-run)
php bin/phlix media:dedupe-paths --apply   # merge duplicate media items sharing a path
```

`php bin/phlix migrate` is the supported equivalent of `php scripts/run-migrations.php` — both delegate to the same `Phlix\Common\Database\MigrationRunner` service, applying every `migrations/*.sql` file on each run (idempotent; no tracking table). More commands are added in later steps.

### Admin SPA (admin-ui)

The admin console is a **React + TypeScript + Vite** single-page app. Its source lives in
`admin-ui/`; the production build is emitted into `public/assets/admin/` and **committed to the
repo**, so the running Workerman server has **no Node build dependency at runtime** (it just serves
the static shell + bundle). `admin-ui/node_modules/` is gitignored.

The SPA mounts at `/admin` and `/admin/*`, served by `AdminAppController` (returns the built
`index.html` shell; 503 if the bundle is missing) and gated by the existing `AdminMiddleware` — a
non-admin (401/403) is redirected (302) to `/login`. Its typed `ApiClient` reuses the same JWT
mechanism as `public/assets/js/api-client.js` (`access_token`/`refresh_token` in `localStorage`,
Bearer header, single retry on 401 via `POST /auth/refresh`).

```bash
cd admin-ui
npm install          # one-time / on dependency changes
npm run build        # tsc + vite build → ../public/assets/admin/ (commit the result)
npm run test         # Vitest unit/component tests
npm run dev          # Vite dev server (HMR) for local development
```

When you change anything under `admin-ui/src/`, re-run `npm run build` and commit the refreshed
`public/assets/admin/` bundle along with your source changes.

CI runs the SPA build + Vitest suite on any change under `admin-ui/` via the **Admin UI** GitHub
Actions workflow (`.github/workflows/admin-ui.yml`): `npm ci → npm run build → npm run test` on
push/PR to `master`/`main`/`develop` (path-filtered, so PHP-only changes don't trigger it).

The first feature page on top of the scaffold is the **Libraries** page at `/admin/libraries`
(step 1.1c): list / add / edit / delete libraries, a `PathPicker` driving the 0.6
`GET /api/v1/admin/fs/browse` endpoint, per-row Scan / Rescan buttons that hit the **async** 1.1b
scan API (`POST /api/v1/libraries/{id}/scan|rescan` → 202 `{job_id, status: "queued"}`), live
**coarse** lifecycle status by polling `GET .../scan-status` every 2 s (polling stops on
`completed` / `failed`), and a per-library scan-history modal. No backend changes — the page
consumes only contracts already shipped by 0.6 and 1.1b.

The **Settings** page at `/admin/settings` (step 1.3) renders all 15 server-setting
keys across 8 tabbed groups (Transcoding, Metadata, Markers, Subtitles, Discovery,
Trickplay, Newsletter, Port Forward). It consumes the 0.5 GET/PUT `/api/v1/admin/settings`
contract — **no new endpoints were added**. Bool keys render as toggle switches;
numeric keys render as number inputs with `min`/`max` constraints; `tmdb.api_key` renders
as a password field with Show/Hide toggle. Overridden keys (DB-persisted vs. config-file
default) display a "custom" badge. A sticky Save button fires `PUT /api/v1/admin/settings`;
dirty-state gating keeps the button disabled when no fields have changed.

The **Webhooks** page at `/admin/webhooks` (step 1.4a) provides full CRUD for webhook
subscriptions plus a per-webhook test trigger. It consumes the five endpoint contract in
`WebhookAdminController` (GET list, POST create, PUT update, DELETE remove, POST test).
The `PUT /api/v1/admin/webhooks/{id}` route, plus the backing `WebhookDispatcher::update()`
and `WebhookAdminController::update()` PHP methods, were added in this step to support
edit-in-place (the controller already had index/create/delete/test before 1.4a).
The event multi-select shows 7 subscribable events grouped into 5 categories
(Playback, Library, Downloads, Recordings, Alerts); `webhook.test` is internal-only.
Secret is write-only — GET never returns it; the edit form shows an empty field with
"(unchanged)" placeholder and omits `secret` from the PUT payload when blank, so the
server retains the stored value. Coverage: 97.29% on `webhooks.ts`, 89.74% on
`WebhooksPage.tsx`.

Full operator + contributor docs live in the
[phlix-docs](https://github.com/detain/phlix-docs) site (`docs/admin/integrations.md`,
`docs/admin/server-settings.md`, and `docs/dev/admin-spa.md`).

### Git Workflow

1. Create a feature branch: `git checkout -b feature/my-feature`
2. Make changes and commit: `git commit -am 'Add new feature'`
3. Push to remote: `git push origin feature/my-feature`
4. Create Pull Request on GitHub
5. After review, merge via squash-merge

## Contributing

1. Fork the repository
2. Create your feature branch
3. Ensure all tests pass (`./vendor/bin/phpunit`)
4. Follow PSR-12 coding standards
5. Submit a pull request

## License

Proprietary - All rights reserved.

## Support

For issues and feature requests, please use the GitHub issue tracker.

---

For detailed development documentation, see [DEVELOPER.md](DEVELOPER.md).

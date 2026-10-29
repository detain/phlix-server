# Phlix Media Server

[![PHPUnit](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml)
[![Web UI](https://github.com/detain/phlix-server/actions/workflows/web-ui.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/web-ui.yml)
[![codecov](https://codecov.io/gh/detain/phlix-server/graph/badge.svg)](https://codecov.io/gh/detain/phlix-server)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![Code style](https://img.shields.io/badge/code%20style-PSR--12-blueviolet)](https://www.php-fig.org/psr/psr-12/)

A comprehensive media server platform built with PHP 8.3+, featuring real-time WebSocket communication, HTTP REST APIs, and support for multiple client platforms including Roku, Samsung Tizen, and Windows.

> **Repository moved 2026-05-17:** this codebase migrated from `github.com/detain/phlix` to [`github.com/detain/phlix-server`](https://github.com/detain/phlix-server) as part of the Phase B repo split. Update existing local clones with `git remote set-url origin git@github.com:detain/phlix-server.git`. The old repo is being archived in step B.4b.

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

public/                 # no front controller — start.php serves everything (see below)
├── templates/emails/   # Smarty newsletter email template (only remaining .tpl)
└── assets/             # Static assets (css, js, images) + assets/app/ (built Vue SPA bundle)
```

## Requirements

- **PHP**: 8.3 or higher
- **MySQL**: 8.0+ or MariaDB 10.6+
  (On MariaDB — and on MySQL below 8.0.13/8.0.17 — the MySQL-only functional /
  multi-valued index statements in migrations 011 and 050 are skipped with an
  operator-visible note via the `@phlix-require-mysql` dialect gate in
  `src/Common/Database/MigrationRunner.php`. They are performance-only, and 051
  supersedes the multi-valued one with a join table MariaDB builds natively, so
  no query or feature is lost — only those specific index scans stay slower.)
- **Workerman**: 5.0+ (bundled via Composer)
- **FFmpeg**: For transcoding (optional)

Every environment variable the server process reads is inventoried in the definitive census —
[Environment variables](#environment-variables) — with defaults, secrets, and restart semantics.

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
  → their `/app` pages), dispatched in `src/Server/Workerman/HttpHandler.php` (the served daemon; the pre-S171 `public/index.php` copy of that logic was unserved and deleted). Smarty (`smarty/smarty`) is retained on the
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
- **External SSO — OIDC, LDAP & GitHub**: Optional external login backends, toggled per-provider from the admin **Integrations → Auth providers** page. Flows, endpoints and config pointers: [External SSO](#external-sso).
- **Secure Password Hashing**: Argon2ID for password storage
- **Multi-Device Sessions**: Track and manage sessions across devices
- **User Profiles**: Multiple profiles per account with parental controls
  - Up to 5 profiles per user account
  - Profile-specific content rating restrictions (G, PG, PG-13, R, NC-17, X, UNRATED)
  - PIN protection (4 or 6 digits) for profile settings
  - Genre-based filtering (allowed/blocked genre lists)
  - Daily watch time limits per profile — **stored, not enforced**: the
    `max_daily_watch_time` setting persists through the profile settings API,
    but no playback path reads it (and there is no live per-profile watch-time
    ledger to measure against), so a configured cap never restricts anything
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

### External SSO

Optional OpenID Connect, LDAP and GitHub OAuth2 login backends, toggled per-provider from the
admin **Integrations → Auth providers** page (persisted as the `auth.oidc.enabled` /
`auth.ldap.enabled` / `auth.github.enabled` server settings). An external identity always
resolves to an existing account — it never silently creates a duplicate — and the auth provider
registry supports multiple instances of the same family (e.g. two OIDC issuers; configuring
named instances from the admin console is a later step).

- **OIDC** — authorization-code flow with PKCE + `state` + `nonce` and id-token validation:
  `GET /auth/oidc/authorize` → `GET /auth/oidc/callback`.
- **GitHub** — plain OAuth2 + PKCE, with no OIDC discovery and no `id_token`:
  `GET /auth/github/authorize` → `GET /auth/github/callback`. The user is identified from
  `GET https://api.github.com/user` (default scopes `read:user user:email`) and keyed on
  GitHub's stable numeric id (`github.<id>`) — never the renameable login, never the e-mail.
- **LDAP** — no separate endpoints. It rides `POST /auth/login` with an `ldap:`-prefixed
  username (e.g. `ldap:jdoe`).
- **Session hardening** — sessions are delivered as httpOnly + Secure + SameSite=Lax cookies.
  The OIDC/GitHub post-login redirect is allowlisted to same-origin paths only. The OAuth
  `state` is additionally bound to the browser that started the flow by a short-lived HttpOnly
  correlation cookie (`phlix_oauth_oidc` / `phlix_oauth_github`).
- **Account linking** — an already-signed-in user can attach an external identity to their
  account. `GET /auth/identities` lists linked identities; `GET /auth/identities/link/oidc` and
  `GET /auth/identities/link/github` start a verified OAuth round-trip link;
  `POST /auth/identities/link/ldap` links via a live LDAP bind; `DELETE /auth/identities/{id}`
  unlinks one (own identity only, refused with `409` if it would remove your last sign-in
  method). Linking always proves control of the identity and never mints a new session; an
  identity already linked to another account is rejected with `409`. A linked identity is then
  usable for login.

Config pointers:

- **Provider configuration is DB-backed** (migration `093_plugin_settings.sql`, table
  `plugin_settings`) rather than a per-plugin `settings.json`; an existing file is imported
  once, automatically, on first read — no operator action on upgrade. The OIDC and GitHub
  settings saves **preserve any optional key absent from the request body** (only an
  explicitly empty value clears one), so a partial payload cannot wipe a field it does not
  know about. (The LDAP save still rebuilds its document from the body alone apart from
  `bind_pw` — send it the complete map.)
- **The provider `redirect_uri` is always absolute and is pinned to `PHLIX_DOMAIN`.** Register
  `https://<your PHLIX_DOMAIN>/auth/oidc/callback` (or `/auth/github/callback`) at the IdP /
  OAuth App. If neither `PHLIX_DOMAIN` nor an absolute per-provider `redirect_uri` setting is
  configured, `/auth/{provider}/authorize` **fails closed** with `503
  callback_url_not_configured`. See [Environment variables](#environment-variables) and the
  SSO/external-auth guide in phlix-docs for the full setup, including the GitHub provider
  (which has admin **API** endpoints but no admin-SPA card yet).

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
- **Completion**: Client-reported via `POST /api/v1/sessions/{id}/complete` — `reached_end: true`
  (the default) marks the item watched and removes it from Continue Watching; `false` clears the
  resume point. The web SPA player fires it on the media `ended` event; native clients do not call
  it yet. Continue Watching and Next Up are derived from the `playback_state` rows written by
  progress reporting — the server applies no automatic progress-threshold completion detection and
  keeps no per-profile watch-time statistics

### Live TV & DVR (admin-managed)
LiveTV ships as operator tooling. The entire control plane — tuners, channels, guide,
recordings, and series rules — is served under `/api/v1/admin/livetv` behind admin auth,
and the web SPA exposes it on an admin-only page (`/app/admin/livetv`); there is no
member-facing LiveTV page, channel browse, recording-schedule, or settings surface.
The only non-admin LiveTV routes are stream legs, served under
the same signed-URL/stream middleware as HLS/DASH:
`GET /livetv/recording/{id}/stream` (DVR recording playback, re-checked against the
requester's profile rating cap via the recording's linked library item) and
`GET /livetv/timeshift/{sessionId}/...` (the live tuner's rolling window — session rows
carry only a channel id with no library linkage, so these are rating-gated by nothing, by
design).

Shipped capabilities (driven through the admin API — except recording storage, which is
config-file driven and has no HTTP surface):
- **Multi-Tuner Support**: DVB-T, HDHomeRun, and IPTV tuner drivers; DVB-S, DVB-C, and
  ATSC are reserved type constants only — no drivers exist for them
- **Channel Scanning**: Automatic discovery of broadcast services
- **Electronic Program Guide**: Program info with categories, filtered by channel and
  time range
- **DVR Scheduling**: Schedule recordings with priority management
- **Time-Shifting**: Rolling-HLS buffer of the over-air window; seeking within it is
  inherent to HLS and handled by the player — the server serves the buffer and implements
  no cursor seeking of its own
- **Storage Management**: Used-space tracking and the total recording-size limit are
  enforced internally by the scheduler; configured in `config/livetv.php`
  (`storage_path`, `max_storage_bytes`)

Not yet exposed: per-user channel lineups and personal channel favorites exist only in the
service layer (`ChannelManager` over the `livetv_lineups`/`livetv_favorites` tables); no
HTTP routes or UI back them yet, so members cannot favorite channels or build lineups.

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
| `--db-name`, `--db-user`, `--db-pass`, `--db-host`, `--db-port` | MySQL identity (random password if `--db-pass` omitted). The installer writes these to `/etc/phlix/env`; at runtime `config/database.php` reads all of `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USER` / `DB_PASSWORD` from the environment — see [Environment variables](#environment-variables). |
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

Or via the one-liner, which works regardless of where the server was installed because
`--update` discovers the install path from the systemd unit. **This is the exact string the
admin console's update banner offers for copy-to-clipboard** (`config/updates.php`'s
`update_command`, surfaced by `GET /api/v1/admin/updates/status`) — the server never runs it
for you:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --update -y
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

# Start the server (HTTP :8096 + WebSocket :8097 workers; ports from config/server.php)
php start.php start
```

## Configuration

Configuration is managed via PHP files in `config/`:

```php
// config/server.php
return [
    'server' => [
        'name' => 'Phlix Media Server',
        'host' => '0.0.0.0',
        'port' => 8096,
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

This is the **definitive census** (R14-05): every environment variable the
server process reads, grouped by subsystem. The list was built by grepping
every `getenv()`, `$_ENV[]` and constant-mediated read in `src/`, `config/`,
`start.php`, `bin/` and `scripts/` — if a knob is not here, the PHP runtime
does not read it. Most operators only set `DB_PASSWORD` and `JWT_SECRET`;
everything else has a safe default. "Secret?" marks values that must never be
logged or committed. The "Restart" column (R14-20) uses four values: **yes** =
read once at boot (bind, container/config construction) — a new value applies
only after restart; **per-proc** = read on first use inside each worker
process then memoized — effectively restart-scoped; **live** = `getenv()` on
every use; **n/a** = consumed by the CLI/shell layer, not the daemon. See
[What requires a restart](#what-requires-a-restart) below.

#### Listening sockets & processes

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_HTTP_HOST` | `0.0.0.0` (`server.host`) | no | yes | `start.php` via `ListenEndpoints` (R14-06) |
| `PHLIX_HTTP_PORT` | `8096` (`server.port`) | no | yes | same; also feeds the DLNA-advertised port |
| `PHLIX_HTTP_WORKER_COUNT` | `14` (`worker.count`; `auto` → 14) | no | yes | same |
| `PHLIX_WS_HOST` | `0.0.0.0` (`websocket.host`) | no | yes | same (WebSocket worker bind) |
| `PHLIX_WS_PORT` | `8097` (`websocket.port`) | no | yes | same |

#### Logging

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_LOG_STDOUT` | off (`0`) | no | yes | `config/logger.php` — truthy (`1`/`true`/`yes`/`on`) ADDS a `php://stdout` sink for `docker logs`/`journalctl`; file handlers keep working (R14-09) |
| `PHLIX_LOG_LEVEL` | per-handler (`file` info / `error` error / `events`+`plugins` debug) | no | yes | `StructuredLogger::createHandler()` lowers/raises every handler's floor; PSR-3 names, case-insensitive, `warn` accepted; unknown value fails SOFT to shipped defaults (R3-08) |
| `PHLIX_DEBUG_EVENTS` | `0` (off) | no | yes | truthy wraps the PSR-14 dispatcher in a debug decorator AND enables the `events` log handler (`.logs/events.log`). Leave off in production; see `config/logger.php` |

#### File-backed job queues

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_JOB_QUEUE_ROOT` | `<repo>/var/jobs` | no | yes | `JobQueuePaths` — one root for all five queues (R14-14); deliberately NOT `/tmp`, which `PrivateTmp=true` sandboxes per-service |
| `PHLIX_MARKER_JOBS_DIR` | `<root>/marker` | no | yes | marker-detection queue dir (beats `PHLIX_JOB_QUEUE_ROOT`) |
| `PHLIX_MEDIA_ASSET_JOBS_DIR` | `<root>/media_asset` | no | yes | chapter-thumbnail/trickplay queue dir |
| `PHLIX_COLLECTION_JOBS_DIR` | `<root>/collection` | no | yes | TMDB box-set sync queue dir |
| `PHLIX_METADATA_WRITE_JOBS_DIR` | `<root>/metadata_write` | no | yes | metadata write-back queue dir |
| `PHLIX_SIMILARITY_JOBS_DIR` | `<root>/similarity` | no | yes | similarity-computation queue dir |

#### Database & migrations

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | no | yes | `config/database.php` |
| `DB_PORT` | `3306` | no | yes | `config/database.php` |
| `DB_DATABASE` (alias `DB_NAME`) | `phlix` | no | yes | `config/database.php` (`DB_DATABASE` wins) |
| `DB_USER` (alias `DB_USERNAME`) | `phlix` | no | yes | `config/database.php` (`DB_USER` wins) |
| `DB_PASSWORD` | `` (empty) | **yes** | yes | `config/database.php` |
| `DB_POOL_ENABLED` | `1` (on) | no | yes | `config/database.php` → `PooledMySQLConnection` fleet |
| `DB_POOL_SIZE` | `8` | no | yes | `config/database.php` |
| `PHLIX_MIGRATIONS_DIR` | `migrations/` | no | n/a (CLI) | `bin/phlix`, `scripts/run-migrations.php` |
| `PHLIX_MIGRATIONS_STRICT` | off | no | n/a (boot) | **docker-entrypoint shell only** — truthy inverts the migration-failure boot-continue into refuse-to-start; the PHP runtime never reads it |

#### Auth & secrets

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `JWT_SECRET` | dev placeholder — **refuses to boot** unconfigured in production shape | **yes** | yes | `AuthServicesProvider` (pre-fork assert), `SignedUrl` fallback |
| `PHLIX_SECRET_KEY` | — | **yes** | n/a (shell) | **docker-entrypoint alias** → exported as `JWT_SECRET` before the daemon starts; the PHP runtime never reads this name |
| `PHLIX_SIGNED_URL_SECRET` | derives from `JWT_SECRET` (domain-separated) | **yes** | live | `src/Auth/SignedUrl.php` |
| `PHLIX_SIGNED_URL_TTL` | `21600` s (6 h) | no | live | `src/Auth/SignedUrl.php` |
| `PHLIX_COOKIE_INSECURE` | off | no | live | `AuthController` + OIDC/GitHub callback controllers — drops `Secure` on session cookies for plain-HTTP dev (never in production) |
| `PHLIX_TLS_ENABLED` | off | no | yes | `config/hub.php` — https vs wss scheme composition |

#### Hub relay (server → hub tunnels)

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_RELAY_ENABLED` | off | no | yes | `src/Hub/RelayConfig.php` |
| `PHLIX_RELAY_DISABLED` | off | no | yes | kill-switch honored by `RelayTunnelBoot`/`RelayStateStore`/admin hub controller even when config says enabled |
| `PHLIX_RELAY_HUB_URL` / `PHLIX_RELAY_HUB_WS_URL` | _unset_ | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_LOCAL_HTTP_ADDRESS` | _unset_ | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_PING_INTERVAL` / `PHLIX_RELAY_PING_TIMEOUT` | `30` / `10` s | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_RECONNECT_DELAY` / `PHLIX_RELAY_RECONNECT_MAX_DELAY` / `PHLIX_RELAY_RECONNECT_JITTER_FACTOR` | `5` / `300` s / `0.3` | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_REQUIRE_TLS` | off (`false`) | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_TLS` | off | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_TLS_CAFILE` / `PHLIX_RELAY_TLS_VERIFY` | system CA / on (verify) | CA path | yes | `RelayConfig.php`, `src/Hub/HttpClient.php` |
| `PHLIX_RELAY_TUNNEL_HOSTNAME` | _unset_ | no | yes | `RelayConfig.php` |
| `PHLIX_HUB_URL` | _unset_ | no | yes | `config/hub.php` |
| `PHLIX_HUB_JWKS_URL` | derived from `PHLIX_HUB_URL` | no | yes | `config/hub.php` |
| `PHLIX_HUB_HEARTBEAT_INTERVAL` | `60` s | no | yes | `config/hub.php` |
| `PHLIX_HUB_ENROLLMENT_RENEWAL_THRESHOLD` | `86400` s | no | yes | `config/hub.php` |
| `PHLIX_HUB_TLS_VERIFY` / `PHLIX_HUB_TLS_CAFILE` | on / system CA | no | yes | `src/Hub/HttpClient.php` |
| `PHLIX_SUBDOMAIN_AUTO_CLAIM` | `1` (on) | no | yes | `config/hub.php` |

#### SyncPlay write-through bridge

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `SYNCPLAY_BRIDGE` | on (only literal `0` disables) | no | yes | `config/server.php` |
| `SYNCPLAY_BRIDGE_SOCKET` | `<repo>/var/syncplay-bridge.sock` | no | yes | `config/server.php` |
| `SYNCPLAY_BRIDGE_TIMEOUT_MS` | `250` | no | yes | `config/server.php` |
| `PHLIX_SYNCPLAY_BRIDGE_TOKEN` | _unset_ | **yes** | yes | `config/server.php` + `src/Session/SyncPlay/SyncPlayBridge.php` |

#### HLS & transcoding

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `HLS_MAX_CONCURRENT_SEGMENTS` | `8` | no | yes | `config/server.php` → `hls.` |
| `HLS_CACHE_MAX_BYTES` | `8 GiB` | no | yes | `config/server.php` → `hls.` |
| `HLS_CACHE_MAX_AGE` | `10800` s | no | yes | `config/server.php` → `hls.` |
| `HLS_MIN_DISK_SPACE_BYTES` | `524288000` (500 MiB) | no | yes | `config/server.php` → `hls.` — free-space floor; below it the segment cache is swept and encodes answer `503` + `Retry-After: 3` instead of `ENOSPC` |
| `PHLIX_HLS_RELAY_MAX_BUFFER_SECONDS` / `PHLIX_HLS_RELAY_MAX_SEGMENTS` | see `config/hls_relay.php` | no | yes | `config/hls_relay.php` |
| `PREFERRED_ACCELERATOR` | `` (auto) | no | yes | `config/transcoding.php` |
| `TONE_MAPPING_MODE` | `none` | no | yes | `config/transcoding.php` |
| `PREFER_HDR_OUTPUT` | `false` | no | yes | `config/transcoding.php` |

#### Storage paths

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_LIBRARY_ROOTS` | see `src/Common/Fs/LibraryRootGuard.php` | no | per-proc | library path jail — read on first use per worker process, then memoized (restart to change mid-flight) |
| `PHLIX_BROWSE_ROOTS` | see `config/filesystem.php` | no | yes | filesystem-browser allowlist (config load at boot) |
| `ARTWORK_STORAGE_PATH` | `/var/artwork` | no | yes | `config/artwork.php` (config load at boot) |
| `SUBTITLE_STORAGE_PATH` | `/var/subtitles` | no | yes | `config/subtitles.php` (config load at boot) |
| `IMDB_CACHE_DIR` | see `config/imdb.php` | no | yes | `config/imdb.php` |
| `PHLIX_THEME_MUSIC_CACHE_DIR` | see `config/theme_music.php` | no | yes | `config/theme_music.php` |

#### Metadata providers & scrobblers

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `TMDB_API_KEY` | _unset_ | **yes** | live | `MediaServicesProvider`, `TmdbApiKeyResolver` (DB admin-setting wins) |
| `IMDB_ENABLED` | off | no | yes | `config/imdb.php` (config load at boot) |
| `TRAKT_CLIENT_ID` / `TRAKT_CLIENT_SECRET` | `` | **yes** (secret) | yes (master switch) | `config/scrobblers/trakt.php` — empty client id = Trakt subsystem off at boot |
| `TRAKT_REDIRECT_URI` | derived from `PHLIX_DOMAIN` | no | yes | `config/scrobblers/trakt.php` |
| `TRAKT_SYNC_INTERVAL` | `30` min (min 5, max 1440) | no | yes | `config/scrobblers/trakt.php` (config load at boot) |
| `TRAKT_TOKEN_ENCRYPTION_KEY` | `` | **yes** | yes | `config/scrobblers/trakt.php` + `Application` — required to persist user OAuth tokens |
| `LASTFM_ENABLED` | `0` (off) | no | yes | `config/lastfm.php` |
| `LASTFM_API_KEY` / `LASTFM_SHARED_SECRET` | `` | **yes** | yes | `config/lastfm.php` (`LASTFM_SHARED_SECRET` feeds both `shared_secret` and `api_secret`) |
| `LASTFM_CALLBACK_URL` / `LASTFM_SESSION_KEY` / `LASTFM_USERNAME` | `` | secret-capable | yes | `config/lastfm.php` |
| `LASTFM_SUBMIT_NOW_PLAYING` | `1` (on) | no | yes | `config/lastfm.php` (config load at boot) |

#### Plugins

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `COMPOSER_BIN` | `composer` on PATH | no | live | `PluginsProvider`, `ComposerRunner`, audit scripts |
| `PHLIX_PLUGINS_COMPOSER_TIMEOUT` | see `PluginsProvider` | no | live | plugin install/update composer runs |
| `PHLIX_PLUGINS_REQUIRE_SIGNATURE` | see `PluginsProvider` | no | live | plugin install signature gate |
| `PHLIX_PLUGINS_ALLOW_UNVERIFIED` | off | no | live | `PluginLoader` — allow loading unsigned plugins (dev only) |
| `PHLIX_PLUGINS_TRUSTED_SIGNATURES` / `PHLIX_PLUGINS_TRUSTED_SIGNATURES_FILE` | _unset_ | no | live | `TrustedSignaturesConfig` — inline list / PEM file of trusted keys |
| `PHLIX_PLUGINS_CATALOG_REF` | branch/tag per `CatalogSourceResolver` | no | live | catalog pin override |
| `PHLIX_PLUGINS_ALLOW_HTTP` | off | no | live | `HttpInstaller` — allow http:// plugin download URLs (dev only) |
| `PHLIX_GITHUB_TOKEN` (fallback `GITHUB_TOKEN`, `GH_TOKEN`) | _unset_ | **yes** | live | `ComposerRunner` — private-repo plugin fetches |

#### HTTP surface, limits & misc

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_CORS_ALLOWED_ORIGINS` | _unset_ | no | live | `CorsManager` — comma-separated origin allowlist |
| `PHLIX_DOMAIN` | _unset_ | no | live | `config/hub.php` composition + the OAuth2/OIDC `redirect_uri` Host allowlist: `/auth/{oidc,github}/authorize` derives `<scheme>://<Host><path>` only when `Host` equals this value; unset/malformed ⇒ fail-closed `503 callback_url_not_configured` (absolute per-provider `redirect_uri` is the escape hatch) |
| `TRUSTED_PROXIES` | loopback (`127.0.0.1`, `::1`) | no | live | `TrustedProxyResolver` — CIDR list of proxy hops allowed to assert `X-Forwarded-For`; unlisted non-loopback proxies bucket every request under the proxy address |
| `RATE_LIMIT_<SURFACE>_MAX` / `RATE_LIMIT_<SURFACE>_WINDOW` | per-surface | no | yes | `config/server.php` → `rate_limit`; `<SURFACE>` ∈ `REGISTER`, `REFRESH`, `WEBAUTHN_START`, `WEBAUTHN_FINISH`, `JWKS`, `WS_CONNECT` |
| `PHLIX_SSRF_ALLOW_CIDRS` | _unset_ (deny-all private) | no | per-proc | `src/Common/Net/SsrfGuard.php` — extra trusted CIDRs for outbound fetches (e.g. LAN metadata hosts); read on first guard use per worker process, then memoized |
| `PHLIX_METRICS_ENABLED` | `1` | no | yes | `config/metrics.php` (per-worker boot gate) |
| `PHLIX_METRICS_FLUSH_INTERVAL` / `PHLIX_METRICS_BUCKET_SECONDS` / `PHLIX_METRICS_RETENTION_DAYS` / `PHLIX_METRICS_CONNECTION_TTL` / `PHLIX_METRICS_ROUTE_CAP` | `5` / `10` s / `7` d / `15` s / `200` | no | yes | `config/metrics.php` |
| `PHLIX_UPDATES_CHECK_ENABLED` / `PHLIX_UPDATES_MARKER_URL` / `PHLIX_UPDATES_COMMAND` / `PHLIX_UPDATES_POLL_SECONDS` / `PHLIX_UPDATES_TIMEOUT_SECONDS` | see `config/updates.php` | no | yes | `config/updates.php` (update-check timer) |
| `PHLIX_CONTAINER_COMPILE` | off | no | yes | `ContainerFactory` (pre-fork compile) + `EventServicesProvider` (per-worker warm) — compile the PHP-DI container to `var/cache/` |
| `PHLIX_ENV` | `production` | no | yes | `AuthServicesProvider` dev-shape acceptance, `scripts/bootstrap_env.php` |
| `PHLIX_ENV_FILE` | `/etc/phlix/env` | no | n/a (CLI) | `scripts/bootstrap_env.php` |

#### Read via `$_ENV` only — caveat

`config/playback.php` and `config/port-forward.php` read their knobs through
`$_ENV[...]`, **not** `getenv()`. With PHP's common `variables_order = "GPCS"`
(CLI, systemd, most images) `$_ENV` is never populated from the process
environment, so these take their config-file defaults regardless of what you
export. They are effective only where `variables_order` includes `E` (or the
SAPI pre-populates `$_ENV`).

| Variable | Config default | Purpose |
|----------|----------------|---------|
| `PHLIX_CROSSFADE_DURATION` / `PHLIX_CROSSFADE_FADE_OUT` / `PHLIX_CROSSFADE_FADE_IN` | `0` / `0.3` / `0.3` | audio crossfade (disabled by default) |
| `PHLIX_UPNP_ENABLED` | `true` | UPnP IGD discovery |
| `PHLIX_PORT_FORWARD_AUTO` | `true` | automatic NAT-PMP/UPnP pinholing |
| `PHLIX_EXTERNAL_PORT` / `PHLIX_EXTERNAL_HTTP_PORT` / `PHLIX_EXTERNAL_HTTPS_PORT` | `32400` / `8080` / `8443` | advertised external ports |
| `PHLIX_STUN_SERVER` / `PHLIX_STUN_PORT` | `stun.l.google.com` / `19302` | STUN reachability probe |

#### Documented elsewhere, NOT implemented here

These names appear in historical CHANGELOG entries and/or external docs but
have **zero read sites** anywhere in this repository — do not expect setting
them to do anything: `PHLIX_HUB_ENROLLMENT_TOKEN`, `PHLIX_PUBLIC_URL`,
`PHLIX_HWACCEL`.

### What requires a restart

R14-20. Every knob above marked "Restart" is read **once** and changing it in
the process environment has no effect until the unit/container restarts. The
short list of why, grouped by the mechanism that reads it early:

- **Bound before the first fork (`start.php` master):** `PHLIX_HTTP_*`,
  `PHLIX_WS_*` (listen URIs + counts), `PHLIX_CONTAINER_COMPILE` (the compiled
  container is shared through fork inheritance).
- **Per-worker construction at boot:** `PHLIX_LOG_LEVEL` / `PHLIX_LOG_STDOUT` /
  `PHLIX_DEBUG_EVENTS` (handler graph), `DB_POOL_*` and all `DB_*`
  (`ConnectionPool::init()`), `PHLIX_METRICS_*` (collector per worker),
  the SyncPlay bridge sockets, Trakt/Last.fm **master switches**
  (`TRAKT_CLIENT_ID` empty and `LASTFM_ENABLED` decide whether the subsystem
  exists at all), `PHLIX_ENV`, relay/hub wiring (`PHLIX_RELAY_*`,
  `PHLIX_HUB_*`, `PHLIX_TLS_ENABLED`), DLNA `enabled`/`cds_enabled` (the
  `:1900` listener and SSDP advertisement are built at boot).
- **Config-load paths:** the job-queue dirs (`PHLIX_JOB_QUEUE_ROOT`,
  `PHLIX_*_JOBS_DIR`) — a daemon and a standalone worker started at different
  times with different values will not share a queue until both restart.
- **Rate-limit profiles:** `RATE_LIMIT_*` and `TRUSTED_PROXIES` semantics are
  frozen by `RateLimitProfiles` pre-flight in `start.php`.

Everything else (metadata keys like `metadata.*`, `PHLIX_SIGNED_URL_*`,
`PHLIX_CORS_ALLOWED_ORIGINS`, `TRUSTED_PROXIES` — rebuilt per request — and
the plugin gates read at each composer/install invocation) is resolved at
runtime and applies to the next request/job without a restart. The two
per-process memos (`PHLIX_SSRF_ALLOW_CIDRS`, `PHLIX_LIBRARY_ROOTS`) apply on
the next worker boot rather than the next request.

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

> **No one-time cleanup needed for `media_items` any more (S152).**
> `migrations/096_path_hash_unique_index.sql` adds
> `UNIQUE KEY idx_media_items_library_path_hash (library_id, path_hash)` as part of the
> ordinary migration chain, so a fresh install gets the path-dedupe constraint from
> `php bin/phlix migrate` alone. Before it, migration 072 deferred the index to
> `migrations/cleanup_072.php` and migration 087 dropped it, so an install nobody
> hand-finalized had **no** duplicate-path protection — and every scanner path lookup
> lost the `const` plan. The migration is idempotent and refuses to touch a table that
> still holds duplicates, failing instead with a message that names the remedy: run
> `php migrations/cleanup_072.php` once to merge them, then re-run migrations. That
> script now owns **de-duplication only**.

> **No one-time cleanup needed for `playback_state` any more (S156).**
> `migrations/097_playback_state_unique_key.sql` adds
> `UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)` as part of the
> ordinary migration chain, so a fresh install gets the progress-upsert constraint from
> `php bin/phlix migrate` (or `php scripts/run-migrations.php`) alone. Before it,
> migration 090 (S29, updates.md #29) reserved the number but carried **no executable
> statement at all** and deferred the key to the manual `migrations/cleanup_090.php`,
> which neither `scripts/install.sh` nor the Docker entrypoint nor either migrate command
> ever calls — so an install nobody hand-finalized had **no**
> `(session_id, media_item_id)` constraint, the `ON DUPLICATE KEY UPDATE` in
> `PlaybackController::reportProgress()` / `StreamManager::persistStreamState()` could
> never fire, and every ~15 s progress tick inserted a new row instead of updating one
> (finished episodes never left Continue Watching and `playback_state` grew without
> bound). The migration is idempotent and refuses to touch a table that still holds
> duplicate rows, failing instead with a message that names the remedy: run
> `php migrations/cleanup_090.php` once to merge them, then re-run migrations. That
> script now owns **de-duplication only** — it is needed only on an existing install that
> accumulated duplicate rows while the key was missing, never on a fresh one.

> **Migration AND rescan required on deploy (S331).** Music albums with no artist
> tag are no longer discarded whole — they are ingested under a structural
> placeholder artist whose display label is `[Unknown Artist]`.
> `migrations/102_music_artists_placeholder_flag.sql` adds
> `music_artists.is_placeholder` and widens `uk_name` to `(name, is_placeholder)`,
> so a REAL album genuinely tagged `[Unknown Artist]` stays a distinct artist
> (`is_placeholder = 0`) from the untagged bucket (`is_placeholder = 1`). Run
> `php bin/phlix migrate` (or `php scripts/run-migrations.php`) after pulling —
> migrations are idempotent, so re-running is safe. ⚠ **A scan (or rescan) of each
> music library is ALSO required**: albums the old policy discarded were never
> written to the database, and no backfill can reconstruct them without re-reading
> the audio files — the migration itself only adds the column and touches no rows.
> The scan summary key `skipped_no_artist` is renamed `placeholder_artist_files`
> (untagged albums are now indexed, not skipped).

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
| POST | `/api/v1/sessions` | Create a device session — body `{device_id: string (required), device_name?, device_type?}` → `201 {session_id}`; `400` missing `device_id`, `401` unauthenticated |
| GET | `/api/v1/me/sessions` | List the authenticated user's sessions |
| DELETE | `/api/v1/sessions/{id}` | End a session |
| POST | `/api/v1/sessions/{id}/progress` | Report playback progress |
| GET | `/api/v1/sessions/{id}/progress` | Get playback state |
| POST | `/api/v1/sessions/{id}/complete` | Signal that playback finished — body `{media_item_id: string (required), reached_end?: bool = true}`; `reached_end:true` marks the item watched (leaves Continue Watching, finalizes watch-time/stats), `false` clears the resume point. Auth mirrors `/progress` (session owner). `→ {message, reached_end}`; `404` unknown session, `403` wrong owner, `400` missing `media_item_id`. The web SPA player + mini-player POST this on the media `ended` event; native clients do not call it yet |
| GET | `/api/v1/users/me/continue-watching` (also `/api/v1/me/continue-watching`) | Continue-Watching list for the authenticated user — `{items:[...]}` of `MediaItemShaper`-shaped rows with top-level `id` (= media item id, for detail navigation), `poster_url`/`poster_srcset` (series poster for episodes, falling back season → own), `runtime` (minutes), `year`, `rating`, plus re-attached `position_ticks`/`duration_ticks` (SPA resume) and preserved `media_item_id`, `parent_id`, `metadata` (console/rating-gate compatibility) |
| GET | `/api/v1/users/me/next-up` | "Next Up" rail — for each series the active profile has STARTED, the single next unwatched episode to play (sibling to Continue Watching). Optional `?limit=` (default 20, clamped 1-50; the server scans up to `max(limit×3, 50)` started series). Auth required (`AuthMiddleware`). Returns `{items:[...]}` of `MediaItemShaper`-shaped episode rows (series poster resolved) with `position_ticks`/`duration_ticks`=0 plus added `series_id`/`series_name`; `media_item_id` carried for the rating-gate post-filter. Watched/in-progress signal is `playback_state` ONLY (not `user_item_data.watched` / `watch_history`); only numbered seasons are walked (Specials excluded); all-watched series yield no entry. `401` unauth, `503` if watch-history unwired, `{items:[]}` when no active profile |
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
| GET | `/auth/oidc/authorize` · `/auth/github/authorize` | Start an external login (**unauthenticated**). Redirects to the IdP / GitHub with PKCE + `state`, sets the browser-binding correlation cookie, and stores the resolved absolute `redirect_uri` in the server-side state. `503 provider_not_configured` when the provider is not enabled+configured; `503 callback_url_not_configured` when no absolute callback URL can be resolved (see `PHLIX_DOMAIN`) |
| GET | `/auth/oidc/callback` · `/auth/github/callback` | Provider redirect target (**unauthenticated**). One-shot `state` consume, correlation-cookie check (`403` on mismatch), token exchange with the *same* `redirect_uri` replayed from state, then session cookies + a `302` to the same-origin `redirect_uri`. Also handles the account-link branch |
| GET | `/auth/identities` | List the current user's linked external identities — auth required |
| GET | `/auth/identities/link/oidc` · `/auth/identities/link/github` | Start a verified OAuth link onto the current account — auth required |
| POST | `/auth/identities/link/ldap` | Link an LDAP identity via a live bind (body `{username, password}`) — auth required |
| DELETE | `/auth/identities/{id}` | Unlink one of the current user's identities (`404` if not yours, `409 last_sign_in_method` if it is your last) — auth required |
| GET | `/api/v1/admin/auth-providers` | List registered auth providers — admin-only |
| POST | `/api/v1/admin/auth-providers/{name}/enable` \| `/disable` | Enable/disable `oidc`, `ldap` or `github` (`409 not_configured` if it has no saved config) — admin-only |
| GET/POST | `/api/v1/admin/auth-providers/oidc/config` | Read / save OIDC config (`provider_url`, `client_id`, `client_secret` write-only, `scopes`, `redirect_uri`). An absent optional key is **preserved**, not cleared — admin-only |
| GET/POST | `/api/v1/admin/auth-providers/ldap/config` | Read / save LDAP config; `POST .../ldap/test` dry-runs a bind — admin-only |
| GET/POST | `/api/v1/admin/auth-providers/github/config` | Read / save GitHub config (`client_id`, `client_secret` write-only, `scopes`, `redirect_uri`); `configured` means id **and** secret are present. An absent optional key is **preserved** — admin-only |
| GET | `/api/v1/admin/auth-providers/{oidc,ldap,github}/schema` | JSON schema for the provider's config form — admin-only |

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

**Real-database integration tests self-skip.** Tests under `tests/Integration/`
that need a live MySQL probe the configured host first and skip when it is
unreachable, so `./vendor/bin/phpunit` is green on a machine with no database —
it just reports more skips. To actually exercise them, point the suite at a
throwaway MySQL that has had `php scripts/run-migrations.php` applied:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=phlix_test DB_USER=root DB_PASSWORD=root \
  ./vendor/bin/phpunit --no-coverage
```

CI does exactly this (`.github/workflows/phpunit.yml` provisions a `mysql:8.0`
service and runs the migrations before the suite), so these tests are not
decorative. Note that the network-dependent group is excluded by `phpunit.xml`
on purpose: `Phlix\Plugins\OAuth2\OAuth2HttpClient` (the live OAuth2 transport)
is driven by a fake in every provider test and is effectively uncovered — closing
that gap needs a live HTTP fixture.

#### Comparing skip sets between two runs — never compare the COUNT

`phpunit.xml` sets `displayDetailsOnSkippedTests="true"`, so every run of this suite
that loads that file **and uses PHPUnit's default result printer** — CI, local,
`--testsuite Unit`, `syncplay-e2e.yml` — prints each skipped test BY NAME. Use the
names, not the number:

```bash
./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/before.txt
# ...make your change...
./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/after.txt
comm -3 /tmp/before.txt /tmp/after.txt   # Left column: tests that dropped out of the skip set. Right column: tests that joined it.
```

Against a CI run (the script strips `gh`'s `job<TAB>step<TAB>timestamp ` prefix for you):

```bash
gh run view <run-id> --log | scripts/skipped-test-names.sh > /tmp/ci.txt
```

That is also how the mechanism is checked after a merge: master's own pre-S345 log exits
**4** on this command (measured on run `32027697809`), so an exit of **0** with a non-empty
file is the before/after demonstration. The exit code is the whole contract — an empty set
is never handed over silently:

| exit | meaning | what to do |
| --- | --- | --- |
| **0** | every run in the input passed the contract; stdout is the sorted set (zero lines is a valid answer for a run that skipped nothing) | compare it |
| **2** | the input is not PHPUnit output at all — no result summary line anywhere — or a run opened by a banner never completed | check what you piped in |
| **3** | some run's numbers do not add up: a run printed no list, or PHPUnit's list/entry format drifted | do **not** compare; read the denominators on stderr |
| **4** | some run counted skips, printed no detail list, and testdox does not explain them | restore `displayDetailsOnSkippedTests="true"`, or find the invocation that did not load `phpunit.xml` |
| **5** | some run's OWN testdox skip glyphs (` ↩ `) prove it is a `--testdox` run and account for all its unnamed skips (the glyph-less suite skip included), or a run ended `No tests executed!` with nothing named | re-run without `--testdox`; **not** a `phpunit.xml` fault |
| **6** | the sorted set could not be written in full (`sort`, or your redirect, failed) | stdout is truncated; do **not** compare |

Denominators always go to stderr, on every exit, so a set can be checked against the count
PHPUnit itself printed. The script's header enumerates the same six codes with the exact
input class that reaches each one.

The evaluation is **per run**, not per input: a multi-run log — the normal
`gh run view <id> --log` shape — is segmented at PHPUnit's banners and terminating
summary lines, and each run's arithmetic is judged on its own denominators. When any
run fails, every failing run is named on stderr (ordinal, banner, that run's own
numbers), no set is written, and the first failing run's exit code wins; the
input-wide tallies still printed on stderr are audit-only and decide nothing. The old
failure mode — one run's shortfall paid for by another run's surplus, netting to a
code right for the sum and wrong for both runs — is closed, so a refusal now points
at a specific run instead of the input as a whole.

⚠ **`--testdox` is the one invocation this cannot cover, and it is a PHPUnit
limitation, not a config mistake.** PHPUnit constructs the default result printer for
a testdox run with its `$displayDetailsOnSkippedTests` argument hardcoded `false`
(the `outputIsTestDox()` branch of `Facade::createResultPrinter()` — the configuration
value is not consulted there; `vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221`
in the pinned PHPUnit 10.5.64, which is the citation the script's own error message
prints), so a `--testdox` run adds to `Skipped: N` and can never
name those skips, with or without `--display-skipped`. No workflow passes `--testdox`
any more (`syncplay-e2e.yml` dropped it in S345 for exactly this reason). If you add it
back, or use it locally, the script exits **5** and says so — do **not** "fix"
`phpunit.xml` in response; re-run without `--testdox`. Exit 5 is decided inside the
run whose OWN glyphs prove it is a testdox run: any ` ↩ ` glyph that does not surplus
the run's shortfall marks the run, and because the attribution uses the run's own
`Skipped: N` rather than the glyph count, a SINGLE glyph is enough to carry the whole
shortfall to exit 5 — the glyph-less suite skip (a `testSuiteSkipped` event produces
no glyph) is accounted for exactly this way. Only a SURPLUS — more glyphs than the
run's shortfall, which no real testdox run can produce because PHPUnit counts every
glyph it prints in the same run's `Skipped: N` — attributes nothing to testdox: both
causes are then named and `phpunit.xml` is not exonerated. A surplus can only come
from other output sharing the log, which `gh run view --log` makes routine. Stray
glyphs are attributed to NO run only when they fall outside every run's segment —
before any PHPUnit banner, or after a run's terminating summary line, since the
segment is flushed and closed at that line — so those can never reach exit 5 whatever
their count. A stray glyph that lands INSIDE a run's segment is attributed to that
run and can carry it to exit 5, because it is arithmetically identical to the genuine
mixed testdox shape above. Exit 5 is also
taken for a run that ends `No tests executed!` with **nothing named**: a whole-suite
skip under `--testdox` prints exactly that (the suite skip is invisible), so a
quietly-empty set would be a lie. The message says to verify the invocation, because
a genuinely empty run (a typo'd `--filter`, an empty directory) prints a
byte-identical shape and is refused the same way — see the KNOWN LIMITS note below.

⚠ **The second exception is the `assertion-escape-probe` job.** It runs PHPUnit from
`scripts/assertion-escape-audit.php`, which captures the output into a PHP variable and
only tests it for a marker — it never echoes it. So no log line and no name set is
obtainable from that job, whatever `phpunit.xml` says. Nothing is wrong with its config;
its output simply never leaves the process. Those are the only two exceptions: PHPUnit's
`--teamcity` printer still emits the skipped-details list, and was checked.

Skipped test **suites** (a class-level `#[Requires*]`, or `markTestSkipped()` in
`setUpBeforeClass()`) are counted in the same `Skipped: N` but named under a different
header, and a run in which every suite skipped prints `No tests executed!` with no
`Tests:` line at all. The script parses that shape too and emits such an entry as
`SUITE:<name>`.

**Why names and not `Skipped: N`.** The number drifts between runs on its own — this
repo has legitimate environment-dependent skips, and this box reports ~259 where CI
reports 3 — and a moved count cannot distinguish *"a test started skipping"* from
*"a conditionally-skipped test turned into a failure"* from *"a test disappeared"*.
All three move it identically, and two of the three can leave it **unmoved**. `comm`
over the name set separates them; the count cannot. `scripts/skipped-test-names.sh`
writes only the sorted set to stdout (so `comm`/`diff` can eat it directly), puts its
denominators on stderr, and **fails loudly** rather than printing an empty set — with
one of the exit codes in the table above. Each code names the cause its own
arithmetic supports, and never attributes more to a cause than that cause can carry — a
wrong diagnosis would send the next reader to fix the wrong thing, and rounds 2 and 3 of
the S345 review plus its test engineer each caught one doing exactly that before it
shipped (the last was a clamp that trimmed a surplus of testdox glyphs down to the
shortfall; it is removed rather than re-tuned, because a surplus cannot come from a real
testdox run at all).

⚠ That is **not** a guarantee that the code is right for every input — the script's
header carries a numbered KNOWN LIMITS block, re-derived for per-run evaluation, and
this section is its summary. Two of the old input-wide limits are closed by the
segmentation and are no longer true: a shortfall in one run can no longer be paid for
by another run's surplus (the multi-run refusal above replaces the netting), and N
stray glyphs beside a run that lost N names no longer read as one testdox run that
skipped N (exit 5 needs the run's OWN glyphs). What remains, all measured on this
version:

- **A run that genuinely executed nothing is refused too.** A typo'd `--filter` or an
  empty directory prints a byte-identical `No tests executed!` to the invisible
  suite-skip shape, so it exits **5** with no set written and a message that says to
  verify the invocation — the right refusal for a shape that could otherwise hand
  over a silently-empty set.
- **The input is assumed to be CONCATENATED runs**, as `./vendor/bin/phpunit 2>&1` and
  a single job's `gh run view --log` produce. `gh run view --log` strips its
  `job<TAB>step<TAB>timestamp ` prefix but does **not** group the lines by job: when
  two jobs' PHPUnit runs are INTERLEAVED in one log (parallel jobs), the
  banner-delimited segmentation splits at the wrong boundaries and exits **2** naming
  run 1 about input that is complete — the right refusal, wrong cause. The documented
  recipe is safe when a workflow's PHPUnit invocations are sequential steps of ONE
  job; for parallel jobs, pipe each job's log separately
  (`gh run view --log --job <job-id>`).
- **A data-set label containing a literal newline** is emitted truncated at that
  newline: the entry spans two lines, the denominators still agree, and this is the
  one shape that escapes the cross-check. No data provider here produces one and the
  truncation is stable across runs, so `comm` is not corrupted by it — but the
  denominator argument does not cover it.
- **If a future PHPUnit renames the list header itself** (`There was 1 skipped test:`),
  the refusal is still loud and nothing is written, but it arrives as exit **4**
  blaming `phpunit.xml` rather than as exit 3 blaming the parser — the right refusal
  with the wrong cause.

The script's header carries all six limits (three closed by S352, three still open,
plus the refusal side-effect of limit 2 — the genuinely-empty run shape, listed
first above), numbered, each with the input that reaches it, and enumerates every
exit path with its input class; that header is the canonical statement of this
contract, and this section is its summary.

**Keep the suite hermetic w.r.t. the environment.** `PHLIX_DOMAIN` changes real
behaviour (it is the OAuth callback-URL allowlist — see
[Environment variables](#environment-variables)), so every test that depends on
it sets it in `setUp()` and restores the ambient value in `tearDown()`, and the
"no allowlist" cases pass `''` explicitly instead of relying on the env being
unset. Running the whole suite with and without `PHLIX_DOMAIN` exported yields
identical per-test assertion counts; if you add a test that reads an env var,
restore it.

### Code Standards

This project follows PSR-12 coding standards and uses static analysis tools:

```bash
# Check code style
./vendor/bin/phpcs --standard=PSR12 src/

# Run static analysis
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/psalm --show-info=false
```

Both are CI gates (`.github/workflows/coding-standards.yml`). Psalm is configured
by `psalm.xml` at `errorLevel="5"`; there is **no** `psalm-baseline.xml` and the
workflow fails if one appears. `--show-info=false` matches what CI runs — without
it Psalm additionally prints ~1400 informational suggestions that are not gated.

**Psalm needs PHP >= 8.3.16.** Psalm 6.x aborts at startup on anything older
(`Psalm requires a PHP version ">= 8.3.16"`), which includes the 8.3.6 that some
dev boxes ship. CI is unaffected — `shivammathur/setup-php` with `php-version:
'8.3'` installs the newest 8.3 patch. If your local PHP is too old, run it in a
container instead of upgrading the box:

```bash
docker build -t phlix-psalm - <<'EOF'
FROM php:8.3-cli
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions mysqli pdo_mysql gd zip fileinfo sockets pcntl posix ldap ffi curl swoole
EOF

docker run --rm --network host -v "$PWD:/app" -w /app -u "$(id -u):$(id -g)" -e HOME=/tmp \
  phlix-psalm ./vendor/bin/psalm --show-info=false --no-progress
```

The extension list is not optional and mirrors the `extensions:` list in the
Psalm CI job: Psalm resolves classes and constants from the extensions that are
actually loaded, so a bare `php:8.3-cli` reports 140 phantom errors
(`Swoole\Coroutine\Channel`, `Socket`, `AF_INET`, `ZipArchive`, `GdImage`, `FFI`, …)
that do not exist on a properly configured runner.

### CLI

Administrative tasks are exposed through the `bin/phlix` command-line tool (built on `webman/console` / Symfony Console):

```bash
php bin/phlix list       # list every available command
php bin/phlix migrate    # apply migrations/*.sql against config/database.php
php bin/phlix media:dedupe-paths           # preview duplicate-path merges (dry-run)
php bin/phlix media:dedupe-paths --apply   # merge duplicate media items sharing a path
```

`php bin/phlix migrate` is the supported equivalent of `php scripts/run-migrations.php`. Both delegate to the same `Phlix\Common\Database\MigrationRunner` service. Re-running is safe. Applied files are recorded in a `schema_migrations` ledger and skipped while their checksum matches. When a file does re-run, its statements tolerate the replay: they use `IF NOT EXISTS` where MySQL supports it, and duplicate-column/duplicate-key errors are downgraded to notes. The downgrade is necessary because MySQL 8 has no `IF NOT EXISTS` on `ADD COLUMN`/`ADD INDEX`. More commands are added in later steps.

**Exit codes (S159).** Both commands exit `0` when every statement applied, or failed only with an idempotent "already applied" error. They exit `1` when at least one genuine, non-idempotent statement error was recorded. A failing run does **not** stop at the first error — later files are still attempted. But the failing file is deliberately left out of the ledger, so the next run retries it. Consequences worth knowing:

- `scripts/install.sh` runs under `set -euo pipefail`, so a failed migration **aborts** the install/update before the service is restarted.
- `docker/docker-entrypoint.sh` prints a loud `PHLIX-MIGRATION-FAILURE` banner to stderr and, by default, **still starts the container** (a crash-looping media server is worse than a degraded one). Set `PHLIX_MIGRATIONS_STRICT=1` to make the container refuse to start instead. It also refuses to start when migrations could not run at all (no database host configured, or `scripts/run-migrations.php` missing).
- `PHLIX_MIGRATIONS_DIR` overrides the directory that is scanned for `*.sql` (default `migrations/`). Honoured by **both** commands.

⚠ **"Idempotent" is a closed list, not "anything a replay raises".** The squelch is decided on the MySQL **error number**, never on the exception text. The driver prefixes the whole failing statement to its message, so matching a phrase like `already exists` would grep the migration's own SQL — an earlier version did, and it reclassified hard failures as successes. The idempotent set is exactly four errors:

1. `1050` — table already exists.
2. `1060` — duplicate column.
3. `1061` — duplicate key name.
4. `1091` — can't drop; check that the column/key exists.

Each names an object that can only be the one the failing statement was creating: a table in this schema, or a column/index, which are *table*-local.

Everything else fails the run, **including several shapes a re-run can legitimately produce** — write the migration so they never fire:

| Shape | MySQL | Write this instead |
|---|---|---|
| Seed row inserted twice | `1062` duplicate entry | `INSERT IGNORE` / `INSERT … ON DUPLICATE KEY UPDATE` |
| `ALTER TABLE … ADD PRIMARY KEY` replayed | `1068` multiple primary key defined | guard it, or add the PK in `CREATE TABLE` |
| `… CONSTRAINT <name> FOREIGN KEY` / `CHECK` replayed | `1826` / `3822` duplicate constraint name | guard the `ADD CONSTRAINT` (check `information_schema`, or `DROP … IF EXISTS` first) and give every constraint a schema-unique name |
| `DROP TABLE`/`CREATE TRIGGER`/`CREATE FUNCTION` replayed | `1051` / `1359` / `1304` | use the `IF EXISTS` / `IF NOT EXISTS` form those statements accept |

`1062` in particular is deliberate: it is also what `ADD UNIQUE` raises when the *existing data* violates the new constraint, which is the most valuable failure a migration can report. `1068` names no object, so a replay and a genuine "a different primary key is already there" are indistinguishable.

`1826`/`3822` were **considered for the idempotent set and rejected** — do not add them. Foreign-key and CHECK-constraint names are unique **per schema**, not per table, so the object that already holds the name can belong to a *completely different* table, and the statement that failed can be a `CREATE TABLE` that consequently created **nothing**. Squelching them let such a file exit 0 *and* enter `schema_migrations`, so it was never retried — worse than no squelch at all.

**Exit codes: `library:scan` (S347).** `php bin/phlix library:scan <libraryId>` (with or
without `--rescan`) returns one of:

| exit | meaning |
| --- | --- |
| **0** | scan (or rescan) completed; every file was indexed |
| **1** | scan did not run — unknown library id, the manager threw, or an already-queued/running job refused the scan |
| **2** | Symfony's `INVALID` (invalid input/usage) — deliberately **NOT** used, so "you typed the arguments wrong" and "your library lost files" never share a number |
| **3** | scan completed but N file(s) could not be indexed (the lossy warning is on stderr; each file is named in `.logs/error.log`) |

The same table is printed by `php bin/phlix library:scan --help`. `3` (not `2`) is the
"completed but lost files" code because `2` is Symfony's reserved invalid-input code — a
wrapper must be able to tell a scan that never ran from one that lost files.

### Admin UI (part of the `/app` SPA)

There is **no separate admin app**. An earlier React + Vite admin console (`admin-ui/`, served at
`/admin` by `AdminAppController`, built into `public/assets/admin/`, gated by an `admin-ui.yml`
workflow) was **decommissioned in this repo**: the app was removed in `6d1f43fe` (2026-06-01,
"feat(C8.1): decommission React admin") and its workflow replaced on 2026-06-26 by the **Web UI**
workflow (`7caad461`, now `.github/workflows/web-ui.yml`, building `web-ui/`). Nothing named
`admin-ui/` ships in this repository today.

The admin console is the `/app/admin/*` section of the same `@phlix/ui` Vue SPA described under
[Web Portal](#web-portal) — source in `web-ui/`, bundle served from `public/assets/app/`, routes
registered in `web-ui/src/main.ts` (the shell's **Admin** entry renders only for
`useAuthStore().isAdmin`; the admin API itself is gated server-side regardless). The surviving
legacy page paths `/admin/plugins*` and `/admin/dashboard` 302-redirect to their `/app/admin/*`
equivalents in `src/Server/Workerman/HttpHandler.php`.

Full operator + contributor docs live on the
[phlix-docs](https://detain.github.io/phlix-docs) site — see in particular
[admin/server-settings](https://detain.github.io/phlix-docs/admin/server-settings) and
[admin/library-management](https://detain.github.io/phlix-docs/admin/library-management).

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

[Mozilla Public License 2.0](LICENSE) — see [`LICENSE`](LICENSE) for the full text.

MPL-2.0 is **per-file weak copyleft**. In short:

- You can use Phlix Server in a larger project, including a commercial or
  closed-source one.
- If you **modify** a file that is part of Phlix Server, you must make that
  file's source available under the MPL-2.0.
- New files you add alongside it can be licensed however you like.

"Phlix" is a trademark — see [`TRADEMARK.md`](TRADEMARK.md) for what that does
and doesn't restrict.

### Third-party components

Phlix Server depends on two LGPL-3.0 libraries — [Smarty](https://www.smarty.net/)
(`smarty/smarty`) and [Tukio](https://github.com/Crell/Tukio) (`crell/tukio`) — plus
a number of MIT-licensed packages. MPL-2.0 lists the GPL family as Secondary
Licenses (MPL-2.0 §1.12, §3.3), so those are compatible. If you redistribute
Phlix Server, you must still meet each dependency's own terms; `composer licenses`
lists them.

## Support

For issues and feature requests, please use the GitHub issue tracker.

---

For detailed development documentation, see the
[phlix-docs](https://detain.github.io/phlix-docs) site — the in-tree `DEVELOPER.md` and the
`docs/archive/` plan set were retired into it (`77e681fd`, "Remove docs directory after
phlix-docs merge").

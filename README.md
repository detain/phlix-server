# Phlix Media Server

[![PHPUnit](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml)
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
- **HLS Streaming**: Adaptive bitrate streaming for web clients with multi-quality playlists
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
│   └── WebPortal/       # Web portal (HTML UI)
│       ├── WebPortalRouter.php # REST API for portal
│       └── PageRenderer.php    # Smarty template rendering
├── Session/            # Playback session management
├── Media/              # Media library and metadata
│   ├── Library/        # Library management (LibraryManager, ItemRepository, MediaScanner)
│   ├── Metadata/      # Metadata fetching (TMDB, TVDB, Fanart, NFO providers)
│   ├── Transcoding/    # FFmpeg transcoding with EncodingHelper
│   └── Streaming/      # HLS streaming with adaptive bitrate
├── Auth/               # Authentication services
└── Common/             # Shared utilities

public/
├── index.php           # Web portal entry point
├── templates/          # Smarty templates
└── assets/             # Static assets (css, js)
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
  `Phlix\Shared\Events\*`, `Phlix\Shared\Auth\JwtClaims`, and
  `Phlix\Shared\Hub\*` DTOs live there since `phlix-server` 0.11.0.

### Web Portal
- **Smarty-based Templates**: Server-side rendered HTML pages using Smarty
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
DB_PASSWORD=your_strong_password php scripts/run-migrations.php

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

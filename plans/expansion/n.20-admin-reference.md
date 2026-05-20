# Step N.20 — Admin Reference Guide

**Phase:** N (End-User Documentation)
**Step:** N.20
**Depends on:** N.18 (backup/restore — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: /home/sites/phlex/)
**Estimated subagent type:** scribe (fallback: general-purpose)

## 1. Goal

Write the consolidated admin reference guide at `docs/reference/admin-reference.md`
that triply covers environment variables, CLI commands, and config files — linking
out to the existing detail pages (`env-vars.md`, `cli.md`) for deep-dives.
Follows the §7 layout: TL;DR → shell/config blocks → what-can-go-wrong
(3 failures) → next-steps.

Existing partial pages:

- `docs/reference/env-vars.md` — already complete with most variables
  (§3 Scope says extend, not replace).
- `docs/reference/cli.md` — documents `scripts/pair-with-hub.php` and
  `scripts/port-forward.php`; needs extension with `bin/phlex` commands and
  §7 layout polish.

## 2. Context (what already exists)

### Environment variables (§3.1)

`docs/reference/env-vars.md` already covers:

- `PHLEX_CONTAINER_COMPILE`
- `PHLEX_DEBUG_EVENTS`
- `PHLEX_PLUGINS_ALLOW_HTTP`
- `PHLEX_PLUGINS_REQUIRE_SIGNATURE`
- `PHLEX_PLUGINS_COMPOSER_TIMEOUT`
- `JWT_SECRET`
- `PHLEX_HUB_URL`, `PHLEX_HUB_JWKS_URL`, `PHLEX_HUB_ENROLLMENT_TOKEN`
- `PHLEX_HUB_HEARTBEAT_INTERVAL`, `PHLEX_SUBDOMAIN_AUTO_CLAIM`
- `PHLEX_TLS_ENABLED`, `PHLEX_DOMAIN`
- `PHLEX_RELAY_ENABLED`, `PHLEX_RELAY_HUB_URL`, `PHLEX_RELAY_TUNNEL_HOSTNAME`,
  `PHLEX_RELAY_RECONNECT_DELAY`, `PHLEX_RELAY_PING_INTERVAL`, `PHLEX_RELAY_PING_TIMEOUT`
- `PHLEX_PORT_FORWARD_AUTO`, `PHLEX_EXTERNAL_PORT`, `PHLEX_EXTERNAL_HTTP_PORT`,
  `PHLEX_EXTERNAL_HTTPS_PORT`, `PHLEX_UPNP_ENABLED`, `PHLEX_STUN_SERVER`, `PHLEX_STUN_PORT`
- Database test vars (`APP_ENV`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USER`, `DB_PASSWORD`)
  used only by `phpunit.xml`.

**Missing from env-vars.md** (extend in §4.1):

| Variable | Default | Description |
| --- | --- | --- |
| `PHLEX_HTTP_PORT` | `32400` | HTTP port the server listens on. Overridden by `config/server.php` `server.port` at runtime. |
| `PHLEX_DATABASE_HOST` | `127.0.0.1` | MySQL host; maps to `config/database.php` `connections.mysql.host`. |
| `PHLEX_DATABASE_PORT` | `3306` | MySQL port; maps to `connections.mysql.port`. |
| `PHLEX_DATABASE_NAME` | `phlex` | Database name; maps to `connections.mysql.database`. |
| `PHLEX_DATABASE_USER` | `phlex` | Database username; maps to `connections.mysql.username`. |
| `PHLEX_DATABASE_PASSWORD` | _(empty)_ | Database password; the existing `DB_PASSWORD` env var is consumed by `config/database.php` directly. `PHLEX_DATABASE_PASSWORD` is documented as an alias. |
| `PHLEX_LOG_LEVEL` | `info` | Minimum log level: `debug`, `info`, `warning`, `error`. Controls `.logs/app.log` verbosity. |
| `PHLEX_HWACCEL` | `none` | Preferred hardware acceleration: `nvidia`, `vaapi`, `videotoolbox`, `qsv`, `amf`, `v4l2`, `none`. Overridden by `config/ffmpeg.php` `hwaccel.vendor_priority`. |
| `PHLEX_PUBLIC_URL` | _(unset)_ | Public URL used in hub relay and DLNA announcements. |
| `TZ` | system TZ | PHP `date_default_timezone_set()` value; controls timestamps in logs and EPG. |

### CLI commands (§3.2)

`docs/reference/cli.md` already documents:

- `php scripts/pair-with-hub.php <hub-url> <server-name>` — hub pairing
- `php scripts/port-forward.php <command>` — port forwarding (status/enable/disable/info)

**Missing / to-add** (extend in §4.2):

| Command | Description |
| --- | --- |
| `php public/index.php` | Start the Phlex server (Workerman worker). Blocking; run under systemd/supervisord. |
| `php bin/phlex backup:create --output <path>` | Create a backup archive (DB + config + metadata). See N.18 backup guide. |
| `php bin/phlex backup:restore --input <path>` | Restore from a backup archive. See N.18 restore procedure. |
| `php bin/phlex library:scan --library <id>` | Trigger a full rescan of a specific library by UUID. |
| `php bin/phlex library:scan --all` | Trigger a full rescan of all libraries. |
| `php bin/phlex user:reset-password <email>` | Reset a user's password interactively (prompts for new password). |
| `php bin/phlex hwaccel:probe` | Probe and print detected hardware acceleration (NVENC, VAAPI, VideoToolbox, QSV). Exit 0 if hardware found, non-zero otherwise. |
| `php bin/phlex log:tail --channel=<auth\|http\|media\|session\|streaming>` | Tail the rotating log file for a specific channel. Ctrl+C to stop. |
| `php bin/phlex plugin:install --url <url>` | Install a plugin from a `plugin.json` URL. Plugin lands disabled. |
| `php bin/phlex plugin:enable <name>` | Enable an installed plugin by name. |
| `php bin/phlex plugin:disable <name>` | Disable a plugin by name. |
| `php bin/phlex plugin:uninstall <name>` | Uninstall a plugin (removes files and DB row). |
| `php bin/phlex plugin:list` | List all installed plugins with name, version, enabled state. |
| `php bin/phlex hub:claim --code <code> --hub <url>` | Claim this server to a hub using a claim code from the hub web portal. |
| `php bin/phlex migrate` | Run pending database migrations. Idempotent; safe to run multiple times. |
| `php bin/phlex status` | Show server version, uptime, worker count, and health status. |

**Note:** `bin/phlex` does not yet exist as a file in the repo. The CLI
structure above is **spec-first** — the plan author records the canonical
commands the operator will eventually type; the implementor creates the underlying
commands in `src/Server/Cli/` and wires them to `bin/phlex` as a Symfony Console
application (or equivalent). The doc page uses the expected canonical form.

### Config files (§3.3)

All config files are plain PHP files that `include` returning an array.
No PHP-DI or environment-processing magic beyond `getenv()` calls.

| File | Purpose | Key fields |
| --- | --- | --- |
| `config/server.php` | HTTP server bind | `server.host` (`0.0.0.0`), `server.port` (default `8096`), `worker.count` (`auto`), `worker.stdout_file`, `worker.pid_file` |
| `config/database.php` | MySQL connection | `connections.mysql.host` (`127.0.0.1`), `port` (`3306`), `database` (`phlex`), `username` (`phlex`), `password` (`getenv('DB_PASSWORD')`), `charset` (`utf8mb4`), `pool_size`, `timeout` |
| `config/logger.php` | Log channels | `handlers` map with `type: rotating_file`, `path`, `max_files`, `level`; channels: `file` (app), `error`, `events` (debug-only), `plugins` |
| `config/ffmpeg.php` | Transcoding + hwaccel | `ffmpeg_path`, `ffprobe_path`, `transcode_dir`, `max_concurrent_transcodes` (`4`), `hwaccel.enabled`, `hwaccel.prefer_hardware`, `hwaccel.vendor_priority` (NVENC→VAAPI→QSV→VideoToolbox→AMF→V4L2), `hwaccel_profiles`, `subtitles`, `dash` |
| `config/hub.php` | Hub pairing + relay | `hub_url`, `hub_jwks_url`, `heartbeat_interval` (`60`), `enrollment_token_ttl`, `jwks_cache_ttl`, `key_path`, `config_dir`, `subdomain_auto_claim`, `tls_enabled`, `domain` (`phlex.media`) |

Additional config files to note (not core, but admin-relevant):

| File | Purpose |
| --- | --- |
| `config/backups.php` | Backup destination, retention, schedule (if configured). |
| `config/relay.php` | Relay tunnel WSS URL, ping interval/timeout. |
| `config/port-forward.php` | UPnP/IGD discovery settings, STUN server/port. |
| `config/hwaccel_profiles.php` | CRF values (`23`/`28`), codec selection (`libx264`/`libx265`) per quality tier. |
| `config/subtitles.php` | Subtitle extraction and burn-in settings. |

## 3. Scope — files to create / modify

### Create

- `docs/reference/admin-reference.md` — the consolidated guide (primary deliverable).

### Modify (extend only, do not rewrite)

- `docs/reference/env-vars.md` — add the 10 missing environment variables
  listed in §2 above (`PHLEX_HTTP_PORT` through `TZ`). Keep existing
  content intact; append new rows to the tables in their appropriate sections.

### No source changes

N.20 is doc-only. No `src/` changes.

## 4. Content outline

### `admin-reference.md` — TL;DR (≤5 lines)

One paragraph: this page is the single admin landing page for environment
variables, CLI commands, and config files — three pillars of server
operation. Links to the detailed `env-vars.md` and `cli.md` detail pages.
Three bullet points: env vars control startup, CLI controls operation, config
files control runtime behavior.

### §1 — Environment Variables

Short paragraph: env vars are read at container / process startup; most can
be overridden by `config/*.php` at runtime (noted per-var). For the full
table, see [`docs/reference/env-vars.md`](env-vars.md).

Summarized bullets for the 5 most operationally critical vars:
- `JWT_SECRET` — change this in production; default is insecure dev-only.
- `PHLEX_HTTP_PORT` — port the server binds (default 32400).
- `PHLEX_DATABASE_*` — MySQL connection; credentials live here, not in config files.
- `TZ` — timestamps in logs and EPG depend on correct timezone.
- `PHLEX_LOG_LEVEL` — `debug` is verbose; `error` is production-minimal.

### §2 — CLI Commands

Short paragraph: all commands run from the Phlex install directory.
`php public/index.php` starts the server (blocking — use systemd/supervisord
in production). `bin/phlex` is the management CLI for ongoing operations.
For hub pairing and port forwarding scripts, see [`docs/reference/cli.md`](cli.md).

Two tables:

**Server lifecycle:**

| Command | Description |
| --- | --- |
| `php public/index.php` | Start the Workerman HTTP server. Run under systemd/supervisord, not directly in a shell session. |
| `php bin/phlex status` | Show version, uptime, worker count, health. |
| `php bin/phlex migrate` | Run pending DB migrations. Idempotent. |

**Operational:**

| Command | Description |
| --- | --- |
| `php bin/phlex backup:create --output <path>` | Create a backup. See [backup guide](../advanced/backup-restore.md). |
| `php bin/phlex backup:restore --input <path>` | Restore from backup. See [restore guide](../advanced/backup-restore.md). |
| `php bin/phlex library:scan --library <id>` | Rescan a specific library. |
| `php bin/phlex library:scan --all` | Rescan all libraries. |
| `php bin/phlex user:reset-password <email>` | Interactively reset a user's password. |
| `php bin/phlex hwaccel:probe` | Print detected hardware acceleration; exit 0 if found. |
| `php bin/phlex log:tail --channel=<name>` | Tail rotating log for a channel. Channels: `auth`, `http`, `media`, `session`, `streaming`, `plugins`. |
| `php bin/phlex plugin:install --url <url>` | Install from `plugin.json` URL. Lands disabled. |
| `php bin/phlex plugin:enable <name>` | Enable an installed plugin. |
| `php bin/phlex plugin:disable <name>` | Disable a plugin. |
| `php bin/phlex plugin:uninstall <name>` | Remove plugin files and DB row. |
| `php bin/phlex plugin:list` | List installed plugins with version and enabled state. |
| `php bin/phlex hub:claim --code <code> --hub <url>` | Claim this server to a hub using a claim code. |

### §3 — Config Files

Short paragraph: all config files are plain PHP `return [...]` arrays.
They are included at boot time. Always validate a config file after editing:
`php -l config/<filename>.php`.

#### 3.1 `config/server.php`

```php
return [
    'server' => [
        'name' => 'Phlex Media Server',
        'host' => '0.0.0.0',      // bind address
        'port' => 8096,            // HTTP port (overridden by PHLEX_HTTP_PORT env var)
        'context' => [],
    ],
    'worker' => [
        'count' => 'auto',       // 'auto' or integer
        'stdout_file' => __DIR__ . '/../.logs/stdout.log',
        'pid_file' => '/var/run/phlex/pid',
    ],
    'process' => [
        'reloadable' => true,
        'reuse_port' => true,
    ],
];
```

Field descriptions:

- `server.host` — bind address. `0.0.0.0` = all interfaces; `127.0.0.1` = localhost only.
- `server.port` — HTTP port. Default `8096`. Use `PHLEX_HTTP_PORT` env var to override.
- `worker.count` — Workerman process count. `auto` = CPU core count.
- `worker.stdout_file` — Workerman master process stdout/stderr redirect.
- `worker.pid_file` — PID file path for `stop` signal.

#### 3.2 `config/database.php`

```php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => '127.0.0.1',      // PHLEX_DATABASE_HOST
            'port' => 3306,             // PHLEX_DATABASE_PORT
            'database' => 'phlex',       // PHLEX_DATABASE_NAME
            'username' => 'phlex',       // PHLEX_DATABASE_USER
            'password' => getenv('DB_PASSWORD') ?: '',  // PHLEX_DATABASE_PASSWORD
            'charset' => 'utf8mb4',
            'pool_size' => 20,
            'timeout' => 5,
        ],
    ],
];
```

Field descriptions:

- `connections.mysql.password` — read from `DB_PASSWORD` env var at runtime.
  Never commit credentials to this file in source control.
- `pool_size` — maximum concurrent DB connections per worker.
- `timeout` — query timeout in seconds.

#### 3.3 `config/logger.php`

```php
return [
    'default' => 'file',
    'handlers' => [
        'file' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/app.log',
            'max_files' => 30,
            'level' => 'debug',   // overridden by PHLEX_LOG_LEVEL env var
        ],
        'error' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
        'events' => [ /* ... debug-only, active when PHLEX_DEBUG_EVENTS=1 ... */ ],
        'plugins' => [ /* ... plugin lifecycle log ... */ ],
    ],
];
```

Log levels: `debug` → `info` → `warning` → `error`. Levels below the
configured `level` are discarded.

#### 3.4 `config/ffmpeg.php`

```php
return [
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'ffprobe_path' => '/usr/bin/ffprobe',
    'transcode_dir' => '/var/transcodes',
    'segment_dir' => '/var/segments',
    'max_concurrent_transcodes' => 4,
    'transcode_timeout' => 7200,
    'hwaccel' => [
        'enabled' => true,
        'prefer_hardware' => true,
        'vendor_priority' => [
            'nvenc'    => 0,  // NVIDIA NVENC (GPU)
            'vaapi'    => 1,  // Linux VAAPI
            'qsv'      => 2,  // Intel Quick Sync
            'videotoolbox' => 3, // macOS VideoToolbox
            'amf'      => 4,  // AMD AMF
            'v4l2'     => 5,  // Linux V4L2
        ],
    ],
    'hwaccel_profiles' => require __DIR__ . '/hwaccel_profiles.php',
    'subtitles'       => require __DIR__ . '/subtitles.php',
    'dash' => [
        'enabled' => true,
        'segment_dir' => '/var/segments',
        'default_codecs' => ['video' => 'avc1.64001f', 'audio' => 'mp4a.40.2'],
    ],
];
```

Field descriptions:

- `vendor_priority` — ordered list. First available in the list is used.
  Override with `PHLEX_HWACCEL` env var (e.g., `PHLEX_HWACCEL=vaapi`).
- `hwaccel_profiles.php` — per-quality-tier CRF (`23` for high, `28` for low)
  and codec (`libx264`/`libx265`).
- `max_concurrent_transcodes` — hardware encoder limit; set to `1` on
  low-end NAS devices.

#### 3.5 `config/hub.php`

```php
return [
    'hub_url' => getenv('PHLEX_HUB_URL') ?: null,
    'hub_jwks_url' => getenv('PHLEX_HUB_JWKS_URL') ?: null,
    'heartbeat_interval' => (int)(getenv('PHLEX_HUB_HEARTBEAT_INTERVAL') ?: 60),
    'enrollment_token_ttl' => 7 * 86400,
    'jwks_cache_ttl' => 900,
    'key_path' => __DIR__ . '/hub-server-key.pem',
    'config_dir' => __DIR__,
    'subdomain_auto_claim' => (bool)(getenv('PHLEX_SUBDOMAIN_AUTO_CLAIM') ?: true),
    'tls_enabled' => (bool)(getenv('PHLEX_TLS_ENABLED') ?: true),
    'domain' => getenv('PHLEX_DOMAIN') ?: 'phlex.media',
];
```

Field descriptions:

- `heartbeat_interval` — seconds between hub heartbeats. Range: 30–3600.
- `enrollment_token_ttl` — how long the hub enrollment JWT is valid.
- `subdomain_auto_claim` — automatically claim a `*.phlex.media` subdomain
  after hub enrollment (overridden by `PHLEX_SUBDOMAIN_AUTO_CLAIM` env var).
- `tls_enabled` — enable HTTPS on the allocated subdomain.

#### 3.6 Other config files

Brief bullets on `config/backups.php`, `config/relay.php`,
`config/port-forward.php` with one-line descriptions.

### §4 — What Can Go Wrong

#### Failure 1 — Boolean Env Var in Shell is a String

**Symptom:** `PHLEX_PLUGINS_ALLOW_HTTP=0` is treated as truthy, or
`PHLEX_CONTAINER_COMPILE=1` is ignored.

**Cause:** In shell, `PHLEX_PLUGINS_ALLOW_HTTP=0` passes the string `"0"`
to PHP's `getenv()`, which is truthy because it is a non-empty string.
The bool conversion in the consuming code `(bool)getenv('VAR')` sees
`"0"` → `true` (non-empty string), not `false`.

**Fix:** Always use numeric or literal strings in shell:
```bash
# WRONG
PHLEX_PLUGINS_ALLOW_HTTP=0 php public/index.php    # "0" is truthy in PHP

# CORRECT
PHLEX_PLUGINS_ALLOW_HTTP="" php public/index.php   # empty string = falsy
```
Or set the env var before the PHP process in the shell profile or systemd
unit file, not inline with the command.

#### Failure 2 — CLI Not on PATH

**Symptom:** `php bin/phlex: command not found` when running `php bin/phlex
hwaccel:probe`.

**Cause:** `bin/phlex` is not on the system `PATH`, or PHP itself is not
found. Some Linux distributions install PHP in non-standard locations
(`/usr/local/bin/php`, `/opt/php83/bin/php`).

**Fix:** Use the full path to PHP:
```bash
# Find PHP
which php    # or: ls /usr/bin/php* /usr/local/bin/php*

# Use full path
/usr/local/bin/php bin/phlex hwaccel:probe

# Or add to PATH permanently in ~/.bashrc or /etc/environment
export PATH="/usr/local/bin:$PATH"
```
Check the systemd unit file too — `Environment=PATH=/usr/sbin:/usr/bin:/usr/local/bin`
ensures the path is set in the service context.

#### Failure 3 — Config File PHP Parse Error

**Symptom:** Server fails to start with "Primary script unknown" or
Workerman exits immediately with no log output.

**Cause:** A syntax error in a config PHP file (missing semicolon,
unclosed bracket, array key typo). PHP parses config files at include time.

**Fix:** Always lint after editing:
```bash
php -l config/server.php
php -l config/database.php
php -l config/ffmpeg.php
php -l config/hub.php
php -l config/logger.php
```
A clean `No syntax errors detected` means the file is safe to include.
This is required before restarting the server after any config change.

#### Failure 4 — `JWT_SECRET` Default in Production

**Symptom:** Server starts but external clients cannot connect; JWT
validation errors in logs.

**Cause:** `JWT_SECRET` defaults to `default-secret-change-me` when not
set. The `JwtHandler` fails closed in production (verifies HMAC signature
strictly) — tokens signed with the default secret are rejected even though
they were created with the same default.

**Fix:** Set a strong secret in the environment:
```bash
# Generate a random 256-bit key
openssl rand -hex 32

# Add to systemd unit or environment file
Environment=JWT_SECRET=$(openssl rand -hex 32)
```
Restart the server after changing `JWT_SECRET`. Existing refresh tokens
will be invalidated — users must log in again.

### §5 — Next Steps

- [Environment variables detail](env-vars.md) — complete table of every env var.
- [CLI commands detail](cli.md) — hub pairing and port forwarding scripts.
- [Backup & restore](../advanced/backup-restore.md) — backup strategies and restore procedures.
- [Logs](../advanced/logs.md) — reading server logs and log channel reference.
- [Troubleshooting](../advanced/troubleshooting.md) — diagnosing startup and runtime issues.

## 5. Acceptance criteria

- [ ] File created at `docs/reference/admin-reference.md`
- [ ] TL;DR section present and concise (≤5 lines)
- [ ] §1 covers env vars with 5 critical-var bullets and link to `env-vars.md`
- [ ] §2 covers CLI with two tables (lifecycle + operational) with all listed commands
- [ ] §3 covers all 5 config files (`server.php`, `database.php`, `logger.php`, `ffmpeg.php`, `hub.php`) with annotated PHP blocks
- [ ] §3 includes the "always lint after editing" `php -l` callout
- [ ] §4 has exactly 3 named failures (env var string truthy, CLI PATH issue, config PHP parse error) plus bonus failure 4 (JWT_SECRET default)
- [ ] §5 links to `env-vars.md`, `cli.md`, `backup-restore.md`, `logs.md`, `troubleshooting.md`
- [ ] All PHP config blocks are syntactically correct
- [ ] All bash/shell blocks are syntactically correct
- [ ] `docs/reference/env-vars.md` extended with the 10 missing variables (no existing content removed)
- [ ] No new `TODO` or `FIXME` comments left in the file
- [ ] Verification commands run cleanly:
  ```bash
  ./vendor/bin/phpcs --standard=PSR12 src/
  ./vendor/bin/phpstan analyze src/ --level=9
  find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
  # Doc-only: markdownlint (not yet enforced)
  grep -E "^##? (TL;DR|What can go wrong|Next steps)" docs/reference/admin-reference.md
  ```

## 6. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.20-admin-reference

# ─── 2. Do the doc work ───

# ─── 3. Verify ───
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/phpstan analyze src/ --level=9
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.20: admin reference guide (env vars, CLI, config files)"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, merge, cleanup ───
gh pr create \
  --title "Step N.20: admin reference guide" \
  --body  "Writes docs/reference/admin-reference.md (consolidated env vars, CLI commands, and config files with §7 layout) and extends docs/reference/env-vars.md with 10 missing variables. No src/ changes. Implements step N.20 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                         # MUST be empty
git branch --show-current                  # MUST be 'master'
git log --oneline -1                       # MUST show the N.20 commit
git branch --list 'n.20-*'                # MUST be empty
```

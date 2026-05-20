# Phlix Media Server — Agent Guide

PHP 8.3+ media server on Workerman 5.x with HTTP REST, WebSocket, Smarty web portal, HLS streaming via FFmpeg, JWT auth, and DLNA/SyncPlay/LiveTV modules. Namespace `Phlix\` → `src/`, tests `Phlix\Tests\` → `tests/`.

## Commands

```bash
composer install
php scripts/run-migrations.php
php public/index.php
./vendor/bin/phpunit                        # Unit + Integration suites wired in phpunit.xml
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
./vendor/bin/phpunit tests/unit/Auth/JwtHandlerTest.php --testdox
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/phpstan analyze src/ --level=9
find src -name '*.php' -exec php -l {} \;
```

Coverage writes to `coverage.xml` + `coverage-report/` (configured in `phpunit.xml`).

## Architecture

**Entry**: `public/index.php` bootstraps `ConnectionPool::init()` + `LoggerFactory::init()`, builds `AuthManager`, `LibraryManager`, `SessionManager`, `PlaybackController`, then dispatches via `PageRenderer` or `/api/` JSON. Server worker entry is `src/Server/Core/Application.php` (`Workerman\Worker`).

**HTTP** (`src/Server/Http/`): `Request::fromGlobals()` → `Router::dispatch()` matches `{param}` placeholders → handler returns chained `Response`. Controllers live in `src/Server/Http/Controllers/` (`AuthController.php`, `LibraryController.php`, `MediaItemController.php`, `HlsController.php`, `SessionController.php`). Middleware via `$router->group($prefix, $cb, [$middleware])`.

**WebSocket** (`src/Server/WebSocket/`): `WebSocketServer.php` wraps `Workerman\Worker` on `websocket://`. `Connection.php` implements `ConnectionInterface.php`. `ConnectionPool.php` is singleton (`getInstance()`). `MessageHandler->on($event, $cb)` registers handlers; events listed in `Events.php` (`WebSocketEvents::PLAYBACK_*`, `SYNCPLAY_*`, `AUTH_*`).

**Auth** (`src/Auth/`): `JwtHandler.php` HS256, 1h access / 7d refresh, `iss=phlix`. `UserRepository.php` uses `password_hash(..., PASSWORD_ARGON2ID)`. `AuthManager.php` orchestrates register/login/refresh, calls `AuditLogger`. `UserProfileManager.php` enforces up to 5 profiles, PIN (4 or 6 digits, Argon2ID), rating filter (G/PG/PG-13/R/NC-17/X/UNRATED). `WatchHistory.php` tracks 90% completion threshold.

**Media** (`src/Media/`):
- `Library/`: `LibraryManager.php` · `MediaScanner.php` (parses `S01E02`, `(2020)`) · `FolderWatcher.php` (mtime checksum) · `ItemRepository.php` (hydrates `metadata_json`)
- `Metadata/`: `MetadataManager.php` priority `tmdb→local` (movie), `tvdb→fanart→local` (series); 24h cache via `metadata_refreshed_at`. Providers: `TmdbProvider.php`, `TvdbProvider.php`, `FanartProvider.php`, `LocalNfoProvider.php` — all implement `MetadataProviderInterface.php`. Shared client: `MetadataHttpClient.php`.
- `Streaming/`: `StreamManager.php` · `QualitySelector.php` (profiles: generic, mobile-low, mobile-high, web, tv-4k) · `StreamState.php` (positionTicks, statuses) · `HlsStreamer.php` (master/variant `.m3u8`, segment `.ts`)
- `Transcoding/`: `FfmpegRunner.php` (probe/transcode/thumbnail) · `EncodingHelper.php` (CRF 23/28, libx264/libx265) · `TranscodeManager.php` (config `config/ffmpeg.php`)

**Session** (`src/Session/`): `SessionManager.php` device sessions · `PlaybackController.php` continue-watching (<95%) · `SyncPlay/` group state, `TimeSync.php` NTP-style with `OFFSET_SAMPLE_COUNT=5`, weighted-mean offset.

**Other modules**: `src/LiveTv/` (`ChannelManager`, `GuideManager`, `Recorder`, `LiveTvManager`) · `src/Dlna/` (`ContentDirectory`, `AvTransport`, `DlnaServer`, `DeviceRegistry`, `DlnaDevice`).

**Common** (`src/Common/`):
- `Database/`: `ConnectionPool.php` (static `init()`/`getConnection('mysql')`), `QueryBuilder.php`
- `Logger/`: `LoggerFactory.php` · `LogChannels.php` (`AUTH`, `HTTP`, `WEBSOCKET`, `MEDIA`, `SESSION`, `STREAMING`) · `StructuredLogger.php` (Monolog wrapper) · `AuditLogger.php`

**Web portal** (`src/Server/WebPortal/` + `public/`): `WebPortalRouter.php` for `/api/v1/libraries`, `/api/v1/media/{id}`. `PageRenderer.php` instantiates `\Smarty`, `setTemplateDir($templateDir)`, `assign()`, `fetch('home/index.tpl')`. Templates: `public/templates/{layouts,partials,auth,home,library,player}/*.tpl`. Assets: `public/assets/{css,js,images}/`. JS: `app.js` (global helpers, `window.PhlixApp`), `api-client.js` (`Auth`/`Library`/`Player` namespaces, refresh-token retry), `player.js` (30s `Player.reportProgress`).

## Database

Uses **`Workerman\MySQL\Connection`** ONLY — never PDO / mysqli. Pattern:

```php
$rows = $db->query("SELECT * FROM users WHERE id = ?", [$id]);
$db->query("INSERT INTO users (id, ...) VALUES (?, ?)", [$id, $name]);
```

Schema in `migrations/001_initial_schema.sql` (`users`, `user_settings`, `libraries`, `media_items`, `media_streams`, `sessions`, `playback_state`, `api_keys`, `transcode_jobs`) and `migrations/002_user_profiles_and_parental_controls.sql` (`user_profiles`, `profile_settings`, `watch_history`). All PKs are `CHAR(36)` UUIDs generated via the local `generateUuid()` `sprintf('%04x%04x-...', mt_rand(0,0xffff), ...)` pattern repeated in many classes.

## Config

`config/server.php` · `config/database.php` · `config/logger.php` (rotating to `.logs/`) · `config/ffmpeg.php`. Loaded via `include` — return arrays.

## Conventions

- **PSR-12**, `declare(strict_types=1);`, PSR-4 namespaces match dirs
- Controllers return chained `(new Response())->status(...)->json([...])` / `->html(...)` / `->text(...)`
- DB always parameterized; never concatenate user input
- Logging via `LoggerFactory::get(LogChannels::HTTP)` not `new Logger`
- WebSocket payload shape: `['type' => $event, 'data' => $payload, 'timestamp' => time()]`
- Tests mock `Workerman\MySQL\Connection` with `$this->createMock(Connection::class)` + `willReturn([['col' => 'val']])`
- Smarty templates use `{extends}` / `{block}` / `{include file="partials/media_card.tpl"}`
- Each step in `docs/archive/plans/phase-N/step-N.M-*.md` ends with: branch → commit → `unset GITHUB_TOKEN` → `gh pr create` → `gh pr merge --squash --delete-branch` → `git checkout master && git pull`

## CI

`.github/workflows/phpunit.yml` and `.github/workflows/coding-standards.yml` run on push. Coverage HTML in `coverage-report/`, Clover at `coverage.xml`.

## Reference docs

`README.md` · `docs/dev/DEVELOPER.md` · `docs/archive/SUPERVISOR_PLAN.md` (phase orchestration) · `docs/archive/IMPLEMENTATION_PLAN.md` · `docs/dev/PHLIX_MEDIA_SERVER_TECHNICAL_SPEC.md` · `PLATFORM_{ROKU,SAMSUNG_TIZEN,WINDOWS,MOBILE}.md` · per-phase plans under `docs/archive/plans/phase-{1..7}/`.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, read `.agents/skills/setup-caliber/SKILL.md` and follow its instructions to install Caliber.
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

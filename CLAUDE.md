# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> A more detailed agent brief lives in `AGENTS.md`. Read it for module-by-module conventions, schema fields, and per-class responsibilities. This file covers the essentials.

## Commands

```bash
composer install
php scripts/run-migrations.php              # apply migrations/*.sql against DB in config/database.php
php public/index.php                        # web portal entry (also dispatches /api/*)

# Tests
./vendor/bin/phpunit                        # full suite (Unit + Integration suites are wired in phpunit.xml)
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
./vendor/bin/phpunit tests/Unit/Auth/JwtHandlerTest.php --testdox    # single file
./vendor/bin/phpunit --filter testRegisterCreatesUser                 # single test

# Static analysis / style
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/phpstan analyze src/ --level=9
find src -name '*.php' -exec php -l {} \;
```

Coverage outputs to `coverage.xml` (Clover) and `coverage-report/` (HTML), configured in `phpunit.xml`. Test envs (`DB_*`, `APP_ENV=testing`) are also defined there. Note: `README.md` mentions `start.php` and `scripts/migrate.php` — those do **not** exist; use the commands above.

## Architecture

PHP 8.3+ media server on **Workerman 5.x**. PSR-4 autoload: `Phlix\` → `src/`, `Phlix\Tests\` → `tests/`. Single-process bootstrap in `public/index.php`; long-running worker bootstrap in `src/Server/Core/Application.php`.

### Layers

- **`src/Server/Http/`** — `Request::fromGlobals()` → `Router::dispatch()` (supports `{param}` placeholders and `$router->group($prefix, $cb, [$middleware])`). Controllers in `Http/Controllers/` return chained `(new Response())->status(...)->json(...)`.
- **`src/Server/WebSocket/`** — `WebSocketServer` wraps `Workerman\Worker`. `ConnectionPool` is a singleton (`getInstance()`). `MessageHandler->on($event, $cb)` registers handlers; event constants in `Events.php` (`WebSocketEvents::PLAYBACK_*`, `SYNCPLAY_*`, `AUTH_*`). Payload shape is always `['type' => $event, 'data' => $payload, 'timestamp' => time()]`.
- **`src/Server/WebPortal/`** — `WebPortalRouter` serves portal JSON (`/api/v1/libraries`, `/api/v1/media/{id}`, `GET /api/v1/media/facets?libraryId=<id>` → `{genres:string[]}` for the filter UI via `ItemRepository::distinctGenres()`, etc.). Per-user favorites/ratings/like-level/watched (E10) live here in the auth group → `MediaUserDataController`: `POST`/`DELETE /api/v1/media/{id}/favorite`, `PUT`/`DELETE /api/v1/media/{id}/rating` (body `{rating:int 1-10|null}`), `PUT /api/v1/media/{id}/like` (body `{level:int −2..2}` signed thumbs axis), `POST /api/v1/media/{id}/watched`|`/unwatched`, all `{message}`; `GET /api/v1/media/{id}` carries an add-only `user_data:{favorite,rating,like_level}` block. Favorites/ratings are account-level (keyed on `user_id`, like `user_settings`), **not per-profile**; persisted via `Phlix\Media\UserItemDataRepository` (table `user_item_data`, migrations 039/044/045). `PageRenderer` instantiates `\Smarty`, sets template dir, assigns vars, fetches templates from `public/templates/{layouts,partials,auth,home,library,player}/*.tpl`. A Vite-built front-end (`web-ui/`) compiles to a committed bundle under `public/assets/app/`, injected into templates by `src/Server/WebPortal/ViteAssets.php`.
- **`src/Auth/`** — `JwtHandler` (HS256, `iss=phlix`, 1h access / 7d refresh). `UserRepository` uses `password_hash(..., PASSWORD_ARGON2ID)`. `AuthManager` orchestrates register/login/refresh and calls `AuditLogger`. `UserProfileManager` enforces ≤5 profiles per user, PINs (4 or 6 digits, Argon2ID-hashed), and rating filter (G/PG/PG-13/R/NC-17/X/UNRATED). `WatchHistory` marks complete at 90%.
- **`src/Media/`** — `Library/` (scanner parses `S01E02`, `(2020)`; `FolderWatcher` uses mtime checksum; `ItemRepository` hydrates `metadata_json` and exposes `distinctGenres(?libraryId)` — DISTINCT/sorted genre facets unnested set-side via `JSON_TABLE` over `metadata_json.$.genres`). `Metadata/` providers (`Tmdb`, `Tvdb`, `Fanart`, `LocalNfo`) implement `MetadataProviderInterface`; `MetadataManager` priority is `tmdb→local` for movies, `tvdb→fanart→local` for series, with 24h cache via `metadata_refreshed_at`. `Streaming/` (`HlsStreamer` master/variant `.m3u8` + `.ts`; `QualitySelector` profiles: generic, mobile-low, mobile-high, web, tv-4k). `Transcoding/` (`FfmpegRunner` probe/transcode/HLS-segment, `EncodingHelper` CRF 23/28 libx264/libx265, `TranscodeManager` HLS pipeline; config in `config/ffmpeg.php`; DI wiring in `src/Common/Container/Providers/TranscodeServicesProvider.php`).
- **`src/Session/`** — `SessionManager` (device sessions), `PlaybackController` (continue-watching at <95%), `SyncPlay/` with `TimeSync` doing NTP-style weighted-mean offset over `OFFSET_SAMPLE_COUNT=5` samples.
- **`src/LiveTv/`** (`ChannelManager`, `GuideManager`, `Recorder`, `LiveTvManager`) and **`src/Dlna/`** (`ContentDirectory`, `AvTransport`, `DlnaServer`, `DeviceRegistry`, `DlnaDevice`).
- **`src/Common/`** — `Database/ConnectionPool` (static `init()` / `getConnection('mysql')`), `QueryBuilder`. `Logger/LoggerFactory` + `LogChannels` (`AUTH`, `HTTP`, `WEBSOCKET`, `MEDIA`, `SESSION`, `STREAMING`); `StructuredLogger` wraps Monolog; `AuditLogger` for security events.

### Database

**Only** `Workerman\MySQL\Connection` — never raw PDO or mysqli. Always parameterized:

```php
$rows = $db->query("SELECT * FROM users WHERE id = ?", [$id]);
$db->query("INSERT INTO users (id, name) VALUES (?, ?)", [$id, $name]);
```

Schema lives in `migrations/001_initial_schema.sql` and `migrations/002_user_profiles_and_parental_controls.sql`. All primary keys are `CHAR(36)` UUIDs generated by a local `generateUuid()` helper (`sprintf('%04x%04x-...', mt_rand(...), ...)`) duplicated across many classes — reuse the existing pattern rather than introducing a UUID library.

Tests mock the DB with `$this->createMock(Workerman\MySQL\Connection::class)` and stub `->query(...)->willReturn([['col' => 'val']])`.

### Config

`config/{server,database,logger,ffmpeg}.php` — each `include`d, each returns an array. Logger writes rotating files to `.logs/`.

## Conventions

- PSR-12, `declare(strict_types=1);`, namespaces mirror directories.
- Logging always via `LoggerFactory::get(LogChannels::HTTP)` — do not instantiate `new Logger` directly.
- Smarty templates use `{extends}` / `{block}` / `{include file="partials/media_card.tpl"}`.
- Plan steps under `docs/archive/plans/phase-N/step-N.M-*.md` finish with: branch → commit → `unset GITHUB_TOKEN` → `gh pr create` → `gh pr merge --squash --delete-branch` → `git checkout master && git pull`.

## Caliber (pre-commit)

A pre-commit hook may sync agent configs via `caliber refresh`. Before committing, check `grep -q "caliber" .git/hooks/pre-commit` — if present, the hook handles it; if not, run `caliber refresh` manually and stage the updated agent files (`CLAUDE.md`, `AGENTS.md`, `.claude/`, `.cursor/`, `.cursorrules`, `.github/copilot-instructions.md`, `.opencode/`). Valid `caliber refresh` flags are **only** `--quiet` and `--dry-run`. See `AGENTS.md` "Before Committing" section for full procedure; session-specific lessons in `CALIBER_LEARNINGS.md` if present.

## Further reading

`AGENTS.md` (module/class reference) · `docs/dev/DEVELOPER.md` · `docs/archive/IMPLEMENTATION_PLAN.md` · `docs/archive/SUPERVISOR_PLAN.md` · `docs/dev/PHLIX_MEDIA_SERVER_TECHNICAL_SPEC.md` · `PLATFORM_{ROKU,SAMSUNG_TIZEN,WINDOWS,MOBILE}.md` · per-phase plans under `docs/archive/plans/phase-{1..7}/`.

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
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ AGENTS.md .agents/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> A more detailed agent brief lives in `AGENTS.md`. Read it for module-by-module conventions, schema fields, and per-class responsibilities. This file covers the essentials.

## Commands

```bash
composer install
php scripts/run-migrations.php              # apply migrations/*.sql against DB in config/database.php
php public/index.php                        # web portal entry (also dispatches /api/*)
php start.php start                         # production multi-worker entry (HTTP :8096, WS :8097)

# Tests
./vendor/bin/phpunit                        # full suite (Unit + Integration suites are wired in phpunit.xml)
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
./vendor/bin/phpunit tests/Unit/Auth/JwtHandlerTest.php --testdox    # single file
# --testdox cannot name a skipped test — a skip-name set needs a plain run
./vendor/bin/phpunit --filter testRegisterCreatesUser                 # single test

# Static analysis / style
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/phpcs --standard=phpcs-tests.xml               # separate tests/ gate
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpstan analyse -c phpstan-tests.neon          # separate tests/ gate (level 2)
find src -name '*.php' -exec php -l {} \;

# Release
./scripts/release.sh patch --dry-run        # bump src/Common/Version.php + VERSION + k8s/helm/phlix/Chart.yaml
bash scripts/docker-boot-smoke.sh docker/Dockerfile phlix-server:local   # boot gate for a built image
```

Coverage outputs to `coverage.xml` (Clover) and `coverage-report/` (HTML), configured in `phpunit.xml`. Test envs (`DB_*`, `APP_ENV=testing`) are also defined there. Note: `scripts/migrate.php` does **not** exist — use `php scripts/run-migrations.php` or `php bin/phlix migrate`. `start.php` **does** exist and is the entry `systemd/phlix-server.service` and `docker/supervisord.conf` run.

## Architecture

PHP 8.3+ media server on **Workerman 5.x**. PSR-4 autoload: `Phlix\` → `src/`, `Phlix\Tests\` → `tests/`. Single-process (CGI/FPM) bootstrap in `public/index.php`; the **production entry is `start.php`** at the repo root — `Worker::runAll()` over an HTTP worker on `:8096` (count 14), a WebSocket worker on `:8097`, plus hub-heartbeat, background-timer and relay-tunnel workers (each count 1) — building its routes through `src/Server/Core/Application.php`.

### Layers

- **`src/Server/Http/`** — `Request::fromGlobals()` → `Router::dispatch()` (supports `{param}` placeholders and `$router->group($prefix, $cb, [$middleware])`). Controllers in `Http/Controllers/` return chained `(new Response())->status(...)->json(...)`.
- **`src/Server/WebSocket/`** — `WebSocketServer` wraps `Workerman\Worker`. `ConnectionPool` is a singleton (`getInstance()`). `MessageHandler->on($event, $cb)` registers handlers; event constants in `Events.php` (`WebSocketEvents::PLAYBACK_*`, `SYNCPLAY_*`, `AUTH_*`). Payload shape is always `['type' => $event, 'data' => $payload, 'timestamp' => time()]`.
- **`src/Server/WebPortal/`** — `WebPortalRouter` serves portal JSON (`/api/v1/libraries`, `/api/v1/media/{id}`, `GET /api/v1/media/facets?libraryId=<id>` → `{genres:string[]}` for the filter UI via `ItemRepository::distinctGenres()`, etc.). Per-user favorites/ratings/like-level/watched (E10) live here in the auth group → `MediaUserDataController`: `POST`/`DELETE /api/v1/media/{id}/favorite`, `PUT`/`DELETE /api/v1/media/{id}/rating` (body `{rating:int 1-10|null}`), `PUT /api/v1/media/{id}/like` (body `{level:int −2..2}` signed thumbs axis), `POST /api/v1/media/{id}/watched`|`/unwatched`, all `{message}`; `GET /api/v1/media/{id}` carries an add-only `user_data:{favorite,rating,like_level}` block. Favorites/ratings/like-level/watched are **per-profile** since migration `100_user_item_data_profile_id.sql` widened the PK to `(user_id, profile_id, item_id)`; persisted via `Phlix\Media\UserItemDataRepository` (table `user_item_data`, migrations 039/044/045/100). Every method takes a trailing `?string $profileId = null` that falls back to the account's active (else first) profile and throws `Phlix\Auth\ProfileNotOwnedException` when the profile is not owned by the user. **UI is the `/app` Vue SPA only** — the legacy Smarty page renderer (`PageRenderer` + `public/templates/**/*.tpl` page templates + page controllers) was removed; legacy page paths now 302-redirect to their `/app` equivalent in `public/index.php`. The SPA shell is served by `SharedUiController` (`/app` + `/app/*`) from the Vite-built bundle under `public/assets/app/` (TypeScript source in `web-ui/`), injected via `src/Server/WebPortal/ViteAssets.php`; `WebPortalRouter` now serves JSON only. Smarty (`smarty/smarty`) is retained **only** for the newsletter email (`src/Admin/NewsletterGenerator.php` + `public/templates/emails/newsletter.tpl`).
- **`src/Auth/`** — `JwtHandler` (HS256, `iss=phlix`, 1h access / 7d refresh). `UserRepository` uses `password_hash(..., PASSWORD_ARGON2ID)`. `AuthManager` orchestrates register/login/refresh and calls `AuditLogger`. `UserProfileManager` enforces ≤5 profiles per user, PINs (4 or 6 digits, Argon2ID-hashed), and rating filter (G/PG/PG-13/R/NC-17/X/UNRATED). `WatchHistory` marks complete at 90%. External providers (OIDC/LDAP/GitHub) are registered per worker by `AuthProviderBootstrapper` into `AuthProviderRegistry` behind the `auth.{oidc,ldap,github}.enabled` server settings; their config lives in the `plugin_settings` table (migration `093_plugin_settings.sql`) via `Phlix\Plugins\Repository\PluginSettingsRepository` and the `PluginDbSettings` trait, not `settings.json`.
- **`src/Media/`** — `Library/` (scanner parses `S01E02`, `(2020)`; `FolderWatcher` uses mtime checksum; `ItemRepository` hydrates `metadata_json`; genres are normalized into a `media_item_genres(media_item_id, genre)` join table (migration 051, `genre` COLLATE `utf8mb4_bin` for exact-match filtering — replacing migration 050's MySQL 8 multi-valued functional index after it reproduced real InnoDB purge-thread errors under sustained scan/rescan churn), kept in sync from `create()`/`update()` while `metadata_json.$.genres` remains the canonical source; `distinctGenres(?libraryId)` returns DISTINCT/sorted genre facets by joining `media_item_genres` back to `media_items` (re-asserting `COLLATE utf8mb4_unicode_ci` so the facet list stays case-insensitive independent of the filter columns' now-`_bin` collation)); `ItemRepository::upsertByPath()` is the race-safe find-or-create the scanner uses per file, backed by migration 072's `media_items.path_hash` STORED generated column + `(library_id, path_hash)` unique index (the index is added post-migration by `migrations/cleanup_072.php`, which first merges any pre-existing duplicates); `php bin/phlix media:dedupe-paths [--apply]` merges duplicate-path rows via `PathDeduper` (score-based keeper selection, collision-safe reference repointing)). Strings bound into a media write must already be valid UTF-8 and be truncated **character-wise** (`mb_substr`, not `substr`) — `MediaScanner::streamLanguage()`/`streamTitle()` feed `ItemRepository::addStream()`, which has no `toValidUtf8()` guard, so a byte-wise cut is rejected with MySQL error 1366 and the delete-then-insert stream write leaves the item with a partial `media_streams` set. `Metadata/` providers (`Tmdb`, `Tvdb`, `Fanart`, `LocalNfo`) implement `MetadataProviderInterface`; `MetadataManager` priority is `tmdb→local` for movies, `tvdb→fanart→local` for series, with 24h cache via `metadata_refreshed_at`. `Streaming/` (`HlsStreamer` master/variant `.m3u8` + `.ts`; `QualitySelector` profiles: generic, mobile-low, mobile-high, web, tv-4k; `Streaming/Dash/` MPEG-DASH `DashStreamer` + `AdaptationSet`/`Representation`/`SegmentTemplate`; `Streaming/Trickplay/` scrubber sprites + Roku `.bif` via `BifWriter`, served by `TrickplayController` — `config/trickplay.php` is deliberately tiny and the serving dir is derived from `config/ffmpeg.php`'s `transcode_dir`). `MediaAsset/` is the file-based chapter-thumbnail/trickplay queue: `MediaAssetJobStore` writes one JSON job per item under `config/media_asset_jobs.php`'s `job_queue_dir` (FIFO by mtime), drained by `MediaAssetWorker` on a `Workerman\Timer`; migration `101_library_scan_jobs_media_assets_type.sql` adds a `media_assets` value to `library_scan_jobs.type` so a library's existing rows can be re-enqueued without a rescan. `Music/` (`MusicLibraryScanner`, `MusicLibraryService`, `MusicScanSkipIndex`) — an album with no artist tag is ingested under a structural placeholder artist flagged by `music_artists.is_placeholder` (migration `102_music_artists_placeholder_flag.sql`, which requires a music scan after deploy). `Transcoding/` (`FfmpegRunner` probe/transcode/HLS-segment, `TranscodeManager` HLS pipeline (`readFailureReason()` scrubs then `mb_substr(-500)`s the ffmpeg log tail so `transcode_jobs.error` is always valid UTF-8), `EncodeSettings` (effective preset/CRF/audio-bitrate behind the `transcoding.*` admin settings, and the encode fingerprint folded into the job key); config in `config/ffmpeg.php` + `config/transcoding.php`; DI wiring in `src/Common/Container/Providers/TranscodeServicesProvider.php`).
- **`src/Admin/Maintenance/`** — one-off admin tasks behind `/api/v1/admin/maintenance/*` (`MaintenanceController`). `MaintenanceTask::CATALOGUE` splits them into `MODE_SYNC` (`reap-scan-jobs`, `reap-transcode-jobs`, `cleanup-orphaned-stats` → `200`) and `MODE_QUEUED` (`storage-snapshot`, `dedupe-paths` → `202` plus a `maintenance_jobs` row to poll). Queued rows are claimed atomically by `MaintenanceQueueWorker` (conditional `UPDATE`, `POLL_SECONDS = 5` `Workerman\Timer`) in the background-timers worker; table from migration `098_maintenance_jobs.sql`.
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

A write's return value is shaped by the first SQL token — `array` for `SELECT`/`SHOW`, `int` rowcount for `UPDATE`/`DELETE`/`REPLACE`, a `lastInsertId()` string or `null` for `INSERT` — and is **never `false`**. Test it with `Phlix\Common\Database\WriteResult::wroteNothing($result)`, never `$result === false` or a bare falsy check (a successful UUID insert returns the falsy string `'0'`). `PhlixMySQLConnection` normalises positional bind keys; `PooledMySQLConnection` is the coroutine-aware pooled front and opens no socket until `query()`.

Tests mock the DB with `$this->createMock(Workerman\MySQL\Connection::class)` and stub `->query(...)->willReturn([['col' => 'val']])`.

### Workerman Session Model

**Critical:** `$_SESSION` is **NOT** request-scoped under Workerman. Unlike php-fpm where each request gets a fresh `$_SESSION`, Workerman runs a single process handling multiple concurrent connections. Using `$_SESSION` for state (like OAuth state tokens) causes **race conditions and state leakage** between requests.

**Rule:** For any cross-request state (OAuth state, CSRF tokens, etc.), use **DB-backed state stores**:
- `DbOidcStateStore` for OIDC OAuth
- `DbOAuth2StateStore` for plain OAuth2 providers (e.g. GitHub)
- `DbTraktOAuthStateStore` for Trakt
- `DbLastfmOAuthStateStore` for Last.fm

These use the `oauth_state_store` table with TTL and atomic consume to prevent race conditions.

### Async Patterns

**Blocking vs Async I/O:** Workerman runs a single-process event loop. Blocking I/O (synchronous cURL, `file_get_contents` for HTTP, blocking DB queries) stalls the entire worker, blocking all concurrent connections. Always prefer non-blocking patterns. `docs/dev/BLOCKING_IO_EXCEPTIONS.md` is the complete, closed register of accepted exceptions — each one named, bounded by a measured timeout, and blast-radius costed; `Phlix\Common\Http\EventLoopTls::requiresBlockingCurl()` gates the one TLS case.

**HTTP Clients — Use `workerman/http-client`:**
```php
use Workerman\Http\Client;

$client = new Client(['timeout' => 10]);
// Non-blocking async request with cooperative wait
$client->request($url, [
    'success' => function ($response) use (&$state) {
        $state['response'] = $response;
        $state['done'] = true;
    },
    'error' => function ($error) use (&$state) {
        $state['error'] = $error;
        $state['done'] = true;
    },
]);
// Cooperative wait — yields to event loop, allowing other tasks to proceed
while (!$state['done'] && $waited < $maxWait) {
    usleep(1000); // 1ms interval
    $waited += 0.001;
}
```

Classes using this pattern: `MetadataHttpClient`, `Hub\HttpClient`, `S3Client`.

**Monotonic Time — Use `hrtime(true)`:**
Never use `microtime(true)` or `time()` for measuring elapsed intervals. `hrtime(true)` returns a monotonically increasing nanosecond timestamp that is immune to system clock adjustments (NTP, daylight savings, manual changes).

```php
// CORRECT — monotonic, immune to clock jumps
$start = hrtime(true);
doWork();
$elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

// WRONG — microtime can go backwards if the system clock adjusts
$start = microtime(true);
```

Used in: benchmark scripts, streaming throughput measurement, performance profiling.

**Batch Queries for N+1 Prevention:**
Query in batches rather than looping with individual queries. When fetching related data for multiple items, use `WHERE id IN (...)` with a single query.

```php
// WRONG — N+1 queries (one per item)
foreach ($itemIds as $id) {
    $items[] = $db->query("SELECT * FROM media_items WHERE id = ?", [$id]);
}

// CORRECT — single batch query
$placeholders = implode(',', array_fill(0, count($itemIds), '?'));
$sql = "SELECT * FROM media_items WHERE id IN ($placeholders)";
$items = $db->query($sql, $itemIds);
```

`DuplicateFinder` demonstrates batch pagination with `DEFAULT_BATCH_SIZE = 500` to bound memory in long-lived workers.

**Chunked/Streaming for Large Data:**
For large data that exceeds memory or response size limits, use chunked processing:

```php
// Reading large files in chunks (see AudiobookScanner)
$handle = fopen($filePath, 'rb');
while (!feof($handle)) {
    $chunk = fread($handle, 8192);
    // Process chunk without loading entire file
}

// Chunked HTTP responses for Workerman (see HttpHandler)
// Responses over 2 MB are chunked to avoid buffering
```

`AudiobookScanner` uses chunked reading to parse files without loading them into memory entirely.

### Config

`config/{server,database,logger,ffmpeg,artwork,dlna,imdb,managed_workers,media_asset_jobs,metadata,plugins,process,relay,similarity_jobs,transcoding,trickplay,updates,webhooks}.php` — each `include`d, each returns an array. Logger writes rotating files to `.logs/`.

## Conventions

- PSR-12, `declare(strict_types=1);`, namespaces mirror directories.
- Logging always via `LoggerFactory::get(LogChannels::HTTP)` — do not instantiate `new Logger` directly.
- Smarty is now used **only** for the newsletter email template (`public/templates/emails/newsletter.tpl`); the page-rendering templates were removed. The `/app` Vue SPA (in `web-ui/`) is the web UI.
- **Version is single-sourced from `src/Common/Version.php`** (`Version::STRING`). `./scripts/release.sh [patch|minor|major]` propagates the bump to `VERSION` and `k8s/helm/phlix/Chart.yaml` (`version` + `appVersion`) in one commit — never edit those by hand, and never add a `version` field to `composer.json` (`composer validate --strict` fails when it is present). See `RELEASE_PROCESS.md`.
- Plan steps under `docs/archive/plans/phase-N/step-N.M-*.md` finish with: branch → commit → `unset GITHUB_TOKEN` → `gh pr create` → `gh pr merge --squash --delete-branch` → `git checkout master && git pull`.

## Caliber (pre-commit)

A pre-commit hook may sync agent configs via `caliber refresh`. Before committing, check `grep -q "caliber" .git/hooks/pre-commit` — if present, the hook handles it; if not, run `caliber refresh` manually and stage the updated agent files (`CLAUDE.md`, `AGENTS.md`, `.claude/`, `.cursor/`, `.cursorrules`, `.github/copilot-instructions.md`, `.opencode/`). Valid `caliber refresh` flags are **only** `--quiet` and `--dry-run`. See `AGENTS.md` "Before Committing" section for full procedure; session-specific lessons in `CALIBER_LEARNINGS.md` if present.

## Further reading

`AGENTS.md` (module/class reference) · `docs/dev/DEVELOPER.md` · `docs/dev/BLOCKING_IO_EXCEPTIONS.md` · `RELEASE_PROCESS.md` · `docker/README.md` · `docs/archive/IMPLEMENTATION_PLAN.md` · `docs/archive/SUPERVISOR_PLAN.md` · `docs/dev/PHLIX_MEDIA_SERVER_TECHNICAL_SPEC.md` · `PLATFORM_{ROKU,SAMSUNG_TIZEN,WINDOWS,MOBILE}.md` · per-phase plans under `docs/archive/plans/phase-{1..7}/`.

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
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.

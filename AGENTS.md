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
./vendor/bin/phpunit tests/Unit/Auth/JwtHandlerTest.php --testdox
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/phpstan analyze src/ --level=9
find src -name '*.php' -exec php -l {} \;
```

Coverage writes to `coverage.xml` + `coverage-report/` (configured in `phpunit.xml`).

## Architecture

**Entry**: `public/index.php` bootstraps `ConnectionPool::init()` + `LoggerFactory::init()`, builds `AuthManager`, `LibraryManager`, `SessionManager`, `PlaybackController`, then dispatches via `PageRenderer` or `/api/` JSON. Server worker entry is `src/Server/Core/Application.php` (`Workerman\Worker`).

**HTTP** (`src/Server/Http/`): `Request::fromGlobals()` → `Router::dispatch()` matches `{param}` placeholders → handler returns chained `Response`. Controllers live in `src/Server/Http/Controllers/` (`AuthController.php`, `LibraryController.php`, `MediaItemController.php`, `HlsController.php`, `TranscodeController.php`, `SessionController.php`, `TraktOAuthController.php`). Middleware via `$router->group($prefix, $cb, [$middleware])`.

**WebSocket** (`src/Server/WebSocket/`): `WebSocketServer.php` wraps `Workerman\Worker` on `websocket://`. `Connection.php` implements `ConnectionInterface.php`. `ConnectionPool.php` is singleton (`getInstance()`). `MessageHandler->on($event, $cb)` registers handlers; events listed in `Events.php` (`WebSocketEvents::PLAYBACK_*`, `SYNCPLAY_*`, `AUTH_*`).

**Auth** (`src/Auth/`): `JwtHandler.php` HS256, 1h access / 7d refresh, `iss=phlix`. `UserRepository.php` uses `password_hash(..., PASSWORD_ARGON2ID)`. `AuthManager.php` orchestrates register/login/refresh (login accepts email OR username), calls `AuditLogger`. `UserProfileManager.php` enforces up to 5 profiles, PIN (4 or 6 digits, Argon2ID), rating filter (G/PG/PG-13/R/NC-17/X/UNRATED). `WatchHistory.php` tracks 90% completion threshold.

**Auth DI bindings** (`src/Common/Container/Providers/AuthServicesProvider.php`): the **only** place WebAuthn services are wired into the container. `WebAuthnSettings` is a `factory()` that reads `$appConfig['webauthn']` and calls `WebAuthnSettings::fromConfig()`; `WebAuthnCredentialRepository`, `WebAuthnManager`, and `WebAuthnController` are `autowire()`d off it. Without this provider, php-di tries to autowire `WebAuthnSettings`'s three `string` ctor params (`rpId`/`rpName`/`rpOrigin`) and fails Application boot with `"Parameter \$rpId of __construct() has no value defined or guessable"`. `WebAuthnSettings::fromConfig()` applies defaults (`localhost` / `Phlix Media Server` / `https://localhost` / `attestation_required = false`) so every `webauthn` config key is optional. When you add a new WebAuthn-touching controller or service, register it here — not in some new provider.

**`webauthn` config shape** (under `$appConfig`, e.g. `config/server.php`):

```php
'webauthn' => [
    'rp_id'                => 'phlix.example.com',  // string, default 'localhost'
    'rp_name'              => 'Phlix Media Server', // string, default 'Phlix Media Server'
    'rp_origin'            => 'https://phlix.example.com', // string, default 'https://localhost'
    'attestation_required' => false,                // bool, default false
],
```

**Media** (`src/Media/`):
- `Library/`: `LibraryManager.php` · `MediaScanner.php` (parses `S01E02`, `(2020)`; a single `ffprobe` per time-based file — on both the initial scan and a plain rescan — also derives `metadata_json['source'] = {width, height, video_codec, video_bitrate, pix_fmt, audio_codec, audio_bitrate}` for the Stream Quality/ABR ladder builder (`video_bitrate` falls back to `format.bit_rate`; the real video stream is chosen by skipping embedded cover-art streams, `disposition.attached_pic = 1`), plus the video+primary-audio `media_streams` rows; `scripts/backfill-source-metadata.php [--library=<id>] [--limit=<n>]` backfills pre-existing items missing `source`) · `FolderWatcher.php` (mtime checksum) · `ItemRepository.php` (hydrates `metadata_json`; `distinctGenres(?libraryId): list<string>` returns the DISTINCT, sorted, non-empty genre facet list — unnested set-side via `JSON_TABLE` over `metadata_json.$.genres[*]` (MySQL 8.0+) so PHP never materialises the whole table; `libraryId` is bound + optional, unscoped+route-gated like `query()`; `addStream()` / `deleteStreamsByItem()` write/replace `media_streams` rows — no unique key on `(media_item_id, stream_index)`, so replacement is delete-then-insert)
- `Metadata/`: `MetadataManager.php` priority `tmdb→local` (movie), `tvdb→fanart→local` (series); 24h cache via `metadata_refreshed_at`. Providers: `TmdbProvider.php`, `TvdbProvider.php`, `FanartProvider.php`, `LocalNfoProvider.php` — all implement `MetadataProviderInterface.php`. Shared client: `MetadataHttpClient.php`. Helpers: `PosterSrcset.php` (responsive poster `srcset`), `SceneFilenameNormalizer.php` (normalizes scene-release filenames).
- `Streaming/`: `StreamManager.php` · `QualitySelector.php` (profiles: generic, mobile-low, mobile-high, web, tv-4k) · `StreamState.php` (positionTicks, statuses) · `HlsStreamer.php` (master/variant `.m3u8`, segment `.ts`) · **ABR ladder (Stream Quality/ABR step A2 — pure logic; not yet wired into the transcode pipeline, see `A5`):** `AbrLadder.php` (`build(SourceProfile, profileName='generic'): LadderResult` — deterministic, no DB/ffprobe/filesystem/clock; returns the source-clamped H.264 rung ladder 240p…2160p highest-first plus an "Original" descriptor — copy (`-c copy`) passthrough when the source is H.264+AAC and fits the profile cap, else the top clamped transcode rung relabelled "Original (best available)"; never upscales, never exceeds the source's own bitrate, widths follow the source aspect ratio; a rung's H.264 `CODECS` level is chosen by macroblock count — `ceil(w/16)*ceil(h/16)` against a MaxFS table — not height alone, so anamorphic/DCI/ultrawide rungs advertise a legal `avc1.*` level, not an under-declared one) · `Rendition.php` (`final readonly` rung VO — `id`/`label`/`width`/`height`/`bitrate`(peak BANDWIDTH bps)/`codecs`/`url`(null until `A5`/`A7` fill it in)/`isOriginal`/`isCopy`, plus derived `maxrate()`(≈1.07×`videoBitrate`)/`bufsize()`(2×maxrate)/`bandwidth()`) · `SourceProfile.php` (typed, I/O-free ladder input; `fromSourceMetadata()` adapts A1's persisted `metadata_json['source']` blob) · `LadderResult.php` (`renditions` + `original`; `streamVariants()` prepends a copy-passthrough Original as a genuine extra highest variant, or omits a mirrored-top-rung Original so the master doesn't get a duplicate). The ladder **consumes** `QualitySelector`'s device-profile caps (`max_resolution`/`max_bitrate`, via `getProfile($profileName)`) as its clamp ceiling — it does not replace or change `QualitySelector`, which still governs direct-play-vs-transcode selection and hardware-vendor codec choice.
- `Transcoding/`: `FfmpegRunner.php` (probe/transcode/thumbnail, HLS segmenting) · `EncodingHelper.php` (CRF 23/28, libx264/libx265) · `TranscodeManager.php` (HLS transcode pipeline; config `config/ffmpeg.php`). DI bindings in `src/Common/Container/Providers/TranscodeServicesProvider.php`; HLS columns added to `transcode_jobs` by `migrations/036_transcode_jobs_hls_columns.sql`.
- `UserItemDataRepository.php` (`Phlix\Media\`): per-**USER** favorites + ratings + like-level + watched flag for media items (E10). Account-level, keyed on `user_id` (like `user_settings`) — **NOT per-profile** like `watch_history`; a profile-id swap would be needed for per-profile. Backs table `user_item_data(user_id, item_id PK, favorite BOOL, rating INT NULL, like_level TINYINT NOT NULL DEFAULT 0, watched BOOL NULL, updated_at)` (`migrations/039_user_item_data.sql` + `044_user_item_like_level.sql` + `045_user_item_data_watched.sql`, FK CASCADE → `users` + `media_items`). Methods: `getItemData(userId,itemId): ?array{favorite,rating,like_level}`, `setFavorite()` / `setRating()` / `setLikeLevel()` / `setWatched()` (each `INSERT ... ON DUPLICATE KEY UPDATE` on a single column so the others are preserved), `getFavorites(userId,limit,offset)` (joins `media_items`, favorited-only, newest-first), `deleteByItem()`. Rating range **1-10 enforced in PHP** (`setRating()` throws `\InvalidArgumentException`; consts `MIN_RATING`/`MAX_RATING`); `like_level` is the signed **−2..2 thumbs axis** (−2 strongly dislike … 0 not set … 2 love; consts `MIN_LIKE`/`MAX_LIKE`, enforced in PHP) — no DB CHECK. Same flat positional `?` binding idiom as `UserRepository`/`WatchHistory`. DI: `autowire()` in `MediaServicesProvider`.

**Session** (`src/Session/`): `SessionManager.php` device sessions · `PlaybackController.php` continue-watching (<95%) · `SyncPlay/` group state, `TimeSync.php` NTP-style with `OFFSET_SAMPLE_COUNT=5`, weighted-mean offset.

**Other modules**: `src/LiveTv/` (`ChannelManager`, `GuideManager`, `Recorder`, `LiveTvManager`) · `src/Dlna/` (`ContentDirectory`, `AvTransport`, `DlnaServer`, `DeviceRegistry`, `DlnaDevice`).

**Plugins** (`src/Plugins/`): `PluginLoader.php` install / enable / disable / uninstall lifecycle (DTOs + manifest schema live in `detain/phlix-shared` under `Phlix\Shared\Plugin\*`; the full developer guide + `manifest.schema.json` live in the external [phlix-docs](https://detain.github.io/phlix-docs/plugins/developer-guide) site, **not** in-tree). On install, `PluginLoader::defaultSettings()` materialises the persisted settings array from the manifest's `settings` schema. **`required` vs `default` contract (null-fill):** every *declared* setting key always gets a slot — its declared `default` when present, otherwise **`null`**. A `required: true` setting with **no `default`** is materialised as `null` (a slot is still created), so the materialised array's key-set is always identical to the manifest's declared key-set; defaultless keys are never silently dropped. `required` is therefore **advisory metadata for the settings UI** (prompt the operator to fill it in), **not** a load-time rejection — install/enable never fail just because a required setting lacks a default.

**Theming** (`src/Theming/`): `ThemeMiddleware.php` is **post-render** — it runs `str_replace()` over the already-rendered HTML body looking for the literal strings `{$theme_css|raw}` and `{$theme_js|raw}`. Those are NOT Smarty variables; `|raw` is Twig syntax and Smarty has no equivalent modifier. In `public/templates/layouts/base.tpl` both markers MUST be wrapped in `{literal}…{/literal}` so Smarty emits them verbatim and ThemeMiddleware can find them. If you "fix" them to look like real Smarty (`{$theme_css}` etc.) Smarty will undef-warn and the theme will silently fail to load.

**Marker detection types** (`src/Media/Markers/`): every writer (`IntroMarkerCandidate`, `OutroMarkerCandidate`, and the `INT UNSIGNED` DB columns) types `start_seconds` / `end_seconds` as `int`. `Detection\StoredMarkers` is read-side and must validate with `is_int()` — a previous `is_string()` check made `hasIntro()` / `hasOutro()` always return false on real production data. Both properties are `?int`. Do not "loosen" them to accept strings without changing every writer too.

**Common** (`src/Common/`):
- `Database/`: `ConnectionPool.php` (static `init()`/`getConnection('mysql')`), `QueryBuilder.php`
- `Logger/`: `LoggerFactory.php` · `LogChannels.php` (`AUTH`, `HTTP`, `WEBSOCKET`, `MEDIA`, `SESSION`, `STREAMING`) · `StructuredLogger.php` (Monolog wrapper) · `AuditLogger.php`

**Web portal** (`src/Server/WebPortal/` + `public/`): `WebPortalRouter.php` for `/api/v1/libraries`, `/api/v1/media/{id}`. `GET /api/v1/media/facets?libraryId=<uuid>` → `{genres:string[]}` (`getMediaFacets()` → `ItemRepository::distinctGenres()`; static segment registered BEFORE `{id}` so it isn't swallowed; the SPA falls back to its locally-derived genre set when this endpoint is absent; response is an object so more facet keys can be added later). Favorites/ratings routes (E10) are registered here too, inside the `$auth` (AuthMiddleware) group, and delegate to `MediaUserDataController` (`src/Server/Http/Controllers/`): `POST /api/v1/media/{id}/favorite` (mark) · `DELETE /api/v1/media/{id}/favorite` (un-favorite) · `PUT /api/v1/media/{id}/rating` body `{rating:int 1-10|null}` (set/clear; 400 non-numeric/out-of-range, 404 item missing) · `DELETE /api/v1/media/{id}/rating` (clear) · `PUT /api/v1/media/{id}/like` body `{level:int −2..2}` (required signed thumbs axis; 400 missing/non-int/out-of-range) · `POST /api/v1/media/{id}/watched` / `POST /api/v1/media/{id}/unwatched` (set/clear the watched flag) — all return `{message}`. Plus `GET /api/v1/users/me/favorites?limit&offset` → `{items,limit,offset}` (favorited rows hydrated via `ItemRepository::findById` + shaped like the media list, each with the add-only `user_data` block incl. `like_level`; missing items skipped). `GET /api/v1/media/{id}` now also carries an ADD-ONLY `user_data: {favorite:bool, rating:int|null, like_level:int}` block (injected handler-side in `getMediaItem()` via `resolveUserData()`, NOT in `MediaItemShaper`; `null` when unauthenticated, `{favorite:false, rating:null, like_level:0}` when authed with no row). **Routes live ONLY on `WebPortalRouter`, NOT `Application::loadApiRoutes()`** — both HTTP entry points and the relay dispatcher fall `/api/*` through to the same container-built `WebPortalRouter`, so one registration serves all three paths (adding to `Application` would duplicate/diverge). DI threads `UserItemDataRepository` + `MediaUserDataController` into the router via `WebPortalServicesProvider`. `PageRenderer.php` instantiates `\Smarty`, `setTemplateDir($templateDir)`, `assign()`, `fetch('home/index.tpl')`. Templates: `public/templates/{layouts,partials,auth,home,library,player}/*.tpl`. Assets: `public/assets/{css,js,images}/`. JS: `app.js` (global helpers, `window.PhlixApp`), `api-client.js` (`Auth`/`Library`/`Player` namespaces, refresh-token retry), `player.js` (30s `Player.reportProgress`). `ViteAssets.php` injects the Vite-built front-end bundle (TypeScript source in `web-ui/` per `web-ui/vite.config.ts`, output committed to `public/assets/app/`) into the Smarty layout.

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

## Async Patterns

**HTTP Clients — Use `workerman/http-client` for non-blocking I/O:**
Workerman runs a single-process event loop. Blocking HTTP calls stall all concurrent connections.

Three classes use `Workerman\Http\Client` for async HTTP:
- `MetadataHttpClient` (`src/Media/Metadata/`) — TMDB/TVDB/Fanart API calls
- `Hub\HttpClient` (`src/Hub/`) — Hub relay API calls
- `S3Client` (`src/Admin/`) — S3-compatible storage

Pattern: lazy-initialized `Client` with callback-based async request + cooperative `usleep` wait loop:

```php
$client = new Client(['timeout' => $this->timeout]);
$client->request($url, [
    'success' => fn($response) => $state['response'] = $response,
    'error'   => fn($error)   => $state['error']   = $error,
]);
// Cooperative wait — yields to event loop
while (!$state['done'] && $waited < $maxWait) {
    usleep(1000);
    $waited += 0.001;
}
```

When `Workerman\Coroutine` is available (coroutine context), use `requestCoroutine()` for true async. Falls back to synchronous cURL in CLI/testing contexts.

**Monotonic Time — Use `hrtime(true)` not `microtime(true)`:**
`hrtime(true)` returns a monotonically increasing nanosecond counter, immune to system clock adjustments (NTP sync, daylight savings, manual changes). Use for all elapsed-time measurement:

```php
$start = hrtime(true);
// ... work ...
$elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
```

Used in: `AdminHubController.php`, benchmark scripts under `scripts/bench/`. Never use `microtime(true)` or `time()` for intervals.

**Batch Queries — Prevent N+1:**
Fetch related data for multiple items in a single query using `WHERE id IN (...)`. Never loop with individual queries.

```php
// Single batch query — CORRECT
$ids = array_column($items, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$rows = $db->query("SELECT * FROM media_items WHERE id IN ($placeholders)", $ids);

// Per-item queries — WRONG (N+1)
foreach ($items as $item) {
    $rows[] = $db->query("SELECT * FROM media_items WHERE id = ?", [$item['id']]);
}
```

`DuplicateFinder` demonstrates batch pagination (`DEFAULT_BATCH_SIZE = 500`) for bounded memory in long-lived workers.

**Chunked/Streaming I/O for Large Data:**
- `AudiobookScanner` reads large audio files in 8KB chunks via `fread()` to avoid memory pressure
- `HttpHandler` chunks responses over 2 MB for streaming to clients
- RelayConsumer chunks frames to ≤65535 bytes per DATA frame

## CI

`.github/workflows/phpunit.yml` and `.github/workflows/coding-standards.yml` run on push. Coverage HTML in `coverage-report/`, Clover at `coverage.xml`.

**Test DB credentials.** `phpunit.xml` exports `DB_HOST=127.0.0.1`, `DB_DATABASE=phlix_test`, `DB_USER=root`, `DB_PASSWORD=root` — those must match the workflow's `services: mysql:8.0` container, which sets `MYSQL_ROOT_PASSWORD=root` and `MYSQL_DATABASE=phlix_test`. `tests/Integration/Server/Core/ApplicationTest.php`'s `writeTempDbConfig()` reads those env vars when building the temp `config/database.php` for the smoke-boot test, so if you change either side (the workflow service or `phpunit.xml`) you MUST update the other in the same commit or CI fails with `Access denied for user 'root'@... (using password: NO)`.

**Coverage threshold.** `phpunit.yml` enforces a minimum statement-coverage percentage computed from the Clover XML (`/coverage/project/metrics/@statements` and `@coveredstatements`) — NOT Cobertura's `@line-rate`, which PHPUnit does not emit and which silently returns 0 if you re-introduce it. Current floor is `MIN_COVERAGE=40` (just below the actual ~50%). Bump it as coverage grows; never set it above current coverage or every PR turns red.

**Codecov.** `CODECOV_TOKEN` is provisioned on every repo, but uploads are non-blocking (`fail_ci_if_error: false`) until someone clicks through at <https://app.codecov.io> to flip the repo's `activated: true` flag. The Codecov v2 API does **not** expose programmatic repo activation (PATCH/PUT/POST on the repo endpoint return 405/404), so this step cannot be scripted — don't re-investigate.

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
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md AGENTS.md .agents/ 2>/dev/null`
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
If the pre-commit hook is not set up, read `.agents/skills/setup-caliber/SKILL.md` and follow the setup instructions.
<!-- /caliber:managed:sync -->

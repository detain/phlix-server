# Phlix Media Server — Agent Guide

PHP 8.3+ media server on Workerman 5.x with HTTP REST, WebSocket, an `/app` Vue SPA web portal (the legacy Smarty page UI was removed; Smarty now renders only the newsletter email), HLS streaming via FFmpeg, JWT auth, and DLNA/SyncPlay/LiveTV modules. Namespace `Phlix\` → `src/`, tests `Phlix\Tests\` → `tests/`.

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

**Entry**: `public/index.php` bootstraps `ConnectionPool::init()` + `LoggerFactory::init()`, builds `AuthManager`, `LibraryManager`, `SessionManager`, `PlaybackController`, then dispatches `/api/` JSON, serves the `/app` SPA shell (`SharedUiController`), or 302-redirects legacy page paths to their `/app` equivalent (the Smarty `PageRenderer` page path was removed). Server worker entry is `src/Server/Core/Application.php` (`Workerman\Worker`).

**HTTP** (`src/Server/Http/`): `Request::fromGlobals()` → `Router::dispatch()` matches `{param}` placeholders → handler returns chained `Response`. Controllers live in `src/Server/Http/Controllers/` (`AuthController.php`, `LibraryController.php`, `MediaItemController.php`, `HlsController.php`, `TranscodeController.php`, `SessionController.php`, `TraktOAuthController.php`). Middleware via `$router->group($prefix, $cb, [$middleware])`. **`HlsController.php`/`DashController.php` share segment/playlist serving via the `TranscodeFileServer` trait (`Http/Controllers/TranscodeFileServer.php`) — its `serveJobFile()` streams the file through `Response::withFile()`/Workerman's event-loop file sender instead of `file_get_contents()`-buffering it into worker memory (Stream Quality/ABR step S3), the same mechanism direct-play's `serveMediaStream()` uses, essential for concurrent multi-MB HLS/DASH segments.** It also gets real HTTP semantics for free: `Range` support (`bytes=A-B`/`bytes=A-`/suffix `bytes=-N` → 206, an over-long end clamped to EOF per RFC 7233 §2.1 rather than rejected, genuinely unsatisfiable → 416, multi-range/malformed → whole-file 200 fallback, via `TranscodeFileServer::parseRange()`) and `If-Modified-Since` → 304 for immutable (non-`no-cache`) segments only. `Response.php` carries the file via `withFile(path, offset, length)` (`$filePath`/`$fileOffset`/`$fileLength`); `toWorkermanResponse()` hands it to Workerman's native `withFile()` on the (only-used-in-prod) Workerman entrypoint, while `send()`'s `finalizeFileHeaders()`/`streamFileToOutput()` mirror the identical headers for the CGI/FPM fallback (unreachable in production — streaming routes are Workerman-only, see `Application::loadStreamingRoutes()`). **`Server/Workerman/HttpHandler.php` gzip-compresses buffered text/JSON/HTML responses and tags hashed static assets as immutably cacheable (Stream Quality/ABR step S4).** `compressResponse()` (wired into all three buffered-response dispatch sites in `__invoke()`) sets `Content-Encoding: gzip` only when the client sent `Accept-Encoding: gzip`, the body is `>= GZIP_MIN_BYTES` (1024 bytes) and on a strict Content-Type allowlist (`isCompressibleType()`), and gzip actually shrinks it; it never touches a file-backed (`Response::withFile()`) response or any media/HLS/DASH type, so streaming responses are structurally excluded. `serveStatic()` sets `Cache-Control: public, max-age=31536000, immutable` for requests resolving (via the jailed, `realpath()`d `$real`, not the raw request path) under `public/assets/app/**` — the Vite content-hashed bundle.

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
- `Library/`: `LibraryManager.php` · `MediaScanner.php` (parses `S01E02`, `(2020)`; a single `ffprobe` per time-based file — on both the initial scan and a plain rescan — also derives `metadata_json['source'] = {width, height, video_codec, video_bitrate, pix_fmt, audio_codec, audio_bitrate}` for the Stream Quality/ABR ladder builder (`video_bitrate` falls back to `format.bit_rate`; the real video stream is chosen by skipping embedded cover-art streams, `disposition.attached_pic = 1`), plus the video+primary-audio `media_streams` rows (`streamLanguage()`/`streamTitle()` truncate with `mb_substr(..., 'UTF-8')`, never byte-wise `substr` — `media_streams.language` is `VARCHAR(10)` under utf8mb4, i.e. a CHARACTER budget, and `ItemRepository::addStream()` binds `language`/`title` straight into the INSERT with no `toValidUtf8()` guard, so a mid-sequence cut is rejected by MySQL with error 1366 and costs the item most of its stream set: `StreamProbeBackfill::ensureFor()` and `persistStreams()` both delete before re-inserting and neither is transactional, and `ensureFor()`'s `catch` stamps `markStreamsProbed()` regardless, so the partial set is permanent — real-DB proof in `tests/Integration/Media/StreamLanguageUtf8RoundTripTest.php`); `scripts/backfill-source-metadata.php [--library=<id>] [--limit=<n>]` backfills pre-existing items missing `source`. **`scanFlat()` fans out a bounded pool of concurrent probes (Stream Quality/ABR step S8, Track S — builds on S6's non-blocking `FfmpegRunner::probe()`):** the directory walk is chunked into `SCAN_BATCH_SIZE = 200`-file batches; brand-new, probe-eligible files in each batch are probed via `probeManyConcurrently()` — a `Swoole\Coroutine\Channel`-as-semaphore + a SECOND `Swoole\Coroutine\Channel` as a "done" signal (a two-`Channel` `WaitGroup`-equivalent join — `Swoole\Coroutine\WaitGroup` itself is avoided because PHPStan's bundled swoole stubs, used when `ext-swoole` is absent, have no `WaitGroup` stub and fail `analyze --level=9` on it) sized to `config/ffmpeg.php`'s `max_concurrent_scan_probes` (default 4, ctor param `$maxConcurrentScanProbes`) when running inside a real coroutine, else an identical-behavior sequential fallback (PHPUnit CLI, plain CLI scan scripts) — before `processFile()` runs the create/dedup/persist-streams sequence for every file in the batch sequentially, in original order, via a precomputed-probe param (`false` = "not supplied, probe internally"; a supplied `null` = "fan-out probe genuinely failed, don't re-probe"). Deliberately scoped to `scanFlat()` only — `scanSeriesPerDirectory()`/`scanSeriesDir()` stay fully sequential because they can race to find-or-create a *shared* series/season row via `resolveEpisodeParent()`, a genuinely different hazard left for a future step, documented as an explicit scope note in `scanSeriesDir()`'s own docblock.) · `FolderWatcher.php` (mtime checksum) · `ItemRepository.php` (hydrates `metadata_json`; genres are normalized into a `media_item_genres(media_item_id, genre)` join table (migration 051, `genre` COLLATE `utf8mb4_bin` for exact-match filter semantics) kept in sync by `insertGenreRows()` (INSERT-only, called from `create()` — a freshly-created item can never have pre-existing rows to clear) and `syncGenreRows()` (DELETE-then-`insertGenreRows()`, called from `update()`) — `metadata_json.$.genres` remains the canonical source, the join table is a derived index only, replacing migration 050's MySQL 8 multi-valued functional index after it reproduced real InnoDB purge-thread errors under sustained scan/rescan churn; `distinctGenres(?libraryId): list<string>` returns the DISTINCT, sorted, non-empty genre facet list by joining `media_item_genres` back to `media_items` (re-asserting `COLLATE utf8mb4_unicode_ci` so the facet list stays case-insensitive independent of the filter columns' now-`_bin` collation) so PHP never materialises the whole table; `libraryId` is bound + optional, unscoped+route-gated like `query()`; `getByAllowedGenres()`/`query()`'s genre filter use an `EXISTS` correlated subquery against `media_item_genres`; `addStream()` / `deleteStreamsByItem()` write/replace `media_streams` rows — no unique key on `(media_item_id, stream_index)`, so replacement is delete-then-insert; **`findPathsMap(array $paths): array` (S8)** batches the already-scanned-path check for a whole scan batch into one `WHERE path IN (?,?,...)` query, keyed by path, missing paths simply absent — mirrors `findByIds()`'s pattern, replacing what was previously one `findByPath()` call per candidate file; **`upsertByPath(array $data, bool $callerConfirmedAbsent = false): string`** is the race-safe find-or-create the scanner (`processFile()`, `resolveEpisodeParent()`) uses in place of a bare `create()`: it returns an existing row's id or delegates to `create()`, catching MySQL 1062 on the `(library_id, path_hash)` unique index and reusing the winning concurrent insert's row. `path_hash` is a STORED generated column — `SHA1(path)` only for the deduped types (`episode`/`movie`/`audio`/`book`) with a real path, else `NULL` (index-exempt) — added by `migrations/072_media_items_path_hash.sql`; the UNIQUE INDEX itself is added out-of-band by `migrations/cleanup_072.php` (run once post-migration: `php migrations/cleanup_072.php`), which first merges any pre-existing duplicates so the `ADD UNIQUE INDEX` cannot fail 1062) · `PathDeduper.php` (backs the `media:dedupe-paths` CLI command — `findDuplicateGroups()` groups same-path rows per library, `scoreItem()` picks the keeper (watch_history + playback_state/user_item_data + markers + rating votes/score; lowest id tiebreak), `repointReferencingTables()` moves all FK references (`UPDATE IGNORE` then `DELETE` the loser's colliding leftovers) off the loser before `deleteItem()`)
- `Metadata/`: `MetadataManager.php` priority `tmdb→local` (movie), `tvdb→fanart→local` (series); 24h cache via `metadata_refreshed_at`. Providers: `TmdbProvider.php`, `TvdbProvider.php`, `FanartProvider.php`, `LocalNfoProvider.php` — all implement `MetadataProviderInterface.php`. Shared client: `MetadataHttpClient.php`. Helpers: `PosterSrcset.php` (responsive poster `srcset`), `SceneFilenameNormalizer.php` (normalizes scene-release filenames).
- `Streaming/`: `StreamManager.php` · `QualitySelector.php` (profiles: generic, mobile-low, mobile-high, web, tv-4k) · `StreamState.php` (positionTicks, statuses) · `HlsStreamer.php` (master/variant `.m3u8`, segment `.ts`) · **ABR ladder (Stream Quality/ABR step A2 — pure logic; wired into the transcode pipeline by `A5`, see `Transcoding/` below):** `AbrLadder.php` (`build(SourceProfile, profileName='generic'): LadderResult` — deterministic, no DB/ffprobe/filesystem/clock; returns the source-clamped H.264 rung ladder 240p…2160p highest-first plus an "Original" descriptor — copy (`-c copy`) passthrough when the source is H.264+AAC and fits the profile cap, else a TRANSCODE at the source resolution (clamped to the profile cap, aspect preserved), labelled "Original (<h>p)" — never "the top rung" and never direct-play; never upscales, never exceeds the source's own bitrate, widths follow the source aspect ratio; a rung's H.264 `CODECS` level is chosen by macroblock count — `ceil(w/16)*ceil(h/16)` against a MaxFS table — not height alone, so anamorphic/DCI/ultrawide rungs advertise a legal `avc1.*` level, not an under-declared one) · `Rendition.php` (`final readonly` rung VO — `id`/`label`/`width`/`height`/`bitrate`(peak BANDWIDTH bps)/`codecs`/`url`(`toArray()` always emits `null` here — step `A7`'s `TranscodeManager::getJobVariants()` fills a real `url` only in a derived array copy of a job's persisted ladder, for the API response shape; the object/persisted-JSON form never carries one)/`isOriginal`/`isCopy`, plus derived `maxrate()`(≈1.07×`videoBitrate`)/`bufsize()`(2×maxrate)/`bandwidth()`) · `SourceProfile.php` (typed, I/O-free ladder input; `fromSourceMetadata()` adapts A1's persisted `metadata_json['source']` blob) · `LadderResult.php` (`renditions` + `original`; `streamVariants()` ALWAYS returns `[original, ...renditions]` — S49 removed the old fold that dropped a non-copy Original duplicating the top rung, because the playlist writer iterates exactly this list and a folded Original therefore never got a `media_voriginal.m3u8` written; duplicate-BANDWIDTH master levels are instead prevented by `TranscodeManager`'s SV-4.6 switchable filter (`switchableVariants()`), which withholds from the MASTER only a copy variant and a transcode `original` that `Rendition::duplicatesForAbr()` the top rung — a NON-duplicate transcode `original` is still the master's top ABR level, so the advertised level set is unchanged from v8 — while writing every variant's media playlist either way). The ladder **consumes** `QualitySelector`'s device-profile caps (`max_resolution`/`max_bitrate`, via `getProfile($profileName)`) as its clamp ceiling — it does not replace or change `QualitySelector`, which still governs direct-play-vs-transcode selection and hardware-vendor codec choice.
- `Transcoding/`: `FfmpegRunner.php` (probe/transcode/thumbnail, HLS segmenting) · `EncodeSettings.php` (single source for the effective preset / CRF / audio bitrate behind `transcoding.preset`, `transcoding.crf_h264`, `transcoding.audio_bitrate`; its `fingerprint()` is folded into the transcode job key so a settings change is not masked by a reused job, and is empty at the shipped defaults so the existing cache survives a deploy) · `TranscodeManager.php` (HLS transcode pipeline; config `config/ffmpeg.php`; `readFailureReason()` scrubs the ffmpeg log with `mb_convert_encoding($s, 'UTF-8', 'UTF-8')` BEFORE taking the tail with `mb_substr(-500)` — 500 is a CHARACTER bound, not bytes — because the value goes straight into `transcode_jobs.error` and a byte-wise tail of a log holding a non-ASCII path or container tag starts mid-sequence, so MySQL 1366 would leave a failed job unable to record its own failure). DI bindings in `src/Common/Container/Providers/TranscodeServicesProvider.php`; HLS columns added to `transcode_jobs` by `migrations/036_transcode_jobs_hls_columns.sql`; multi-variant `variants` JSON column by `migrations/049_transcode_jobs_variants.sql`. **`FfmpegRunner::buildSegmentCommand()` per-rung capped-CRF + copy passthrough (Stream Quality/ABR step A4):** caller-supplied `maxrate`/`bufsize` (bps; from A2's `Rendition::maxrate()`/`bufsize()`) emit `-maxrate`/`-bufsize` alongside `-crf`/`-preset` (never a bare `-b:v` — CRF stays the quality driver, the VBV pair is only the ceiling; omitted entirely when the caller doesn't pass both keys, reproducing the pre-A4 command byte-for-byte); `video_codec === 'copy'` emits a genuine `-c:v copy` (no scale/`-force_key_frames`/`-preset`/`-crf`/`-maxrate`/`-bufsize` — a copy can't force a mid-GOP keyframe, so its segment start may drift up to one source GOP length, acceptable only for the manually-pinned "Original" rung); `audio_codec === 'copy'` mirrors this independently (`-c:a copy`, no `-b:a`/`-ac`). `-force_key_frames`/`-output_ts_offset`/`-muxdelay`/`-muxpreload` stay identical across every transcoded rung so ABR switching is seamless. **`TranscodeManager` is now genuinely multi-variant (Stream Quality/ABR step A5), not just per-rung-encode-capable — this is where the ABR ladder actually reaches a player for the first time:** `ensureHlsJob()` resolves a `SourceProfile` (`sourceProfileForItem()` — prefers A1's persisted `metadata_json['source']`, falls back to `sourceProfileFromProbe()` on the live ffprobe result for pre-A1 items), calls `AbrLadder::build()`, and persists the resulting `LadderResult` as JSON in `transcode_jobs.variants`. `buildMultiVariantMaster()` emits one `#EXT-X-STREAM-INF` per ABR-SWITCHABLE variant (highest-first) pointing at its own `media_v{id}.m3u8` — the clamped rungs plus a transcode `original` that is genuinely distinct from the top rung, since `writeVodPlaylists()`'s SV-4.6 filter (`switchableVariants()`) withholds only a copy variant (its segment boundaries can drift) and a transcode `original` that duplicates the top rung's frame+BANDWIDTH (the low-bitrate collapse a player would merge) — while still writing a media playlist for EVERY variant so "Original" stays explicitly selectable; every variant shares one segment timeline. Segments are produced per-variant on demand: `ensureSegment(jobId, variant, index)` resolves the requested rung against the persisted ladder (`findRenditionArray()`), derives its encode params (`segmentParamsForRendition()`), and writes `seg-v{id}-NNNNN.ts` via the same shared tail (`produceSegment()`) that the legacy path uses — so the existing per-segment dedup (`.part-*`), global in-flight cap (`SegmentBusyException`→503), and LRU/TTL cache sweep already cover every variant of every job without change (both checks originally globbed on the `seg-*` filename shape, not a fixed name — see the `S2` note below for how the dedup check later stopped globbing while the cap deliberately did not). **Fully backward compatible:** a job with `variants IS NULL` (created before this deploy) falls through to the untouched legacy single-variant path (`master.m3u8` + `media_0.m3u8` + unprefixed `seg-NNNNN.ts`). **`HlsController::serveFile()` is now variant-aware (Stream Quality/ABR step A6):** it recognizes both the legacy unprefixed `seg-NNNNN.ts` shape (→ `ensureSegment($jobId, null, $index)`) and the multi-variant `seg-v{V}-NNNNN.ts` shape (→ `ensureSegment($jobId, '{V}', $index)`, `{V}` matched against the `[a-z0-9]+` rendition-id allowlist — a defense-in-depth char class that can't smuggle a traversal sequence); either shape gets identical `SegmentBusyException`→503+`Retry-After` and null→404 handling. Per-variant media playlists (`media_v{id}.m3u8`, written up front by `TranscodeManager`) need no controller change — they already served correctly as plain static files via the existing `serveJobFile()` path, no transcoder call. **The client-facing API surface now advertises `variants[]` (Stream Quality/ABR step A7 — this closes out Track A; the server-side ABR pipeline A1→A7 is code-complete end to end):** new `TranscodeManager::getJobVariants(string $jobId): ?array` reads the persisted `variants` column and mirrors `LadderResult::streamVariants()` exactly (the `original` descriptor is ALWAYS prepended as the highest entry, copy or not — S49 removed the array-level `originalDuplicatesTopRung()` fold that used to drop it), returning each rung as `Rendition::toArray()` with `url` filled to its own relative, unsigned `/hls/{jobId}/media_v{id}.m3u8` — the first point in the pipeline `url` is actually populated — or `null` for a legacy `variants IS NULL`/malformed job. `TranscodeController::start()`/`status()` sign that list into a new `variants` response key (`signVariantUrls()`, same prefix-scoped signer as `master_url`/`hls_url`) or pass through the explicit `null`. `MediaItemController::getPlaybackInfo()` separately gains a `quality_ladder` key — a pre-flight preview built purely from A1's persisted source metadata (no probe, no job created, every `url` stays `null`), using the same `?profile=`/`X-Phlix-Device-Type`-header profile resolution as `TranscodeController::start()` (`MediaItemController::mapDeviceTypeToProfile()`, kept byte-identical to the controller's own mapping, test-asserted). Player-visible quality selection is still ahead — step `E3` in `@phlix/ui` and the native clients (`G4` Roku, `G5` console). **`produceSegment()`'s in-flight bookkeeping is split between dedup and the global cap (Stream Quality/ABR Track S step S2 — hot-path perf, no API/behavior change):** the per-segment DEDUP check (`segmentEncodeInFlight()` — the higher-frequency of the two, hit by every hls.js retry of a slow segment) is now memory-based — an in-worker set (`$segmentEncodesInFlight`, keyed by absolute final segment path) unioned with a periodically-refreshed cross-worker snapshot (`$globalInFlightSnapshot`, refreshed by `reconcileInFlightSegments()` at most once/sec) — eliminating that glob entirely. The GLOBAL CAP (`countInFlightSegmentEncodes()`) deliberately stayed a real-time, whole-tree glob on every new-launch decision: an initial memory-based design for the cap too was reviewed and rejected because a shared ≤1s-stale view summed independently across all 14 HTTP worker processes could let the fleet overshoot the ceiling by up to ~14x during exactly the seek-storm scenario the cap exists to prevent (the same class of cascade the prior on-demand HLS seek-cascade fix hardened against); `SegmentBusyException`→503+`Retry-After` semantics are unchanged. Net effect: S2 removes globbing from the more-frequent dedup/retry check, not from the hot path in general — the cap glob still runs, just only on actual launch decisions (after dedup already found nothing in-flight), which are far less frequent than retries. `produceSegment()`'s `try` block also now starts before the in-flight increment (not just around the poll loop), so its `finally` reliably releases the slot on any throw after the increment.
- `UserItemDataRepository.php` (`Phlix\Media\`): per-**USER** favorites + ratings + like-level + watched flag for media items (E10). Account-level, keyed on `user_id` (like `user_settings`) — **NOT per-profile** like `watch_history`; a profile-id swap would be needed for per-profile. Backs table `user_item_data(user_id, item_id PK, favorite BOOL, rating INT NULL, like_level TINYINT NOT NULL DEFAULT 0, watched BOOL NULL, updated_at)` (`migrations/039_user_item_data.sql` + `044_user_item_like_level.sql` + `045_user_item_data_watched.sql`, FK CASCADE → `users` + `media_items`). Methods: `getItemData(userId,itemId): ?array{favorite,rating,like_level}`, `setFavorite()` / `setRating()` / `setLikeLevel()` / `setWatched()` (each `INSERT ... ON DUPLICATE KEY UPDATE` on a single column so the others are preserved), `getFavorites(userId,limit,offset)` (joins `media_items`, favorited-only, newest-first), `deleteByItem()`. Rating range **1-10 enforced in PHP** (`setRating()` throws `\InvalidArgumentException`; consts `MIN_RATING`/`MAX_RATING`); `like_level` is the signed **−2..2 thumbs axis** (−2 strongly dislike … 0 not set … 2 love; consts `MIN_LIKE`/`MAX_LIKE`, enforced in PHP) — no DB CHECK. Same flat positional `?` binding idiom as `UserRepository`/`WatchHistory`. DI: `autowire()` in `MediaServicesProvider`.

**Session** (`src/Session/`): `SessionManager.php` device sessions · `PlaybackController.php` continue-watching (<95%) · `SyncPlay/` group state, `TimeSync.php` NTP-style with `OFFSET_SAMPLE_COUNT=5`, weighted-mean offset.

**Other modules**: `src/LiveTv/` (`ChannelManager`, `GuideManager`, `Recorder`, `LiveTvManager`) · `src/Dlna/` (`ContentDirectory`, `AvTransport`, `DlnaServer`, `DeviceRegistry`, `DlnaDevice`).

**Plugins** (`src/Plugins/`): `PluginLoader.php` install / enable / disable / uninstall lifecycle (DTOs + manifest schema live in `detain/phlix-shared` under `Phlix\Shared\Plugin\*`; the full developer guide + `manifest.schema.json` live in the external [phlix-docs](https://detain.github.io/phlix-docs/plugins/developer-guide) site, **not** in-tree). On install, `PluginLoader::defaultSettings()` materialises the persisted settings array from the manifest's `settings` schema. **`required` vs `default` contract (null-fill):** every *declared* setting key always gets a slot — its declared `default` when present, otherwise **`null`**. A `required: true` setting with **no `default`** is materialised as `null` (a slot is still created), so the materialised array's key-set is always identical to the manifest's declared key-set; defaultless keys are never silently dropped. `required` is therefore **advisory metadata for the settings UI** (prompt the operator to fill it in), **not** a load-time rejection — install/enable never fail just because a required setting lacks a default.

**Theming** (`src/Theming/`): `ThemeMiddleware.php` is retained and still wired **post-render** — it runs `str_replace()` over the already-rendered HTML body looking for the literal strings `{$theme_css|raw}` and `{$theme_js|raw}`. With the Smarty page templates removed, no served page currently carries those markers (the `/app` SPA shell in `public/assets/app/index.html` does not), so the middleware is now effectively **inert** for page rendering (the substitution finds nothing and the SPA themes itself). Do not re-introduce the markers as real Smarty (`{$theme_css}` etc.).

**Marker detection types** (`src/Media/Markers/`): every writer (`IntroMarkerCandidate`, `OutroMarkerCandidate`, and the `INT UNSIGNED` DB columns) types `start_seconds` / `end_seconds` as `int`. `Detection\StoredMarkers` is read-side and must validate with `is_int()` — a previous `is_string()` check made `hasIntro()` / `hasOutro()` always return false on real production data. Both properties are `?int`. Do not "loosen" them to accept strings without changing every writer too.

**Common** (`src/Common/`):
- `Database/`: `ConnectionPool.php` (static `init()`/`getConnection('mysql')`), `QueryBuilder.php`
- `Logger/`: `LoggerFactory.php` · `LogChannels.php` (`AUTH`, `HTTP`, `WEBSOCKET`, `MEDIA`, `SESSION`, `STREAMING`) · `StructuredLogger.php` (Monolog wrapper) · `AuditLogger.php`

**Web portal** (`src/Server/WebPortal/` + `public/`): `WebPortalRouter.php` for `/api/v1/libraries`, `/api/v1/media/{id}`. `GET /api/v1/media/facets?libraryId=<uuid>` → `{genres:string[]}` (`getMediaFacets()` → `ItemRepository::distinctGenres()`; static segment registered BEFORE `{id}` so it isn't swallowed; the SPA falls back to its locally-derived genre set when this endpoint is absent; response is an object so more facet keys can be added later). Favorites/ratings routes (E10) are registered here too, inside the `$auth` (AuthMiddleware) group, and delegate to `MediaUserDataController` (`src/Server/Http/Controllers/`): `POST /api/v1/media/{id}/favorite` (mark) · `DELETE /api/v1/media/{id}/favorite` (un-favorite) · `PUT /api/v1/media/{id}/rating` body `{rating:int 1-10|null}` (set/clear; 400 non-numeric/out-of-range, 404 item missing) · `DELETE /api/v1/media/{id}/rating` (clear) · `PUT /api/v1/media/{id}/like` body `{level:int −2..2}` (required signed thumbs axis; 400 missing/non-int/out-of-range) · `POST /api/v1/media/{id}/watched` / `POST /api/v1/media/{id}/unwatched` (set/clear the watched flag) — all return `{message}`. Plus `GET /api/v1/users/me/favorites?limit&offset` → `{items,limit,offset}` (favorited rows hydrated via `ItemRepository::findById` + shaped like the media list, each with the add-only `user_data` block incl. `like_level`; missing items skipped). `GET /api/v1/media/{id}` now also carries an ADD-ONLY `user_data: {favorite:bool, rating:int|null, like_level:int}` block (injected handler-side in `getMediaItem()` via `resolveUserData()`, NOT in `MediaItemShaper`; `null` when unauthenticated, `{favorite:false, rating:null, like_level:0}` when authed with no row). **Routes live ONLY on `WebPortalRouter`, NOT `Application::loadApiRoutes()`** — both HTTP entry points and the relay dispatcher fall `/api/*` through to the same container-built `WebPortalRouter`, so one registration serves all three paths (adding to `Application` would duplicate/diverge). DI threads `UserItemDataRepository` + `MediaUserDataController` into the router via `WebPortalServicesProvider`. **The Smarty page renderer (`PageRenderer.php`) and all `public/templates/**/*.tpl` page templates + legacy `public/assets/{css,js}` page scripts were removed** — the web UI is the `/app` Vue SPA, whose HTML shell is served by `SharedUiController` from the Vite-built bundle (TypeScript source in `web-ui/` per `web-ui/vite.config.ts`, output committed to `public/assets/app/`; `ViteAssets.php` resolves the hashed asset names). Legacy page paths 302-redirect to `/app` in `public/index.php`. Smarty survives **only** for the newsletter email (`src/Admin/NewsletterGenerator.php` + `public/templates/emails/newsletter.tpl`).

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
- Smarty is used **only** for the newsletter email (`public/templates/emails/newsletter.tpl`); page templates were removed in favor of the `/app` Vue SPA (`web-ui/`).
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

Used in: `AdminHubController.php`, `Server/Workerman/HttpHandler.php` (per-request duration timing, Stream Quality/ABR step S4), benchmark scripts under `scripts/bench/`. Never use `microtime(true)` or `time()` for intervals.

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

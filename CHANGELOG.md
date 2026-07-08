# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- **Dead blocking/legacy linear-transcode path removed from `TranscodeManager`/`FfmpegRunner` (Stream Quality/ABR step S10, Track S — pure cleanup, no behavior change; Track S is now FULLY COMPLETE, S1-S10).** The on-demand, seek-aware HLS/ABR pipeline built across A1-A7 and S1-S9 fully superseded the original blocking, single-linear-encode transcode path; this step deletes the now-zero-caller remainder rather than leaving it to bit-rot alongside the live code.
  - **`TranscodeManager`** (2800 → 2467 lines): removed `startTranscode()` (the blocking linear entry point) and its `$activeJobs` in-memory registration/gate, `stopTranscode()`, `cleanupStaleJobs()`, `getActiveTranscodeCount()`, `getMaxConcurrentTranscodes()`, `getTranscodeStatus()`, `normalizeSourceInfo()`, and `normalizeProfile()` (a distinct method from the still-live `QualitySelector::normalizeProfile()`, untouched) — all confirmed to have zero real callers repo-wide, reachable in practice only via test reflection. The `$activeJobs` map itself, plus the unrelated `$globCache`/`GLOB_CACHE_TTL` static glob-memoization it required, are gone too. The still-live on-demand concurrency gate (`$maxConcurrentTranscodes` property + `getRunningJobCount()`) is unaffected.
  - **`FfmpegRunner`** (1495 → 1426 lines): removed `transcode()`, the blocking `proc_open()` + `stream_get_contents()` method that was `startTranscode()`'s only caller. `buildTranscodeCommand()` — public, independently tested, and referenced by `SoftwareProfile.php` as a behavioral-parity contract — was deliberately left fully untouched.
  - **`TranscodeManager`'s constructor lost its `EncodingHelper $encodingHelper` and `string $transcodeDir` parameters** (previously 2nd/3rd positional args) — an internal, DI-resolved constructor, not a public or wire-facing API, so this carries **no client/API impact whatsoever**. Both params became write-only (unread by any surviving method) once `startTranscode()` — their sole reader — was deleted; removing them was forced by static analysis rather than optional tidying. All 5 real construction sites (the DI provider plus 4 test constructors) were updated to match.
  - **`ItemRepository::getExcludingGenres()`** removed (2409 → 2360 lines) — pre-existing dead code (zero callers repo-wide) flagged with an `@todo S10: DELETE` note back in step S7, and carrying its own long-standing param-count bug (a computed `$genrePlaceholders` string that was never interpolated into the query, so the bound values silently over- or under-matched blocked genres). No behavior was relied upon; no test covered it.
  - The in-worker job-row cache's `invalidateJobRowCache()` call-site count drops from 5 to 3 (completion, legacy-failure, reap) now that `startTranscode()`'s and `stopTranscode()`'s sites are gone with them; the `$jobRowCache` and `invalidateJobRowCache()` docblocks were updated to match. One test, `testCancelInvalidatesCachedJobRow()`, was removed since none of the 3 surviving call sites is a behavioral analog for "cancel" — the other 3 sites already have dedicated regression tests.
  - **Deliberately left untouched, so a future reader doesn't mistake completeness gaps for oversights:** `HlsStreamer`/`DashStreamer` and the CMAF `Dash/` subtree — confirmed live (LiveTV relay + DLNA), not the dead code the original cleanup plan assumed; the `chunk-*.m4s` glob inside `countSegments()`/the reaper — kept for backward compatibility with on-disk segment artifacts from pre-rewrite jobs; `FfmpegRunner::buildTranscodeCommand()` — kept as the documented `SoftwareProfile.php` parity contract, with its own dedicated tests; and `Application.php`'s 7 `ConnectionPool::getConnection('mysql')` DI-bypass call sites — assessed and deliberately deferred as a materially different, higher-risk kind of change, not "nearby" to this diff.
  - **New orphan flagged for a future cleanup step (not fixed here, deliberately out of scope):** with `TranscodeManager`'s ctor no longer consuming it, the `EncodingHelper` class and its standalone DI registration are now fully unconsumed by any caller.
  - Full gate green: phpcs/phpstan level 9 clean, the full combined phpunit suite (4,858 tests) green, line coverage 62.34% — a slight *increase* over the post-S9 62.11% baseline, consistent with deleting untested/lightly-tested dead code (smaller denominator, no covered lines lost).

### Added

- **API surface now advertises the multi-variant quality ladder (Stream Quality/ABR step A7) — the client-facing contract this program has built toward since A1, and the step that closes out Track A: the entire server-side ABR pipeline (A1 source-metadata capture → A2 ladder builder → A3 schema → A4 per-rung FFmpeg encode/copy → A5 multi-variant playlists/segments → A6 variant-aware serving → A7 this API surface) is now code-complete end to end.**
  - **New `TranscodeManager::getJobVariants(string $jobId): ?array`** is the single source of truth for "what's playable for this job." It reads the persisted `transcode_jobs.variants` column (A3's schema, populated by A5) and mirrors `LadderResult::streamVariants()`'s dedup rule exactly: a genuine stream-copy "Original" is a real additional highest variant and is prepended, while a non-copy "Original" that merely duplicates the top clamped rung is **not** listed separately (nothing can request it as distinct anyway — see A6's `findRenditionArray()`). Each entry is the flat `Rendition::toArray()` shape (`id`/`label`/`width`/`height`/`bitrate`/`codecs`/`is_original`/`is_copy`/`video_bitrate`) with `url` filled to that variant's own relative, **unsigned** media-playlist path (`/hls/{jobId}/media_v{id}.m3u8`) — the first point in the pipeline any `url` is actually populated (A2 and A5 leave it `null`). Defensive against a missing/empty/corrupt `variants` column: any of those cases returns `null` (a legacy job) rather than throwing.
  - **`POST /api/v1/media/{id}/transcode` (`start()`) and `GET /api/v1/transcode/{jobId}/status`** both gain a `variants` key: the signed variant list from `getJobVariants()` — each `url` signed with the same prefix-scoped `SignedUrl` signer as `master_url`/`hls_url`, via a new private `TranscodeController::signVariantUrls()` — or an explicit `null` for a legacy `variants IS NULL` job (an explicit key, so a client can reliably branch on `!= null` rather than guess from key absence). Fully additive: no existing response key changed or removed.
  - **`GET /api/v1/media/{id}/playback-info` gains a `quality_ladder` key** — a **pre-flight preview** of the ladder a play would produce, built purely from A1's persisted `metadata_json['source']` blob (no `ffprobe` call, no transcode job created). Every entry's `url` is `null`, since nothing is playable yet — a deliberately different key from a real job's `variants[]` (per-item, not per-job). Resolves to `null` when the item lacks usable persisted source metadata (not yet scanned with A1, or missing width/height) — graceful degradation, not an error. Device-profile resolution is byte-identical to `TranscodeController::start()`: an explicit `?profile=` wins, else it is derived from the `X-Phlix-Device-Type` header via new `MediaItemController::mapDeviceTypeToProfile()` (`samsung-tizen`/`tizen`/`roku` → `tv-4k`, `android`/`ios` → `mobile-high`, `windows` → `generic`, anything else/missing → `web`); a controller test asserts the two controllers' mapping tables stay identical so the preview and the real job never disagree on profile.
  - Player-visible quality selection is still ahead — step **E3** in `@phlix/ui` and the native clients (**G4** Roku, **G5** console) — but `@phlix/contracts` (**B1**) can now mirror this exact, shipped response shape instead of a planned one.

### Changed

- **The coroutine DB connection pool (`config/database.php` `connections.mysql.pool_enabled`) is now ON by default (Stream Quality/ABR step S9, Track S — the last-but-one step of Track S, gated per the plan on the throughput/coherence audit below; only cleanup step S10 remains).** Previously every coroutine in a worker shared one physical `PhlixMySQLConnection`, serialised on that connection's own per-connection coroutine mutex — every DB round-trip in the worker, including on the hot HLS segment/playlist and job-status paths, queued behind whichever query happened to be in flight. `PooledMySQLConnection` (a `Workerman\MySQL\Connection`-shaped front that leases a real connection out of a bounded per-worker pool for the life of the current coroutine) has existed since Track S began, deliberately left `pool_enabled=false` pending exactly this validation pass — S1 (job-row cache) and S5 (genre-facet cache) both flagged their own invalidate-on-write coherence proofs as tied to the single-connection-mutex assumption and explicitly called out S9 as the point those proofs would need re-examining. This step is that re-examination, done as a genuine audit rather than a flag flip:
  - **The audit found one real coherence gap and fixed it.** `TranscodeManager`'s in-worker job-row LRU cache (S1) relied on the shared connection's mutex to guarantee a cache-miss `SELECT` and a concurrent status-write `UPDATE` could never interleave; under the pool, a reader and a writer hold *different* physical connections, so a reader's in-flight `SELECT` can now return **after** a writer has already invalidated the cache for that job — without a guard, the reader would re-poison the (TTL-less) cache with its stale pre-write row, and that wrong row (e.g. a completed job still reading back `running`) would never self-correct until the next write to that same job. Fixed with a new per-jobId monotonic invalidation epoch (`$jobRowEpoch`): `invalidateJobRowCache()` bumps a job's epoch on every state write (its 5 existing call sites are unchanged — only its internal behavior changed), and `jobRowEntry()`'s cache-miss path snapshots the epoch immediately before its `SELECT` and only populates the LRU if the epoch is still unchanged when the query returns; a race is served as a one-shot uncached read to that single caller instead of being trusted. The snapshot-compare-populate sequence has no yield point, so it stays atomic under Swoole's cooperative scheduler even though the DB query itself is no longer mutex-serialised. `distinctGenres()`'s TTL+LRU genre-facet cache (S5) was independently re-checked and needed no change: every genre write already calls its own eager `invalidateGenreFacets()` synchronously with no intervening yield, so there is no equivalent miss-vs-invalidate race window for it to fall into.
  - **`PooledMySQLConnection` closed a real delegation gap surfaced by running CI's exact phpunit invocation (not a filtered subset) against real MySQL with the pool defaulted on.** The front previously delegated only `query()`, the `*Trans()` family, `lastInsertId()`, and `closeConnection()` to the coroutine's leased connection — production `src/` code was confirmed (by repo-wide grep) to only ever call `query()`, so this had never surfaced — but `tests/Integration/Media/BrowseIndexUsageTest.php` (an S7 test) calls `->row('EXPLAIN …')`, the one primitive `query()` doesn't special-case for a row-returning statement. Left undelegated, that call fell through to the un-constructed parent `Workerman\MySQL\Connection::row()` (this front deliberately never calls the parent constructor, so it has no socket/settings) and crashed with `SQLSTATE[HY000] [2002] No such file or directory`. Now `row()`, `single()`, and `column()` delegate to the lease exactly like `query()`; the class docblock also now explains why the fluent query-builder (`select()`/`from()`/`where()`/…) is deliberately *not* delegated — its per-instance builder state is incompatible with per-coroutine leasing, and Phlix never uses that form anyway (always `query($sql, $params)`). A repo-wide inventory of every method actually invoked on a `Connection`-typed value confirms the delegated set (`query`, `row`, `single`, `column`, the `*Trans()` family, `lastInsertId`, `closeConnection`) is now complete — nothing else was missed.
  - **Real parallelism proven, not just "didn't crash."** A new real-MySQL integration test (`tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php`, self-skips without Swoole/reachable MySQL) measures 6 coroutines each running `SELECT SLEEP(0.20)` against a `pool_size=6` pool completing in ~0.2s total (peak in-flight = 6) versus ~1.2s at `pool_size=1` — genuine concurrent execution, not an assumption. A second test hammers the same `transcode_jobs` row with 12 readers + 6 writers using the exact UPDATE-then-invalidate shape of all 5 real call sites, across different pooled connections, and asserts no torn/cross-query values, no 2014/"commands out of sync" errors, and — the epoch guard's specific claim — that both a fresh cache read and a direct DB read converge on the final written value once writes stop (the stale-forever bug the epoch guard exists to prevent). A third test drives 36 coroutines over a 4-connection pool to exercise the blocking `acquire()` channel-pop path under exhaustion with no deadlock or leak. A standalone (non-CI-gated) soak script additionally ran 18,000 read/write ops over 161s across 3 rounds with zero errors and full convergence every round.
  - **Fallback preserved and correctly distinguishes "unset" from "explicitly off."** `DB_POOL_ENABLED=0` (or `false`/`no`/`off`) restores the single-connection mutex path (`PhlixMySQLConnection`) exactly as before this step; `pool_size` still defaults to 8 and `pool_size=1` remains a safe, fully-serialised middle ground if a smaller blast radius than a full opt-out is wanted while diagnosing. The config's boolean parsing is deliberately `getenv('DB_POOL_ENABLED') === false ? '1' : getenv('DB_POOL_ENABLED')` inside `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, not the repo's usual `?: '1'` idiom — the latter would treat the *string* `"0"` as PHP-empty and silently fall through to `'1'`, re-enabling the pool and making the documented opt-out unreachable; `=== false` correctly distinguishes a genuinely-unset env var (default on) from an explicit `"0"` (off).
  - The three already-audited coroutine/workerman-mysql traps (positional-bind re-keying, forced `utf8mb4`, emulated+buffered prepares — see `PhlixMySQLConnection`) were re-verified unchanged and correctly in place under every pooled lease; this step did not touch that class.
  - Full CI-equivalent gate green with the pool on: phpcs/phpstan level 9 clean, the full combined suite (4,859 tests) green against real MySQL 8.0.46, line coverage 62.12% (no regression versus prior Track S rounds, ≥40% gate).

- **Bundled `web-ui/` SPA bumped to `@phlix/ui` v0.74.0 (Stream Quality/ABR step F1, Track F).** Brings the player-visible quality selector (`QualityMenu` wired into the control bar, Auto/discrete-rung/Original level switching via the hls.js level API, visual+a11y baselines — steps E1-E5) into the bundle the server serves at `/app/*`. No server-side code change; `web-ui/package.json`'s `@phlix/ui` pin moves `v0.73.1`→`v0.74.0` and `public/assets/app/**` is rebuilt and committed (Vite/Rollup content-hash chunk-name churn only across the whole bundle, not a regression — the non-deterministic-rebuild gotcha already documented against E5/F2).

- **`MediaScanner::scanFlat()` fans out a bounded pool of concurrent ffprobes instead of probing one file at a time, and batches the already-scanned-path lookup into a single query (Stream Quality/ABR step S8, Track S — scan-throughput perf, no API/behavior change; builds directly on S6's non-blocking `FfmpegRunner::probe()`).** S6 made a single `probe()` call non-blocking (yield to the event loop instead of freezing the whole worker), but `scanFlat()` still issued and awaited those probes strictly one file at a time — under-using the very capability S6 added, since nothing stopped several non-blocking probes from running concurrently. Every candidate file in a scan batch also paid its own `ItemRepository::findByPath()` round-trip just to check whether it was already indexed.
  - **New `ItemRepository::findPathsMap(array $paths): array`** replaces the per-file `findByPath()` check with a single `WHERE path IN (?,?,...)` query per batch, returning `[path => hydrated row]` for every path that already exists (missing paths simply absent, not a null entry); empty input short-circuits without querying. Mirrors the existing `findByIds()` pattern.
  - **New `MediaScanner::probeManyConcurrently(array $paths): array`**, gated by the same coroutine-availability guard S6's `runProbeCommand()` uses (`extension_loaded('swoole') && class_exists(Coroutine::class) && Coroutine::getCid() > 0`). Inside a real Swoole coroutine, one `Swoole\Coroutine\Channel` sized to the concurrency cap acts as a semaphore and a SECOND `Swoole\Coroutine\Channel` (sized to the batch count) acts as a "done" signal that the caller pops once per path to join every launched probe — a `WaitGroup`-equivalent join built from two `Channel`s rather than `Swoole\Coroutine\WaitGroup` itself, because PHPStan's bundled swoole stubs (used when `ext-swoole` is absent, e.g. CI's PHPStan job) have no `WaitGroup` stub and fail `analyze --level=9` on it. A probe failure (thrown exception, or `Coroutine::create()` itself refusing to schedule under Swoole's `max_coroutine` ceiling) resolves that one path to `null` and releases its slot/signals done without stranding the pool or aborting siblings. Outside a coroutine (PHPUnit CLI, plain CLI scan scripts) it falls back to the exact pre-S8 sequential probe-per-path loop — behaviorally identical to before S8 in that context.
  - **New config knob `config/ffmpeg.php` → `max_concurrent_scan_probes` (default 4)**, wired through `MediaServicesProvider` into a new `MediaScanner` constructor parameter, mirroring the existing `max_concurrent_transcodes` knob's style/placement. `scanFlat()`'s directory walk is restructured into fixed-size batches (`SCAN_BATCH_SIZE = 200`, deliberately smaller than `DuplicateFinder`'s 500 since each candidate here may hold open a coroutine-pool probe slot) — concurrency is capped independently of batch size, and confined entirely to the read-only, DB-free ffprobe step; the create/dedup/`persistStreams` sequence for each file still runs sequentially, in original candidate order, exactly as before.
  - **Deliberately scoped to `scanFlat()` only — `scanSeriesPerDirectory()`/`scanSeriesDir()` (episode/series directory scans) are left fully sequential, not an oversight.** Those code paths can create a *shared* parent series/season row via `resolveEpisodeParent()` when two files in the same batch are the first to reference that container; making that path concurrent is a genuinely different (find-or-create race) problem than fanning out read-only probes, and is left for a dedicated future step. Documented in `scanFlat()`'s docblock and as an explicit scope note directly in `scanSeriesDir()`'s docblock.
  - Test coverage: +9 tests (`MediaScannerTest`: sequential-fallback-outside-coroutine regression, bounded-concurrency proof via a live in-flight counter, correct per-path result attribution under concurrency, single-probe-failure isolation, the `Coroutine::create()`-refusal edge case (mutation-tested — the fix's absence provably deadlocks), and the `processFile()` precomputed-probe plumbing; `ItemRepositoryTest`: `findPathsMap()`'s empty short-circuit, single-query/placeholder shape, and result keying).

- **Genre filtering moves off the MySQL 8 multi-valued functional index (MVI) introduced by S7's migration 050 onto a normalized `media_item_genres` join table (Stream Quality/ABR step S7b, Track S — risk-driven redesign, no API/behavior change; supersedes only the genre-index portion of S7 — `sort_title`/`content_rating` and their indexes are untouched and were never implicated).** A dedicated stress test (50 rounds × 300 rows = 15,000 rows of create/cascade-delete churn against a prod-version-matched MySQL 8.4.10, modeling ~50 consecutive full rescans of a 300-item library) proved the risk this program's plan had flagged as needing empirical validation was real, not log-only noise: **29,900** `[MY-012869]` InnoDB purge-thread "record not found on update" errors, recurring continuously across 58 distinct one-second buckets spanning the entire run — up from 73 errors in a single small CI round — scaling with churn volume rather than converging to a fixed benign count. That fails the bar for "contained," so the MVI is replaced rather than accepted.
  - **New migration `migrations/051_media_item_genres_join_table.sql`** creates `media_item_genres (media_item_id, genre)` — `PRIMARY KEY (media_item_id, genre)`, `INDEX idx_media_item_genres_genre (genre)`, `FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE` — idempotently backfills it from every existing row's `metadata_json.$.genres` via `INSERT IGNORE ... JSON_TABLE(...)`, then drops `idx_media_items_genres` (the MVI). `metadata_json.$.genres` remains the single canonical source of truth (API responses and `MediaItemShaper` still read it directly, unchanged); the join table is a derived index only, kept in sync by `ItemRepository::insertGenreRows()` (INSERT-only, from `create()`) and `syncGenreRows()` (DELETE-then-insert, from `update()`).
  - **Read paths rewritten from `MEMBER OF`/`JSON_TABLE` to joins/`EXISTS` against the new table:** `getByAllowedGenres()` and `buildFilters()`'s genre predicate now use a correlated `EXISTS (SELECT 1 FROM media_item_genres ...)` (preserving the "allowed genre OR item has no genres at all" semantic), and `distinctGenres()` (S5's TTL+LRU-cached facet scan) now does a plain `JOIN` read instead of unnesting `JSON_TABLE` — the cache-miss SQL changed, the cache mechanism itself did not.
  - **Deliberate case-sensitivity split, not an oversight.** `media_item_genres.genre` is declared `VARCHAR(255) COLLATE utf8mb4_bin` specifically so the filtering predicates (`getByAllowedGenres()`/`buildFilters()`) keep the exact case/accent-**sensitive** exact-match semantics the old `MEMBER OF` predicate had — a plain `_unicode_ci` column would have silently loosened filter matching (caught in review by direct empirical comparison against real MySQL: `'action' MEMBER OF (...)` vs `Action` stored → no match under the old code; `WHERE genre IN ('action')` against a `_unicode_ci` column → a false match). `distinctGenres()` is the one deliberate exception: its facet-list query re-asserts `COLLATE utf8mb4_unicode_ci` explicitly on the selected/ordered column so the returned facet list stays case-insensitive-deduplicated exactly as it was pre-051 (e.g. `"Action"`/`"action"`/`"ACTION"` on the same item still collapse to one facet), independent of the now-case-sensitive storage/filter collation. Neither query-time `COLLATE` override touches the filtering `EXISTS`/`IN` predicates, so `idx_media_item_genres_genre` stays fully index-usable (verified via `EXPLAIN` on real MySQL 8.0.46 and 8.4.10 — no full scan either before or after).
  - `getExcludingGenres()` (pre-existing dead code, S10-owned) left functionally untouched; only its `@todo` reworded since it no longer references the removed `MEMBER OF` form.
  - `scripts/backfill-sort-metadata.php`'s genre-related comment corrected (comment-only) — it previously described the now-removed MVI as auto-deriving genre indexing; genres are now covered by migration 051's own idempotent backfill plus the write-path sync above, with no PHP CLI equivalent needed.
  - Test coverage: `BrowseIndexUsageTest` rewritten to assert (via real `EXPLAIN`) that the rewritten `EXISTS` genre-membership query resolves against `idx_media_item_genres_genre`/`PRIMARY` with no full scan, empirically verified against both MySQL 8.0.46 (CI's pinned image) and 8.4.10 (prod's version); +9 tests overall covering the case-sensitivity/collation split, `insertGenreRows()`/`syncGenreRows()` write-path sync, and the rewritten filter/facet SQL shapes.
  - Re-running this step's own stress harness against the new join-table design (rather than the MVI) is the closing condition for **I3**, the deploy step gated on this redesign shipping clean.

- **Library browse/filter listings materialize the article-stripped sort key and content rating into indexed columns, and genre filtering moves onto a MySQL 8 multi-valued functional index (Stream Quality/ABR step S7, Track S — pure performance/hardening work, no API/behavior or ordering change; pairs with S5's genre-facet cache and S1's job-row cache). Note: the genre-index portion of this step was superseded by S7b above after a stress test proved it carries real InnoDB purge-thread risk under sustained churn — `sort_title`/`content_rating` (the rest of this entry) are unaffected and remain current.** Every library listing previously ordered `ORDER BY <SortTitle::sqlExpression('name') CASE/LOWER/SUBSTRING>` — a per-row function MySQL can never satisfy from an index, forcing a filesort on every page load — and genre/rating filters ran `JSON_CONTAINS(metadata_json, ?, '$.genres')` / `JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.rating'))`, neither of which is index-usable, so both full-scanned `media_items`.
  - **New migration `migrations/050_media_items_sort_indexes.sql`** adds two nullable columns — `sort_title VARCHAR(255)` (the article-stripped sort key) and `content_rating VARCHAR(32)` (copied out of `metadata_json.$.rating`, named distinctly from the per-user `user_item_data.rating` to avoid ambiguity) — plus a composite index `(library_id, type, sort_title, name)` (a superset of the plan's `(library_id, type, sort_title)`: the trailing `name` removes the residual filesort the stable-paging tiebreak would otherwise force; verified with EXPLAIN — `Extra` is filesort-free) and a single-column `(content_rating)` index for rating filters/parental-control range scans. The migration also backfills both columns for existing rows inline (guarded `IS NULL`, so a re-run or a later run of the new CLI below is a no-op) via the exact CASE `SortTitle::sqlExpression()` emits, so historical ordering is reproduced byte-for-byte, not just approximated.
  - **Genres deliberately stay inside `metadata_json.$.genres` — no new join table.** Instead, the migration adds a MySQL 8.0.17+ **multi-valued functional index** (`ADD INDEX ((CAST(metadata_json->'$.genres' AS CHAR(255) ARRAY)))`), and `ItemRepository`'s genre filters (`buildFilters()`, `getByAllowedGenres()`) are rewritten from `JSON_CONTAINS` to `? MEMBER OF (metadata_json->'$.genres')` — index-resolvable, same case/accent-sensitive exact-match semantics as before. This was chosen over a normalized join table specifically to keep **S5's genre-facet cache coherent**: `distinctGenres()`'s `JSON_TABLE` facet scan (and its `invalidateGenreFacets()` TTL+LRU cache) already reads genres from this exact blob, so a join table would create a second genre-storage location that every genre write would need to keep in sync — and that S5 wiring stays completely untouched by this step. No genre backfill is needed either: the index is derived automatically from the existing blob.
  - **`ItemRepository::create()`/`update()`** now populate `sort_title` (via `SortTitle::from()`, whose output is branch-for-branch identical to the retired runtime `SortTitle::sqlExpression()`) and a new `extractContentRating()` helper populates `content_rating` on both the write path and the new backfill CLI, so live writes, the CLI, and the migration's inline backfill can never drift. `update()` mirrors the existing `canonical_key` lockstep pattern: a `name` change re-derives `sort_title`, a `metadata_json` (re)write re-derives `content_rating`, and an explicit caller-supplied value for either column always wins. The runtime `SortTitle::sqlExpression()`/`letterSqlExpression()` calls are fully removed from the query path (`titleOrder()`, the new private `letterExpression()`, `letterCounts()`, `valueBuckets()`) in favor of reading the materialized columns.
  - **New offline CLI `scripts/backfill-sort-metadata.php [--library=<id>] [--limit=<n>]`** — matches step A1's established backfill-script pattern (`updated`/`skipped`/`failed` buckets, skip-if-already-consistent, non-zero exit on any failure) — for populating `sort_title`/`content_rating` on rows that predate this migration on an instance where the inline migration backfill hasn't (yet) run, or was interrupted.
  - **Deploy caveat — read before running migration 050 on a live box.** Unlike this migration's other statements, the multi-valued genre index's `ADD INDEX` is **not** covered by `MigrationRunner::isExpectedIdempotentError()` and will **hard-fail the whole migration** if any existing row has a genre element longer than 255 characters or a `$.genres` value that isn't a JSON array. The migration's leading comment carries a "DEPLOY RUNBOOK NOTE" with copy-paste pre-flight SQL to detect and clean up any such rows before migrating; **the I3 deploy step must read and follow it.**
  - `getExcludingGenres()` — confirmed dead code repo-wide, and the only remaining un-indexed `JSON_CONTAINS`-based genre path — was left unmigrated to avoid scope creep into its pre-existing param-count bug; flagged with an `@todo` for a future cleanup pass.
  - Test coverage: unit coverage on `extractContentRating()`'s shape handling (array/string/missing/malformed), the create/update materialization write paths (mutation-tested), and the rewritten rating/genre SQL; a new self-skipping integration test (`tests/Integration/Media/BrowseIndexUsageTest.php`, mirrors `SortTitleOrderingTest`'s convention) asserts via `EXPLAIN` that the composite index is applicable with no filesort and the genre `MEMBER OF` predicate resolves against the multi-valued index.

- **`FfmpegRunner::probe()` no longer stalls the whole worker, and `ensureHlsJob()` reuses A1's scanned source metadata instead of re-deriving it from a live probe (Stream Quality/ABR step S6, Track S — pure performance/hardening work, no API/behavior change; pairs directly with A1's source-metadata capture).** Previously `probe()` executed ffprobe via a plain `shell_exec()`. The server's coroutine hook mask (`SwooleRuntime::SAFE_HOOK_NAMES`) is a deliberate **allowlist**, not a blocklist, and does not hook `proc_open`/`exec`/`shell_exec` — so that blocking call froze the *entire* Workerman worker (every concurrent connection) for the full duration of the ffprobe process. `probe()` sits on two hot paths: `TranscodeManager::ensureHlsJob()` (every play-start of a non-direct-play title) and the library scanner (per scanned file), so under real load this was a worker-wide stall on every play button press.
  - **Non-blocking dispatch (the core fix).** New private `FfmpegRunner::runProbeCommand()` runs the ffprobe command via `Swoole\Coroutine\System::exec()` whenever it is called inside a genuine coroutine (`Swoole\Coroutine::getCid() > 0`, matching the repo's established idiom) — a native coroutine primitive that yields to the event loop while ffprobe runs, so other connections keep being served, and which works regardless of the curated hook mask (chosen specifically because `proc_open` is *not* hooked here). Outside a coroutine — the CLI scanner/backfill, unit tests, any non-Swoole runtime — it falls back to the original blocking `shell_exec()`, which is correct there since there is no event loop to stall. Both paths run the command through `/bin/sh -c`, so the existing `escapeshellarg()` quoting and `2>/dev/null` redirect behave identically; `probe()`'s JSON parsing and public return shape are byte-for-byte unchanged. This alone benefits every caller of `probe()` (`ensureHlsJob`, the scanner, `SubtitleController`, `TrailerFinder`, `ThemeMediaFinder`, `TrickplayGenerator`, the legacy `startTranscode`), not just the two named hot paths.
  - **Skip the probe as source-of-truth when scanned metadata is fresh.** New `TranscodeManager::sourceMetadataFresh()`/`persistedDurationSeconds()` helpers check whether A1 already persisted real source dimensions (width + height > 0, mirroring the existing `sourceProfileForItem()` gate) and a positive `metadata_json['duration_seconds']`. When fresh, `ensureHlsJob()` takes the source duration straight from the scan — skipping both the probe-derived duration and the now-redundant idempotent `persistProbedDuration()` write — and the ABR ladder is already built from the persisted profile via the pre-existing `sourceProfileForItem()` preference. Crucially, a probe **failure** is now tolerated on this path: previously any `null` probe result threw `"Failed to probe media file"` and refused to play the title; now that failure only degrades the request (no embedded-subtitle sidecars for that request) when the scan already described the source well enough to build the job without it. Without fresh persisted metadata, a `null` probe still throws exactly as before.
  - **Known, deliberately scoped residual: the probe call is still issued even on the fresh-metadata path.** A1 persists only the video + primary-audio summary (`metadata_json['source']` / `media_streams`) — never subtitle stream descriptors — so embedded TEXT-subtitle detection (`SubtitleExtractor::detectTextTracks()`) still needs the live ffprobe stream list. Skipping the probe outright would silently drop embedded subtitles on the HLS path for backfilled items, which is unacceptable, so the probe is retained but is now (a) non-blocking and (b) no longer the source of truth for the ladder/duration and no longer a hard dependency when metadata is fresh. Fully eliminating this residual probe would require persisting subtitle-track descriptors at scan time (touching A1's persisted shape) — left as a small, well-scoped follow-up rather than reopening the twice-reviewed A1 surface here; the code carries an explicit warning comment at the `detectTextTracks()` call site so a future change doesn't remove the probe before that persistence lands.
  - Test coverage: `FfmpegRunnerTest` covers the non-coroutine `shell_exec()` fallback (JSON parse, null-on-missing-binary, null-on-non-JSON-output) and a real-coroutine test (`Swoole\Coroutine\run()`) that exercises the coroutine-exec branch itself — the actual "no worker-wide stall" mechanism, mutation-tested to confirm it genuinely drives that branch rather than silently degrading to the fallback. `TranscodeManagerTest` covers the fresh-metadata duration/ladder skip (persisted value wins over a stale probe value, no redundant UPDATE issued), probe-failure tolerance on the fresh path, and the preserved throw-on-failure behavior when metadata is not fresh.

- **`ItemRepository::distinctGenres()` is now backed by an in-worker TTL+LRU cache (Stream Quality/ABR step S5, Track S — pure performance/hardening work, no API/behavior change; pairs with library work and, per the plan, is a prerequisite for S9's connection-pool validation).** Previously every genre-filter-UI load (`GET /api/v1/media/facets` → `WebPortalRouter::getMediaFacets` → `distinctGenres()`) unnested `metadata_json.$.genres` via a `JSON_TABLE` full scan of `media_items`, even though the genre set only changes when items are scanned or edited.
  - A bounded map (`private array $genreFacetCache`, `GENRE_FACET_CACHE_MAX = 256` entries) caches each `{genres: list<string>, expires_at: int}` result keyed by scope — a library UUID for a scoped call, or the `GENRE_FACET_GLOBAL_KEY` sentinel (`"\0all-libraries"`, chosen so it can never collide with a real UUID) for the unscoped/all-libraries call. TTL is `GENRE_FACET_CACHE_TTL_MS = 300_000` (5 minutes), measured against a new `monotonicMs()` helper (`hrtime(true)`-based, mirroring `TranscodeManager::monotonicMs()` and the repo's documented monotonic-clock convention) so the cache is immune to NTP/DST clock jumps. A fresh hit returns with zero DB access; a miss or expired entry falls through to the unchanged `JSON_TABLE` query and repopulates the cache.
  - **The 256-entry bound is a security control, not a tuning knob.** `WebPortalRouter::getMediaFacets` passes the raw, unvalidated caller-supplied `?libraryId=` query parameter straight into the cache key, and `ItemRepository` is a shared, per-worker-resident singleton (PHP-DI `autowire()`) — so the scope-key space is attacker-influenced. Without a bound, an authenticated caller could force unbounded resident-memory growth by cycling through fabricated library ids. Eviction is **genuine LRU, not FIFO**: both the cache-hit path and the recompute/populate path `unset()` the existing key immediately before reassigning it, which is required because PHP does not otherwise reposition an existing array key on a plain value reassignment — so the coldest, least-recently-*read* scope is evicted at the bound (`array_key_first()`), not merely the least-recently-*written* one. (An earlier round of this change got the recompute-path repositioning wrong — see the regression test below — and review/independent test-engineering passes closed it.)
  - **Invalidation** is wired into every write path in `ItemRepository` that can change the genre set, via a new public `invalidateGenreFacets(?string $libraryId = null)`: `create()` invalidates that item's library scope (`batchCreate()` is covered transitively, since it calls `create()` per item); `update()` invalidates globally (`null`, flushing every scope) but **only** when `metadata_json` is among the written fields — a metadata-free update (e.g. a rename) leaves the cache warm; `delete()` invalidates the item's library scope (or globally, when the library isn't known at the call site); `deleteByLibrary()` invalidates that library's scope. Any library-scoped invalidation also drops the global/all-libraries scope, since it spans every library's genres. A `null` argument always flushes the whole map. Cross-worker note: a scan running in a different worker process cannot reach this worker's in-memory map, so a scanner-driven genre change surfaces to a facet-serving worker after at most one TTL window (5 minutes) — this is the documented purpose of the TTL, not a gap; same-worker writers never observe their own stale cache thanks to the eager invalidation calls above.
  - Test coverage: 9 new unit tests cover cache-hit/miss behavior, independent per-scope caching, every invalidation call site (including the metadata-changed-vs-not `update()` branches), global-vs-library scope invalidation, and the exact LRU eviction-at-bound and stale-recompute-repositioning behaviors (both mutation-tested — reverting either fix makes its regression test fail as expected). New code reached 100% statement coverage.

- **`HttpHandler` gzip-compresses text/JSON/HTML responses, tags content-hashed static assets as immutably cacheable, and measures request duration with `hrtime(true)` (Stream Quality/ABR step S4, Track S — independent quick win, no API/behavior change to any response body; media/streaming responses are untouched).**
  - **`Content-Encoding: gzip`** is applied by a new private `compressResponse()`, wired into the three buffered-response dispatch sites in `__invoke()` (the `Application` router response, the `WebPortalRouter` `/api/*` fallback, and the SSR page-rendering path). It compresses only when the client sent `Accept-Encoding: gzip`, the body is `>= GZIP_MIN_BYTES` (1024 bytes — below a single ~1.5 KB TCP segment, compression buys nothing on the wire while still costing CPU and the ~20-byte gzip envelope), the response isn't already `Content-Encoding`-tagged, gzip (level 6) actually shrinks the body, and — the key safety gate — the Content-Type is on a strict allowlist (`isCompressibleType()`: any `text/*`, plus `application/json`, `application/javascript`, `application/xml`, `application/manifest+json`, `application/ld+json`, `application/rss+xml`, `application/atom+xml`, `image/svg+xml`). On success it sets `Content-Encoding: gzip`, merges `Accept-Encoding` into `Vary` (`mergeVaryAcceptEncoding()`, dedup-safe), and rewrites `Content-Length` to the compressed size (dropping any stale case-variant header first).
  - **Media/streaming responses are never gzipped, verified by two independent guards.** Guard 1: `compressResponse()`'s first check is `$response->filePath !== null` — every HLS/DASH playlist and segment is served via `Response::withFile()` (S3), and direct-play byte ranges/avatars return raw `WorkermanResponse`s that never even reach this method, so a file-backed or already-bypassed response can never be buffered/compressed. Guard 2: the Content-Type allowlist has no video/audio/image/`octet-stream` entry and specifically excludes the HLS `application/vnd.apple.mpegurl`/DASH `application/dash+xml` playlist types (no `+xml`-suffix wildcard that could net `dash+xml`). Either guard alone excludes the entire streaming surface.
  - **`Cache-Control: public, max-age=31536000, immutable`** is now set by `serveStatic()` for any request resolving under the Vite-built `public/assets/app/**` bundle (content-hashed filenames — the bytes for a given URL never change, so a year-long cache with no revalidation is safe). The decision is gated on the **resolved, jailed filesystem path** (`$real`, the same value already used to serve the file and enforce the `publicRoot` jail), not the raw request-path string — a request whose path merely *starts with* `/assets/app/` but traverses outside it via `..` (e.g. `/assets/app/../foo.js`, or a sibling directory like `/assets/appendix/...`) does not get tagged immutable, even though `realpath()` doesn't normalize `..` in the raw string. Non-`/assets/app/` static files (favicon, robots.txt, etc.) are unaffected.
  - **Request-duration timing switched from `microtime(true)` to `hrtime(true)`** (the repo's documented monotonic-clock convention) — `__invoke()` captures `$startTime = hrtime(true)`; `recordRequestMetrics()`'s `$startTime` parameter is now `int` (was `float`) and computes `$elapsedMs` from the nanosecond difference before converting to milliseconds, so a long-running worker's large `hrtime` values keep full sub-millisecond precision and stay immune to system clock adjustments. Purely a precision/monotonicity improvement — the metric remains a duration, not a timestamp — and only this one request-timing site was touched (the repo's many other `microtime(true)`/wall-clock uses are unrelated and intentionally untouched).

- **HLS/DASH segments and playlists now stream through Workerman's event-loop file sender instead of being buffered into worker memory (Stream Quality/ABR step S3, Track S — structural performance fix, no API/behavior regression; pairs with A6's variant-aware serving, and unblocks hub Track D's deferred `D3` streaming pass-through since the origin no longer buffers whole segments).** `TranscodeFileServer::serveJobFile()` (the trait shared by `HlsController`/`DashController`) previously read every playlist/segment body — including every ~1–6 MB `.ts`/`.m4s` HLS/DASH segment — whole via `file_get_contents()` and advertised `Accept-Ranges: bytes` while never actually honouring a `Range` request. Now:
  - **`Response` gained a file-backed mode** — `withFile(string $path, int $offset = 0, int $length = 0): self` plus new `$filePath`/`$fileOffset`/`$fileLength` properties. `toWorkermanResponse()` hands the path straight to Workerman's native `withFile()` (the same event-loop file sender direct-play already used via `serveMediaStream`), which streams the body chunked for files ≥ 2 MB and auto-derives `Content-Length`/`Accept-Ranges: bytes`/`Last-Modified`, plus `Content-Range` + 206 whenever an offset/length window is supplied. **No route registration or handler-construction change was needed** — the dual-entrypoint boundary (`public/index.php` vs `start.php`→`HttpHandler`→`Application::loadStreamingRoutes()`) is untouched, because `Response` itself now carries the file rather than the controllers being lifted out of the router.
  - **Real `Range` support**, via a new shared `TranscodeFileServer::parseRange()`: single ranges `bytes=A-B`/`bytes=A-` → 206 (an over-long `B` is clamped to `$fileSize - 1` per RFC 7233 §2.1 and served, not rejected); suffix ranges `bytes=-N` ("last N bytes") → 206, with an oversized `N` clamped to the whole file; a `start` at/past EOF, `start > end`, or a zero-length suffix → genuinely unsatisfiable → 416 with `Content-Range: bytes */{size}`; a multi-range or otherwise-unparseable header falls back to a whole-file 200 (an RFC-permitted fallback, not a special case the server has to reject).
  - **Conditional GET**: an `If-Modified-Since` matching the file's mtime now short-circuits to 304 — but only for immutable, `Cache-Control: public, max-age=31536000` segments; `no-cache` playlists/manifests (rewritable mid-encode) are never short-circuited.
  - **CGI/FPM fallback parity.** `Response::send()` — the non-Workerman code path, unreachable in production since streaming routes are Workerman-only — gained a private `finalizeFileHeaders()` (computes the identical `Content-Length`/`Accept-Ranges`/`Content-Range` + forced 206 that Workerman's own `withFile()` derives, traced against `vendor/workerman/workerman/src/Protocols/Http.php::encode()`) and a bounded-chunk `streamFileToOutput()`, so a file-backed `Response` degrades gracefully and answers a Range request identically no matter which entrypoint renders it.
  - **Structural resident-memory/GC win.** No segment or playlist body is copied into a PHP string on the request-handling path anymore, so a large concurrent HLS/DASH load no longer pins a full in-memory copy of every in-flight segment inside the resident Workerman worker. (Verified by code inspection — no `file_get_contents()` remains on the segment/playlist serving path; live resident-memory measurement under load is out of scope for this environment.)
  - Fully backward compatible: `HlsController::serveFile()`/`DashController::serveFile()` only gained a `Request $request` parameter threaded through to `serveJobFile()` (needed to read `Range`/`If-Modified-Since`); the S1 job-row cache, S2's per-variant in-flight dedup/global cap → `SegmentBusyException`/503, 404 self-heal, and the signed-URL middleware group are all upstream of `serveJobFile()` and untouched.

- **`TranscodeManager`'s on-demand segment concurrency gate splits its two in-flight checks apart (Stream Quality/ABR step S2, Track S — pure performance/robustness work, no API/behavior change; pairs with S1's job-row cache).** `produceSegment()` gates a new segment launch with two checks, and they no longer share one mechanism:
  - **The per-segment DEDUP check (`segmentEncodeInFlight()`) — hit on every request, since it's what catches a client retry of a slow segment (the routine hls.js first-byte-timeout re-request) and makes it piggyback on the already-running encode instead of spawning a duplicate `ffmpeg` — is now memory-based**, not a filesystem glob. A new in-worker set (`$segmentEncodesInFlight`, keyed by the absolute final segment path, which embeds the `(jobId, variant, index)` tuple) is unioned with a periodically-refreshed cross-worker snapshot (`$globalInFlightSnapshot`), kept fresh by a new `reconcileInFlightSegments()` throttled to at most one glob per second. This removes the `glob('{final}.part-*')` call from the highest-frequency check on the hot retry/seek path entirely.
  - **The global concurrency CAP (`countInFlightSegmentEncodes()`) intentionally remains a real-time, whole-tree `glob()` on every new-launch decision — it was deliberately NOT converted to read from the same memory-based bookkeeping as the dedup check.** An initial version of this step did make the cap memory-based too; review caught that because the cap is enforced independently by each of the 14 HTTP worker processes, a shared ≤1-second-stale snapshot could let the fleet collectively overshoot the advertised ceiling by up to ~14x — and specifically during a seek storm, the exact scenario the cap exists to bound (the same class of cascade the prior on-demand HLS seek-cascade incident hardened against). The fix restored a live glob for the cap check, preserving the original ~100ms `.part-*`-visibility accuracy bound. **This means S2 does not eliminate all filesystem globbing from the segment hot path** — only the dedup/retry check's globbing is gone; the cap-check glob still runs on every actual launch decision (which happens only after the dedup check already found nothing in-flight for that exact segment, so it's reached far less often than the per-request dedup check). The net effect is still a real, strictly-better-or-equal reduction in glob calls for every traffic pattern versus pre-S2 — just narrower in scope than "zero hot-path globbing."
  - `produceSegment()`'s `try` block now starts immediately before the in-flight increment (previously it wrapped only the poll loop), so the `finally` that releases the slot is reached on any throw after the increment — not just a poll-loop failure — closing a leak window a prior version left open.
  - `SegmentBusyException`→503+`Retry-After`, per-`(jobId, variant, index)` isolation, and all other A5/A6 dedup/cap/sweep/HLS-serving behavior are unchanged from every caller's perspective.

- **`TranscodeManager::getJobRow()` is now backed by an in-worker LRU cache (Stream Quality/ABR step S1, Track S — pure performance work, no API/behavior change; pairs with A5/A6's multi-variant on-demand HLS).** Previously every HLS segment/playlist request re-ran a `SELECT *` against `transcode_jobs` under the shared connection's coroutine mutex, even though a job row is written once at creation and thereafter only its terminal `status` changes — the dominant per-segment DB cost on a hot seek/playback path. Now:
  - A bounded map (`JOB_ROW_CACHE_MAX = 256`, oldest-first/MRU eviction) caches the narrowed row keyed by job id, and the parsed `variants` JSON ladder is memoised alongside it so repeat reads (`ensureSegment()`, `getJobVariants()`, …) never re-`json_decode` it — preserving the exact legacy `NULL`-vs-corrupt-JSON distinction A5 relies on.
  - The `SELECT` itself is narrowed from `*` to `JOB_ROW_COLUMNS` — `id, status, input_path, hls_dir, duration_seconds, segment_seconds, segment_params, subtitle_tracks, variants` — the exact union of columns every real call site reads (a new test pins this list against every call site so a future narrowing regression fails loudly instead of silently nulling a field).
  - The cache is invalidated at all 4 sites that mutate a job row: the terminal-status sync in `getJobReadiness()`, `reapStaleRunningJobs()`, `cancelTranscode()`/`stopTranscode()`, and the legacy `startTranscode()` failure path — so a cache hit is always coherent with the last write.
  - No explicit coroutine lock was needed: `TranscodeManager` is confirmed the sole writer of `transcode_jobs` (repo-wide grep), and the shared connection's existing coroutine mutex already serializes the query round-trip on a miss, ruling out a populate-vs-invalidate race under the **current single-connection model**. **This coherence guarantee is tied to that single-connection mutex** — when step **S9** later validates and enables the coroutine DB connection pool, this cache's invalidate-on-write design will need re-examination (parallel connections could interleave a populate with a concurrent write in a way the current mutex prevents); tracked as a known S9 follow-up, not an open issue here.

- **`HlsController::serveFile()` now serves both segment filename shapes of an on-demand HLS job (Stream Quality/ABR step A6) — completes the server-side serving half of the multi-variant feature landed by step A5.** Two `seg-…\.ts` shapes are recognized and both route to the same `TranscodeManager::ensureSegment($jobId, $variant, $index)` (A5's signature), with identical back-pressure/self-heal behavior for either:
  - Legacy unprefixed `seg-NNNNN.ts` → `ensureSegment($jobId, null, $index)` — selects a `variants IS NULL` single-variant job, unchanged from before A5.
  - Multi-variant `seg-v{renditionId}-NNNNN.ts` (e.g. `seg-v1080p-00042.ts`, `seg-voriginal-00007.ts`) → `ensureSegment($jobId, '{renditionId}', $index)`, where `{renditionId}` is matched against a `[a-z0-9]+` allowlist — the fixed set of ids `AbrLadder` produces (`240p`…`2160p`, `original`). The regex is anchored and excludes `.`/`/`/`\`, so it is a defense-in-depth guard that cannot smuggle a path-traversal sequence even before the earlier `isSafeFilename()` gate; a filename that matches neither `seg-…` regex falls through to a plain static-file lookup that 404s (never reaches the transcoder).
  - Either shape gets the same `SegmentBusyException` → `503` + `Retry-After` back-pressure and a `null` result → `404` self-heal (the client retries once the segment materializes) — no behavior divergence between the legacy and multi-variant paths.
  - **Per-variant media playlists needed no controller change.** `media_v{id}.m3u8` (e.g. `media_v1080p.m3u8`), written up front by `TranscodeManager` alongside `master.m3u8`, already served correctly as a plain static file through the existing `serveJobFile()` path — no transcoder call — confirmed by a new test asserting `ensureSegment()` is never invoked for a playlist request.
  - This is the server-side HLS **serving** half of the multi-variant feature; the client-facing API surface that advertises the available `variants[]` list shipped in step **A7** (see above). Player-visible quality selection is step **E3** in `@phlix/ui`.

- **`TranscodeManager` is now a genuine multi-variant HLS pipeline (Stream Quality/ABR step A5) — the actual multi-quality/ABR feature landing for the first time.** Previously every on-demand HLS job served exactly one quality (`master.m3u8` → `media_0.m3u8` → unprefixed `seg-NNNNN.ts`). `ensureHlsJob()` now:
  - Resolves the source's characteristics into a `SourceProfile` — preferring A1's persisted `metadata_json['source']` blob (via the new `sourceProfileForItem()`/`persistedSourceMetadata()`), and only falling back to deriving one from the live `ffprobe` result (`sourceProfileFromProbe()`) for items that predate the A1 backfill — then calls A2's `AbrLadder::build()` to get a `LadderResult` and persists it as JSON in the (A3) `transcode_jobs.variants` column.
  - Publishes a `master.m3u8` listing **every** clamped quality rung plus the "Original" stream-copy passthrough (when the source is HLS-safe H.264/AAC) as separate `#EXT-X-STREAM-INF` entries, highest-first, each with a correct `BANDWIDTH`/`RESOLUTION`/`CODECS` and its own media playlist (`buildMultiVariantMaster()`). Each variant gets its own VOD media playlist — `media_v{id}.m3u8` (e.g. `media_v1080p.m3u8`, `media_voriginal.m3u8`) — with an **identical segment timeline** (count/`EXTINF`/duration) to every other variant, so hls.js can switch rungs at any segment boundary. Segment boundaries were already kept identical across rungs by A4; A5 is what actually exposes multiple rungs to a player.
  - Serves each variant's segments on demand from `ensureSegment(jobId, variant, index)` (now variant-aware): the requested variant id is resolved against the persisted ladder (`findRenditionArray()`), its encode params are derived (`segmentParamsForRendition()` — the copy contract for a genuine passthrough rung, or capped-CRF H.264/AAC with the rung's scale/VBV ceiling/macroblock-derived `-level` otherwise), and the shared tail (`produceSegment()`) writes `seg-v{id}-NNNNN.ts` (still flat in the job directory — the `/hls/{jobId}/{file}` route's `{file}` segment is `[^/]+`, so no `v{id}/` subdirectory is possible). All of the existing on-demand seek-cascade protections — per-segment dedup via `.part-*` temp files, the global in-flight-encode cap → `SegmentBusyException`/503, and the LRU/TTL cache sweep — continue to work unmodified across every variant of every job, because they already glob on the `seg-*`/`{final}.part-*` filename shape rather than assuming a single variant.
  - **Fully backward compatible.** Any transcode job created before this deploy (`transcode_jobs.variants IS NULL`) keeps working exactly as before: `writeVodPlaylists()`/`ensureSegment()` detect the absent `variants` column and fall through to the untouched legacy single-variant path — `master.m3u8` (single `#EXT-X-STREAM-INF`) + unprefixed `media_0.m3u8` + `seg-NNNNN.ts` — byte-identical to pre-A5. Nothing regresses for in-flight or existing jobs.
  - One small call-site change in `HlsController::serveFile()` (passes `null` for the new `$variant` parameter of `ensureSegment()`) kept the legacy unprefixed-segment regex match working at the time; full variant-aware URL parsing (`media_v{V}.m3u8` / `seg-v{V}-NNNNN.ts`) landed in step **A6** (see above). Player-visible quality selection UI is further out still (step **E3** in `@phlix/ui`) — this step was the server-side foundation, not yet user-facing.

- **`FfmpegRunner::buildSegmentCommand()`: per-rung capped-CRF encode + genuine stream-copy passthrough (Stream Quality/ABR step A4) — `FfmpegRunner`-only groundwork at the time; the copy path was DORMANT until step A5 wired a real per-rung/Original decision into `TranscodeManager` (see the A5 entry above — `TranscodeManager::computeSegmentParams()`/`segmentParamsForRendition()` now make that decision for every job created after A5 deployed).** Two segment shapes, selected per-stream from the caller's params:
  - **Capped-CRF transcoded rung (the default, and byte-identical to the exact pre-A4 command when the new keys are omitted).** `maxrate`/`bufsize` (bps ints, sourced from A2's `Rendition::maxrate()`/`Rendition::bufsize()`) are now emitted as `-maxrate`/`-bufsize` alongside the existing `-crf`/`-preset`, giving the quality-driven encode a hard VBV ceiling so a rung's encoded bitrate never exceeds its advertised HLS `BANDWIDTH`. No bare `-b:v` is ever set — it would disable CRF mode; the cap is the `-maxrate`/`-bufsize` pair only, and it is emitted only when the caller supplies both keys. `-force_key_frames`, `-output_ts_offset`, and `-muxdelay`/`-muxpreload` stay byte-identical across every transcoded rung (only scale/bitrate/level differ), so ABR switching between rungs at a segment boundary stays seamless.
  - **Genuine stream-copy passthrough for "Original."** `video_codec === 'copy'` now emits a real `-c:v copy` (previously silently upgraded to a forced `libx264` re-encode) and skips `-force_key_frames`/scale/`-preset`/`-crf`/`-maxrate`/`-bufsize` — a stream copy can't force an arbitrary keyframe, so a copy segment's actual start may drift up to one source GOP length from the nominal boundary; acceptable for a manually-pinned Original variant but exactly why copy is never used for the ABR-switching rungs. `audio_codec === 'copy'` gets the same treatment independently (`-c:a copy`, no `-b:a`/`-ac`), so a mixed video-copy/audio-reencode segment (or the reverse) is fully supported.
  - Fully backward compatible: a caller that never passes `maxrate`/`bufsize`/`video_codec: 'copy'`/`audio_codec: 'copy'` gets the exact pre-A4 CRF-only command.

- **ABR ladder builder + rendition value objects (Stream Quality/ABR step A2) — pure groundwork for the multi-variant HLS master at the time; wired into `TranscodeManager`'s master/media-playlist generation by step A5 (see above).** New `src/Media/Streaming/{AbrLadder,Rendition,SourceProfile,LadderResult}.php`. `AbrLadder::build(SourceProfile $source, string $profileName = 'generic'): LadderResult` is pure and deterministic — no DB, ffprobe, filesystem, clock, or randomness; identical inputs always produce identical output — and returns an ordered, source-clamped H.264 quality ladder (240p…2160p, highest-first) plus an "Original" descriptor, given the source's video/audio characteristics (`SourceProfile`, adaptable from A1's persisted `metadata_json['source']` via `SourceProfile::fromSourceMetadata()`, or constructed directly from a live probe) and a device-profile name looked up in the existing `QualitySelector` (`generic`/`mobile-low`/`mobile-high`/`web`/`tv-4k`). The ladder **consumes** `QualitySelector`'s `max_resolution`/`max_bitrate` device caps as its clamp ceiling — it does not replace or change `QualitySelector` itself, which still governs direct-play-vs-transcode selection. Every rung is clamped so it never upscales past the source resolution, never exceeds the source's own video bitrate when known, and never exceeds the device profile's resolution/bandwidth cap (reserving a 128 kbps AAC allowance + maxrate headroom so the advertised `BANDWIDTH` never exceeds the profile's cap); a rung's width is derived from the source's own aspect ratio (not a fixed 16:9), so anamorphic/DCI/ultrawide sources aren't distorted; a source below the lowest tier (or one squeezed below it by a narrow device-profile width) still yields exactly one clamped rung; unknown source dimensions cap the ladder conservatively at a 1080p 16:9 tier (never 1440p/2160p) and suppress the copy `Original`. Each transcode rung also carries the derived encoder targets step A4 will consume: `-b:v` (`videoBitrate`), `-maxrate` (`Rendition::maxrate()`, ≈1.07× target) and `-bufsize` (`Rendition::bufsize()`, 2×maxrate). `Rendition` mirrors the eventual wire shape `{id,label,width,height,bitrate,codecs,url}` (plan §1 D6) — `url` stays `null` here; step A5 wires the ladder into the transcode pipeline but still leaves `url` `null` (variant playlists are addressed by convention, `media_v{id}.m3u8`); step A7 (see above) is what actually fills `url` in, in a derived array copy of the ladder returned by `TranscodeManager::getJobVariants()` for the API response shape — `Rendition::toArray()` itself still always emits `url: null`.
  - **"Original" (D4): a stream-copy passthrough when the source is HLS-safe (H.264 + AAC) and fits the profile cap — `-c copy`, near-zero CPU, labelled `Original (<source height>p)` — else the top clamped transcode rung, relabelled `Original (best available)`, so the UI's "Original" choice doesn't map onto a duplicate master variant.** `LadderResult::streamVariants()` prepends the copy Original as a genuine extra highest variant when it applies, and omits the non-copy one so `A5` doesn't emit a duplicate `#EXT-X-STREAM-INF`.
  - **H.264 `CODECS` level is chosen by macroblock count (`ceil(w/16) * ceil(h/16)`) against a MaxFS table, not by height alone**, so a rung or copy `Original` whose frame is wider than its height tier's canonical 16:9 shape (2048×1080 DCI-2K, 2560×1080 ultrawide, 2560×1440, 3840×2160, …) advertises a legal, never-under-declared `avc1.*` level — e.g. plain 1920×1080 is L4.1 (`avc1.640029`), but 2048×1080 needs L4.2 (`avc1.64002A`) and 2560×1080 needs L5.0 (`avc1.640032`). **Coordination note for step A4:** the FFmpeg encoder must encode each rung — and the copy path — at this exact per-rung, macroblock-derived level, not a single flat `-level 4.1` applied to every rung, or the encoded stream will silently mismatch the level the master playlist advertises for wide/anamorphic tiers.

### Added

- **Schema: `transcode_jobs.variants` column for the multi-variant ABR ladder (Stream Quality/ABR step A3) — schema-only groundwork, no behavior change yet.** New migration `migrations/049_transcode_jobs_variants.sql` adds a single nullable `variants TEXT` column (`AFTER segment_params`, same additive/idempotent `ALTER TABLE ... ADD COLUMN` style as `047_transcode_jobs_ondemand_columns.sql` — a re-run duplicate-column error is downgraded to a note by `MigrationRunner`). It will carry the resolved ABR ladder as JSON-shaped text (matching `segment_params`'s TEXT convention, since the workerman/mysql driver handles it as a plain string rather than native JSON): `LadderResult::toArray()` from step A2 (`src/Media/Streaming/{AbrLadder,Rendition,LadderResult}.php`) — `{renditions: [Rendition::toArray(), ...highest-first], original: Rendition::toArray()}`, each rendition flat as `{id, label, width, height, bitrate, codecs, url, is_original, is_copy, video_bitrate}`. Nothing read or wrote the column at the time — step A5 (see above) is what builds the ladder in `TranscodeManager::ensureHlsJob()` and persists it here, so the master/media playlists are rebuilt without recomputing the ladder from a live `ffprobe` on every request. Existing rows are unaffected: `variants` is `NULL` for every pre-A5 job, and the pipeline keeps working unmodified via the existing single-variant columns (`profile`, `segment_params`, …) as the fallback path.

### Added

- **Scanner now persists source video/audio technical metadata at scan time (Stream Quality/ABR step A1) — groundwork for the upcoming multi-quality (ABR) streaming ladder.** The single `ffprobe` call the scanner already ran per time-based file (video/movie/episode/audio) now also derives a compact technical summary, stored at `metadata_json['source'] = {width, height, video_codec, video_bitrate, pix_fmt, audio_codec, audio_bitrate}`, so a later ABR ladder builder can pick renditions without re-probing on every playback start. The existing total-duration probe is folded into the same call (no added probing cost). The primary video stream is chosen skipping embedded cover-art / poster streams (`disposition.attached_pic = 1`, common in MKV/MP4/M4V), so a poster's tiny dimensions never masquerade as the source resolution; `video_bitrate` falls back to the whole-file `format.bit_rate` when the video stream itself reports none (common for Matroska). Runs on both the initial-scan and the incremental-rescan path — the rescan path (previously a duration-only backfill) now backfills `source` and the streams below too, for files indexed before this change.
  - **`media_streams` rows (video + primary audio) are now written from the same probe**, via the existing `ItemRepository::addStream()` and a new `ItemRepository::deleteStreamsByItem()`. Replacement is delete-then-insert (idempotent) because the table carries no unique key on `(media_item_id, stream_index)` and would otherwise duplicate rows on every rescan.
  - **New offline CLI `scripts/backfill-source-metadata.php [--library=<id>] [--limit=<n>]`** populates `metadata_json['source']` (+ duration + streams) for items indexed before this change. Idempotent and guarded per item: an item already carrying both a positive duration and a `source` blob is skipped without probing, a probe or write failure on one item is logged and never aborts the run, and the process exits non-zero when any item hard-fails so automation can detect a partial run — failed items are left `source`-less and are picked up again on the next invocation.

### Fixed

- **Transcoded playback (MKV / HEVC / 10-bit) now reports the real length and seeks anywhere.** A file the browser can't direct-play (e.g. a 10-bit HEVC `.mkv`) is transcoded to HLS on demand. The pipeline previously ran a **single linear CMAF encode** (`ffmpeg -f dash … -hls_playlist 1`) that wrote HLS child playlists **incrementally with no `EXT-X-ENDLIST` / `PLAYLIST-TYPE:VOD`** until the whole (minutes-long, software) encode finished. hls.js therefore treated the stream as **live**: `video.duration` only grew as segments arrived, and `seekable` covered only the encoded-so-far region, so seeking past it snapped back. (The dead `playlist_type => 'vod'` param was only read by the unused `-f hls` path, never the `-f dash` path that actually ran.)
  - **`TranscodeManager::ensureHlsJob()` is now on-demand + seek-aware.** On job creation it probes the duration and publishes a **complete VOD playlist immediately** — `master.m3u8` + `media_0.m3u8` listing every segment with its `EXTINF`, `#EXT-X-PLAYLIST-TYPE:VOD`, and a closing `#EXT-X-ENDLIST` — so the player knows the true total length and full seekable range up front. No background A/V encode is launched (subtitle extraction still runs detached). The job is recorded `status='completed'` so the stale-job reaper (which only reaps `running` rows) can't tear it down mid-watch.
  - **Segments transcode on demand.** `HlsController` routes a `seg-NNNNN.ts` request through the new `TranscodeManager::ensureSegment()`, which returns a cached segment or launches `FfmpegRunner::startSegmentEncode()` — an `-ss` fast-seek encode of exactly that segment's window, with a forced keyframe at its start and `-output_ts_offset` anchoring its PTS to the timeline (so segments stitch and a seek lands correctly). The request polls for the atomically-renamed segment with a **coroutine-yielding `usleep`** (`SWOOLE_HOOK_SLEEP` is in the curated hook set) so a waiting request never blocks the worker. Any segment — including one far past what has been produced — is served, so the user can seek anywhere (a ~1–3 s spin-up per uncached seek on CPU). A per-job soft cap bounds concurrent encodes so frantic scrubbing can't fork-bomb ffmpeg.
  - **Migration `047_transcode_jobs_ondemand_columns.sql`** — adds `duration_seconds`, `segment_seconds`, and `segment_params` (JSON) to `transcode_jobs` so any segment can be built later without re-probing. Reuse now requires `segment_params IS NOT NULL`, so a legacy linear-CMAF job left in the table across the upgrade is **not** reused (its live playlist is skipped; a fresh on-demand job is created). DASH manifest generation is dropped from this path — no client consumed it (web, mobile, and Roku all play the HLS `master_url`). No client change is needed: the SPA already detects `.mkv`/HEVC and starts the transcode; it now attaches to a proper seekable VOD stream.
- **Metrics: admin `/api/v1/admin/metrics/*` no longer 500s with a "Couldn't execute method `Error::__toString`" fatal.** The S2 metrics wiring registered the concrete `MetricsRepository` but never bound the read-side `MetricsRepositoryInterface`, while `MetricsController` type-hints the interface and `AdminRoutes` resolves `get(MetricsController::class)` at route registration. PHP-DI then tried to instantiate the interface directly and threw `InvalidDefinition` ("the class is not instantiable"), which the Workerman error handler surfaced as the mangled `Error::__toString` fatal. `MetricsServicesProvider` now aliases `MetricsRepositoryInterface::class => get(MetricsRepository::class)` (reusing the shared concrete singleton); a new regression test asserts the interface resolves.

### Security

- **systemd unit: extra kernel/privilege hardening (phlix-hub parity).** Adds `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectControlGroups`, `ProtectHostname`, `ProtectClock`, `RestrictSUIDSGID`, and `RestrictRealtime` to the generated `[Service]` block, on top of the existing `ProtectSystem=strict`/`ProtectHome`/`NoNewPrivileges`/`PrivateTmp`/`RestrictNamespaces`/`LockPersonality`/`RemoveIPC` set. All are safe for the media server (software transcoding shells out to ffmpeg; optional DVB/DLNA needs neither module loading nor clock/hostname/cgroup writes). Deliberately **not** setting `PrivateDevices` (would hide `/dev/dvb` tuners and `/dev/dri`), `MemoryDenyWriteExecute` (breaks PHP JIT/opcache), or `SystemCallFilter` (Swoole io_uring is syscall-sensitive). Verified with `systemd-analyze verify` and a `systemd-run` sandbox on the host.

### Added

- **Multi-level "Love" for media items (Feature 10).** Builds on the per-user favorites/ratings (E10, below) with a separate 0-3 "Love" axis distinct from `favorite` (boolean) and `rating` (1-10).
  - **Migration `044_user_item_like_level.sql`** — adds `like_level TINYINT NOT NULL DEFAULT 0` to `user_item_data` (`AFTER rating`). `0` = not loved … `3` = most loved. The 0-3 range is enforced in PHP (`UserItemDataRepository::setLikeLevel()` throws `InvalidArgumentException` out of range) — **not** via a DB `CHECK` constraint (consistent with mig 039's PHP-enforced 1-10 rating). Idempotent re-run via the runner's "Duplicate column name" downgrade. **This migration is the W1 deploy trigger (server migration max → 044).**
  - **New endpoint `PUT /api/v1/media/{id}/like`** (body `{level: int 0-3}`, required) → `{message: "Love level saved"}`; `400` on missing/non-integer/out-of-range `level`, `401` unauthenticated, `404` unknown item. Handled by `MediaUserDataController::setLikeLevel()`, validated/coerced exactly like `setRating` (the range is deferred to the repository). Registered once in the `requireAuth` group on `WebPortalRouter` (with a 503-when-unwired delegate) — reachable from **both** HTTP entry points (CGI/web-portal + the Workerman daemon's `HttpHandler`, which forwards `/api/*` 404s to `WebPortalRouter`), exactly as the existing favorite/rating routes do.
  - **`UserItemDataRepository::setLikeLevel(userId, itemId, level)`** — upserts via `INSERT ... ON DUPLICATE KEY UPDATE` (flat positional `?` binding, colon-free), preserving `favorite`/`rating`; `getItemData()` and `getFavorites()` now also select `like_level` (int, default 0 when absent/NULL).
  - **`user_data` block extended (add-only).** `GET /api/v1/media/{id}` and `GET /api/v1/users/me/favorites` now carry `like_level: int` (default 0) alongside `favorite`/`rating`. No existing key disturbed; only detail + favorites-list responses build a `user_data` block (browse/list rows do not).

- **Strip multi-word "noise" suffixes from match titles (Feature 13).** Filename→title cleaning now peels trailing edition/scene markers — `Directors Cut`, `UNCUT & UNRATED`, `ALTERNATE ENDING`, `Extended Cut`, `Remastered`, `YIFY`, `DC`, … — before the title is sent to a metadata provider, so they no longer depress the match hit-rate. New shared `Phlix\Media\Metadata\TitleSuffixStripper` is the single source of truth (longest-phrase-first, end-anchored, word-boundary; a single-token noise word never empties a title; the original filename `raw` is never mutated); both the movie normalizer (`SceneFilenameNormalizer`) and the series parser (`EpisodeFilenameParser::cleanSeries()`) consume it. The effective list is admin-extensible via the new `matching.noise_suffixes` server-setting (replace-not-merge override; an empty override falls back to the code defaults mirrored in `config/matching.php`), wired through `MediaServicesProvider`.

- **Auto-merge / de-duplicate top-level series & movies (Feature 1).** A title-slug variance (separators, year bleed, a flat→per-directory re-scan, a concurrent-scan race) used to silently create a second top-level row for the same title — the "100 episodes + 1 stray episode" symptom — because there is no DB UNIQUE constraint on items. Added:
  - **`Phlix\Media\Library\CanonicalKey`** — a pure normalizer that collapses separator/article/case variance and prefers a matched external id (`imdb:` > `tmdb:` > `title-key:year` > `title-key`).
  - **Prevention at scan time** — `MediaScanner` now resolves a container in the tier order `containerCache → exact path (findByPath) → canonical key (findTopLevelByCanonical)`, reusing an existing container on a canonical hit rather than manufacturing a sibling. Applied to the top-level movie create path too.
  - **`DuplicateFinder`** — pages a library's top-level items in fixed batches, buckets them by canonical key + type, returns groups of size ≥ 2 with the most-descendants member as the primary.
  - **`SeriesMerger`** — `merge(primaryId, duplicateIds): {moved, deleted}`; for a series, re-parents episodes onto the primary's matching season (re-parent-before-delete) then deletes empty shells; for a movie, gap-fills missing metadata (add-only) then deletes the duplicate. Runs inside one real DB transaction; works under both `DB_POOL_ENABLED=0` and `=1` (the ctor takes the base `Workerman\MySQL\Connection`). Per-user playback markers are out of scope: re-parented episodes keep their ids (state survives); deleted shells/duplicate-movie rows lose their own per-user rows via `ON DELETE CASCADE`.
  - **Migration `043_media_items_canonical_key.sql`** — adds a nullable, **non-unique** `canonical_key VARCHAR(191)` column + `(library_id, type, canonical_key)` index (no UNIQUE — historical dupes exist; uniqueness is enforced in app code). `ItemRepository::create()/update()` write the column as the source of truth.
  - **`scripts/dedup-series.php [--library=ID] [--dry-run|--apply]`** — offline backfill that runs `DuplicateFinder` per library and, on `--apply`, calls `SeriesMerger`; dry-run is the default and a re-run after `--apply` reports zero groups (idempotent).
  - **Admin merge API** — `GET /api/v1/admin/libraries/{id}/duplicates` (preview groups) and `POST /api/v1/admin/media/merge` `{primary_id, duplicate_ids[]}` → `{moved, deleted}`, both admin-gated (`Phlix\Server\Http\Controllers\Admin\AdminMergeController`, registered via `AdminRoutes::register()` for both entry points). Validates same-library + same-type, rejects self-merge (`400`), `404` on missing primary, `503` when no transaction-capable connection is bound.

- **Metadata source priority — configurable per-field fallback (Feature 3).** The matching pipeline now normalizes each provider's payload into a canonical field set and resolves each field by walking a configurable source order, taking the first non-empty value (external IDs merged, earlier source wins). Added `Phlix\Media\Metadata\Resolution\{SourceRecord, FieldMappers, PriorityFieldResolver, PriorityConfig}`. The order is driven by the new `metadata.provider_priority` server-setting (per media type; defaults `movie`/`series` = `["tmdb","imdb"]`, `anime` = `["anidb","myanimelist","tvdb","fanart","local"]`) and `metadata.genres_mode` (`first` | `union`, default `first`); defaults live in `config/metadata.php`. `MovieMetadataResolver`/`SeriesMetadataResolver` were refactored onto this resolver behavior-preservingly under the default order (the live series resolver keeps a fixed `['tmdb']` order — making the configured series order take effect is a deliberate future change).

- **Admin metadata-source name endpoint (`GET /api/v1/admin/metadata/sources`, Step 3.6).** New admin-gated, read-only JSON endpoint returning `{sources: string[]}` — the available metadata-source names so the admin SPA's per-media-type priority editor (`metadata.provider_priority`) can list REAL names. The list is the built-in sources (`tmdb`, `imdb`, `tvdb`, `fanart`, `local`, in that stable order) followed by any plugin sources currently registered in `SourceRegistry` (e.g. `anidb` / `myanimelist` when those plugins are enabled), de-duplicated. Handled by new `Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController` (its only dependency is the container-scoped `SourceRegistry`); registered via `AdminRoutes::register()` so BOTH HTTP entry points (the Workerman daemon `Application` + the `public/index.php` web portal) expose it from one registration, gated by `AdminMiddleware` (401 unauth / 403 non-admin). No DB access, no write.

- **First-class metadata-source plugin contract (`SourceRegistry`, Step 3.5b).** Plugins that provide metadata (e.g. `phlix-plugin-anidb`, `phlix-plugin-myanimelist`) can now register through the shared typed contract `Phlix\Shared\Metadata\MetadataSourceInterface` (shipped in `detain/phlix-shared` v0.15.0) instead of the old brittle `method_exists($manager,'registerProvider')` / FQCN container-sniffing dance. New `Phlix\Media\Metadata\Resolution\SourceRegistry` is a process-scoped registry of `MetadataSourceInterface` instances keyed by `sourceName()`; it is wired as a single container-scoped binding in `MediaServicesProvider`. `PluginLoader::enable()` now `register()`s any enabled plugin entry instance that implements `MetadataSourceInterface`, and `PluginLoader::disable()` `deregister()`s it — a leak-free enable/disable cycle (re-register is idempotent, deregister truly removes). Bumped the `detain/phlix-shared` constraint `^0.14.0` → `^0.15.0`. This step builds the registry + plugin contract only; it does **not** change `MovieMetadataResolver`/`SeriesMetadataResolver` output (the live series resolver keeps its fixed `['tmdb']` order from Step 3.4) — feeding the registry into the resolvers is a later step.

- **`web-ui`: bumped `@phlix/ui` `v0.56.0` → `v0.57.0` and rebuilt the committed SPA bundle (`public/assets/app/`) (Wave 1 bump).** Picks up the favorites wiring (`MediaCard` favorite button, Browse "Favorites" row, Browse/Detail persistence + hydrate), the multi-level **Love** control (`LoveButton.vue` 4-state component on cards + detail), and the player favorite/Love controls (`Player.vue`, `MiniPlayer` favorite toggle, `PlayerPage` hydrate). `package.json`/`package-lock.json` pin the new `v0.57.0` git tag; the Vite bundle was regenerated (now includes `LoveButton-*`). No server PHP changed for the bump.

- **`web-ui`: bumped `@phlix/ui` `v0.55.0` → `v0.56.0` and rebuilt the committed SPA bundle (`public/assets/app/`) (Wave 0 bump).** Picks up the shared admin **Duplicates** page (the UI for the merge API above) and the **Metadata** settings tab's per-media-type source-priority editor. `package.json`/`package-lock.json` pin the new git tag; the Vite bundle was regenerated. No server PHP changed for the bump.

- **Per-user favorites + ratings for media items (E10).** Each user can now mark any media item as a favorite and give it a personal 1-10 rating, persisted server-side. New table `user_item_data(user_id, item_id PK, favorite BOOL, rating INT NULL, updated_at)` (`migrations/039_user_item_data.sql`, FK CASCADE → `users` + `media_items`) and `Phlix\Media\UserItemDataRepository` (flat positional-`?` binding like `UserRepository`/`WatchHistory`; upserts with `INSERT ... ON DUPLICATE KEY UPDATE` per-column so favorite/rating preserve each other; the 1-10 range is enforced in PHP — `setRating()` throws — not via a DB CHECK). Four auth-gated routes on `WebPortalRouter` (handled by `MediaUserDataController`): `POST /api/v1/media/{id}/favorite`, `DELETE /api/v1/media/{id}/favorite`, `PUT /api/v1/media/{id}/rating` (body `{rating: int 1-10|null}`; 400 on non-numeric/out-of-range, 404 if the item is missing), and `DELETE /api/v1/media/{id}/rating` — all return `{message}`. `GET /api/v1/media/{id}` now also carries an **add-only** `user_data: {favorite: bool, rating: int|null}` block (`null` when unauthenticated; `{favorite:false, rating:null}` when authed with no row), leaving every existing detail key untouched. **Favorites/ratings are account-level (keyed on `user_id`, like `user_settings`) — NOT per-profile** like `watch_history`. Registered once on `WebPortalRouter` (not `Application::loadApiRoutes()`) so all three dispatch paths — `public/index.php`, the Workerman daemon's `HttpHandler`, and the relay dispatcher — share it. Existing items have no row until first interacted with, so they simply report `favorite:false, rating:null`; no backfill needed.

### Fixed

- **Docs: `AdminMergeController` docblock corrected.** The merge controller's docblock described the `503` ("merge unavailable") trigger as "the active DB connection is not the transaction-aware `PhlixMySQLConnection`", which was stale after the `SeriesMerger` ctor was widened to the base `Workerman\MySQL\Connection`. It now reads "no transaction-capable base `Connection` bound", reflecting that the merger is wired from whichever connection ships — `PhlixMySQLConnection` (`DB_POOL_ENABLED=0`) or `PooledMySQLConnection` (`DB_POOL_ENABLED=1`). Comment-only change.
- **Hub config is now actually loaded (fixes empty hostname candidates → blank server URL / disabled "Manage").** `config/hub.php` was never wired into the app config, so `HubServicesProvider` fell back to bare defaults — notably `public_url = ''` — and the server advertised no hostname candidates at pairing (`hostname_candidates_json: []`), leaving the hub with no URL for the server and the "Manage" button disabled. `config/server.php` now `require`s `config/hub.php` as its `hub` key (like `ffmpeg`), so the configured `public_url` (`https://<PHLIX_DOMAIN>`) reaches `HubClient`. The hub updates `hostname_candidates_json` from every heartbeat, so existing pairings self-heal on the next heartbeat (no re-pair needed).
- **Manual "Send heartbeat" (admin) no longer fails with cURL "No host part in the URL".** `HubClient::sendHeartbeat()` posts to a relative path via `$this->httpClient`; the heartbeat *loop* swaps that for an enrollment-scoped client, but a direct admin call runs in the HTTP worker where the loop never ran, so it used the empty-base placeholder. `sendHeartbeat()` now (re)builds an enrollment-scoped client from the freshly-loaded enrollment (also picking up a renewed token), leaving test-injected mocks untouched.

- **Hub pairing now completes reliably (server stores enrollment + heartbeat starts without a restart).** Two bugs left a paired server stuck at "not paired / last seen never": (1) the admin SPA's poll→complete flow sent an empty `hubJwksUrl` to `POST /hub/complete`, which required it and returned 400 — so the enrollment was never stored, and because the hub deletes the one-time claim the instant it returns the JWT, re-polling couldn't recover; (2) even once stored, `HubApplication::start()` is one-shot, so the heartbeat loop wouldn't run until a process restart. Now: `hubPoll()` stores the enrollment **server-side** the moment the claim is consumed (atomic, client-independent) and returns `hubJwksUrl`; `hubComplete()` is **idempotent** (returns success when already enrolled instead of 400 on a missing field); and the `phlix-hub-heartbeat` worker **polls for a late-appearing enrollment** (`HubApplication::isEnrolled()`) and starts the heartbeat within ~15s of pairing — no restart needed.

- **Hub pairing now advertises the server's configured public URL.** Under the Workerman daemon `$_SERVER` is empty, so `HubClient::getHostnameCandidates()` reported `[]` and the hub recorded no reachable hostname for the server (blank URL in "My Servers"). The server now advertises its public base URL — derived from `PHLIX_DOMAIN` (set by `scripts/install.sh --domain`) with the scheme from `tls_enabled` — as the preferred hostname candidate during pairing (`config/hub.php` `public_url` → `HubClient`). `install.sh` also now persists `PHLIX_TLS_ENABLED` (from `--tls`/`--no-tls`) so the scheme is correct, and fixes a latent bug where `PHLIX_TLS_ENABLED=0` was ignored (`?: true` treated `'0'` as unset).

- **`PhlixMySQLConnection`: force emulated + buffered prepared statements.** Under the Swoole event loop mysqlnd's socket is coroutine-hooked, so a query yields the coroutine while waiting on the socket. With the parent's **native, unbuffered** prepares each statement keeps per-statement server-side state on that socket which leaks across coroutine yields — wedging the shared connection so the next `prepare()` silently returns `false` (`Call to a member function bindParam() on false`) or params desync (`HY093`) under concurrent requests, even with the per-coroutine query mutex. `connect()` now sets `PDO::ATTR_EMULATE_PREPARES = true` (prepare is client-side only) and `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = true` (results consumed immediately). Because emulated prepares would otherwise bind params as strings (making `LIMIT ?`/`OFFSET ?` → `LIMIT '50'` → MySQL 1064, and this codebase has many such queries), `execute()` is also overridden to bind each value with its natural PDO type (`int → PARAM_INT`, …), mirroring the parent's prepare/execute + 2006/2013 reconnect. Diagnosed + verified first on phlix-hub (150 concurrent requests → zero corruption; bound/positional/mixed `LIMIT` queries succeed); applied here for parity since this connection class runs the same native-prepare path under the same coroutine runtime. Parameterisation stays injection-safe; charset is utf8mb4.

### Changed

- **`web-ui`: bumped `@phlix/ui` `v0.43.0` → `v0.44.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Carries the **required companion** to the signed-URL gate (see Security below): the web player now points its direct-play `<video src>` at the server-minted **signed** `stream_url` from `GET /api/v1/media/:id` instead of building a bare `/media/:id/stream` path. Without this bump direct play would `401` once the gate deploys (the SPA holds a `localStorage` token, not a session cookie, and a media element can't attach a `Bearer` header). The hls.js/transcode path already attaches the Bearer token to every segment XHR via `xhrSetup` and is unaffected. **Must deploy together with the gate.** No server application code changed for the bump — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.42.0` → `v0.43.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Fixes the library grid where the same titles could "stay" on screen while scrolling: the virtual grid measured the scroll offset on a `requestAnimationFrame`-deferred path that stalls under scroll load (rAF is throttled during scrolling, notably on Firefox), freezing the rendered window. The grid now measures synchronously on scroll. No server application code changed — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.41.0` → `v0.42.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Fixes the A-Z jump rail showing **empty skeleton boxes**: the pre-sized library grid only paged sequentially (append-at-end), so jumping to a letter scrolled to slots whose page was never fetched. The grid now does random-access paging — it loads the page(s) covering the visible window (including after a jump) and places items at their absolute index. No server application code changed — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.40.0` → `v0.41.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Carries the SPA fix that makes every API client send the logged-in user's Bearer token by default (previously the default token store was a no-op). This is the **required companion** to the media-auth gate above: the SPA now authenticates media/letter-index requests, so requiring auth on those endpoints doesn't break browsing. Must deploy together with the gate (both land on master before `install.sh --update`). No server application code changed for the bump — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **Media listings now sort & bucket by an article-stripped title — "The Plot" files under P, not T (the title still displays as "The Plot").** Alphabetical browse/library listings and the A-Z jump rail previously ordered and grouped by the raw `name`, so every "The …"/"A …"/"An …" title piled up under T/A. A new `Phlix\Media\Library\SortTitle` derives a sort key by ignoring a single leading article — English `the`/`a`/`an` plus the common Romance/German articles `el`/`la`/`le`/`les`/`los`/`las`/`die`/`der`/`das` (only when the article is a whole word, so "Theory", "Antman" and "Death Race" keep their natural letter). `ItemRepository` applies it to every alphabetical `ORDER BY` (the `/api/v1/media` name sort + its tiebreaks, plus `getByLibrary`/`getByType`/`findByParent`/the rating- & genre-filtered listings) and to the `letter-index` bucket `GROUP BY`, so the rail's cumulative offsets still line up with the grid. The expression is a single source of truth that also powers a new **`sort_title`** field on the media-item API shape (so any client-side sort can agree), and it is **portable + zero-migration**: it uses only `CASE`/`LOWER`/`LEFT`/`SUBSTRING`/`TRIM` with a `COLLATE utf8mb4_bin` prefix test (case-insensitive but accent-sensitive, mirroring PHP `strncasecmp`) — never `REGEXP_REPLACE`, whose case-insensitive form differs between MySQL 8 and MariaDB 10.6 — so it is correct for every existing and future row the instant it deploys, with no schema change or backfill. `date_added`/`year`/`rating`/`runtime` sorts keep their primary key and only gain the article-insensitive alphabetical tiebreak. (Known pre-existing limitation, unchanged here: a title whose first significant letter is accented or non-Latin — e.g. "Élan" — still folds into the rail's `#` bucket while sorting in its locale position, so the rail jump can be slightly off for such titles in multilingual libraries.)

- **`web-ui`: bumped `@phlix/ui` `v0.39.0` → `v0.40.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Carries the client side of the article-aware sorting above: the `MediaItem.sort_title` field, a `stripLeadingArticle`/`compareByStrippedTitle` helper, and library Browse rails that sort by the article-stripped name (so a "The …"-named library files under its real letter, matching the media listings). The media grid + A-Z rail already reflect the article rule because they render the server's order; this bump keeps the client-sorted surfaces consistent. No server application code changed for the bump — `package.json`/`package-lock.json` pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.32.0` → `v0.36.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Brings the shipped UI work to the served app: **full-width layout** + **clicking a poster opens the info/detail page** (v0.33.0); the **matched/unmatched metadata filter** + **clickable cast** (each cast name opens that title's library filtered to the actor) (v0.34.0); the listing grid **pre-sized to the full result count** with on-demand paging (v0.35.0); and the **A-Z jump rail** on long library listings (v0.36.0, driven by the new `letter-index` endpoint). No server application code changed for the bump — `package.json`/`package-lock.json` pin the new git tag and the Vite bundle was regenerated.

### Security

- **Binary / streaming / feed endpoints now require proof of an authenticated session via short-lived signed URLs (closes the residual `TODO(security)` gap from the media-auth gate below).** The byte-serving routes a `<video>`/`<img>`/`<audio>`/e-reader/native player can't attach a Bearer header to — `/media/{id}/stream`, `/hls/**`, `/dash/**`, `/api/v1/books/{id}/{read,cover,download}`, `/opds/v1.2/**`, `/api/v1/audiobooks/{id}/{read,stream}`, `/api/v1/photo/photos/{id}/{thumbnail,full}` — were reachable by anyone who knew the (UUID) id. They are now gated:
  - **`Phlix\Auth\SignedUrl`** mints/verifies a `?exp=<unix>&sig=<base64url-HMAC-SHA256>` token. The HMAC covers a *canonical resource* (the query-less path) + expiry; for HLS/DASH the resource is the per-job directory prefix (`/hls/{job}`, `/dash/{job}`), so ONE signature on the master-playlist URL authorises every variant playlist, segment and sidecar under it. The signing key comes from `PHLIX_SIGNED_URL_SECRET`, or is derived (domain-separated) from `JWT_SECRET` when unset — so a leaked stream token can't be replayed as a JWT, and vice-versa. Default TTL is 6 h, overridable via `PHLIX_SIGNED_URL_TTL`.
  - **`Phlix\Server\Http\Middleware\SignedUrlMiddleware`** gates those route groups (and an inline equivalent guards `/media/{id}/stream`, which bypasses the router for Workerman `withFile` streaming). It accepts **any** of: an already-authenticated session (`$request->userId` from Bearer **or** the `phlix_session` cookie — so the in-browser player keeps working untouched: hls.js attaches the Bearer token to every segment XHR via `xhrSetup`, and same-origin `<img>`/`<video>` send the cookie automatically), a valid signed-URL token (cookieless/headerless native players, casting, cross-origin), or — for OPDS only — HTTP Basic. Anonymous requests get `401 {code: auth.required}`.
  - **OPDS feeds now support HTTP Basic auth**, because e-reader clients send `Authorization: Basic` (not Bearer) and re-send it on every request. A new session-free `AuthManager::verifyCredentials()` validates the credentials of an *active* account without minting a session; failures return `401` with `WWW-Authenticate: Basic realm="Phlix OPDS"` so the reader prompts. The acquisition `download` link the feed already emitted (`/opds/v1.2/books/{id}/download`) is now actually registered (it was a dead 404), so the whole OPDS flow is authenticated **and** functional.
  - **Minting:** the now-gated JSON detail / transcode endpoints emit the signed URLs in the fields clients already read — `GET /api/v1/media/{id}` (`stream_url`), `getBook`/`readBook` (`cover_url`/`read_url`/`download_url`), `getAudiobook`/`readAudiobook` (`stream_url`/`read_url`), `getPhoto`/`listPhotos`/`getAlbum`/`listAlbums`/`slideshow` (`thumbnail_url`/`full_url`), and `POST /api/v1/media/{id}/transcode` + `…/status` (signed `master_url`/`hls_url`/`dash_url` and signed subtitle track URLs). The photo `slideshow` URLs were also corrected to the real `/api/v1/photo/photos/…` route (they previously pointed at an unregistered `/photo/photos/…` path).
  - The existing JSON listing/search/detail gate (`AuthMiddleware`, below) is **unchanged**. Companion client work lands separately: `@phlix/ui` (the web player consumes the signed `stream_url` and keeps hls.js `xhrSetup`) and the native Roku/Tizen/Windows/mobile clients (consume the server-provided signed URL instead of a bare path).

- **Media & library listing/search endpoints now require a signed-in user (were world-readable).** `GET /api/v1/media` (the SPA's main browse/search), `/api/v1/media/letter-index`, `/api/v1/media/{id}`, `/api/v1/media/{id}/playback`, `/api/v1/libraries{,/{id},/{id}/items}`, the `/api/v1/users/me/*` activity & settings routes, the per-item markers/extras metadata, and the music / books / audiobooks / photos **JSON listing + detail** endpoints had **no auth gate**, so anyone could enumerate a user's entire private library without logging in. A new dependency-free `Phlix\Server\Http\Middleware\AuthMiddleware` (the authenticated-but-not-necessarily-admin counterpart to `AdminMiddleware`) now gates these route groups in BOTH dispatch paths (`WebPortalRouter` for `public/index.php` + the Workerman `HttpHandler`); `$request->userId` is already populated from the Bearer token **or** the `phlix_session` cookie before dispatch, so logged-in SPA and browser-session requests pass while anonymous ones get `401 {code: auth.required}`. **Requires `@phlix/ui` ≥ v0.41.0** (the SPA now sends the token on media requests; older bundles would break browsing). Binary/streaming routes a `<video>`/`<img>`/e-reader can't attach a Bearer header to — `/media/{id}/stream`, `/hls/**`, `/dash/**`, book `cover`/`read`/`download` + OPDS feeds, audiobook `read`/`stream`, photo `thumbnail`/`full` — are **deliberately left open for now** (an item id is an unguessable UUID, only reachable via the now-gated listings) and flagged `TODO(security)` for a follow-up signed-URL / OPDS-Basic-auth pass. Native clients (Roku/Tizen/Windows/mobile) must send their access token on media requests.

- **`web-ui`: bumped `@phlix/ui` to `v0.20.0` — the SPA now validates the session on boot and gates the admin section client-side.** The shared router guard previously treated a token's mere *presence* in `localStorage` as "logged in" (never validating it) and applied no admin-role check, so after a reload a stale/expired token would render every protected route — including the whole `/app/admin/*` console — and the account badge fell back to a generic "A" because the user was never rehydrated. v0.20.0 validates a restored token once via `/auth/me` before the first protected route resolves (clearing it + redirecting to login when invalid) and redirects a logged-in non-admin away from admin routes. The server API already authorized every request (`AdminMiddleware`), so this was client-side broken access control, not data exposure. The committed bundle under `public/assets/app/` was rebuilt; no application code changed.
- **Require admin authentication on theme-media mutation endpoints.** `POST /api/v1/libraries/{id}/theme-media/scan` (`scanThemeMedia`) and `DELETE /api/v1/libraries/{id}/theme-media` (`deleteThemeMedia`) were registered as bare routes with no auth gate, so unauthenticated callers could trigger filesystem scans and delete cached theme media for any library ID. `ThemeMediaController` now carries an optional `AdminMiddleware` (wired in `Application::getThemeMediaController()`, mirroring `LibraryController`) and both mutation methods return `401`/`403` for unauthenticated/non-admin callers before any side effect. The read endpoint (`getThemeMedia`) is unchanged. (Flagged by an external review of an earlier PR; verified still present and fixed here.)

### Fixed

- **"Initiate pairing" (admin → Remote Access) no longer 500s with "Failed to write Ed25519 private key: config/hub-server-key.pem".** The hub-pairing flow persists its runtime state — the server's Ed25519 private key plus `hub-enrollment.json` / `hub-subdomain.json` — into `config/`, but the systemd unit's `ProtectSystem=strict` mounts the install tree read-only **except** the paths in `ReadWritePaths` (which listed `var/`, `.logs`, `templates_c`, … but **not** `config/`), so the key write failed. `scripts/install.sh` now idempotently appends `${INSTALL_PATH}/config` to the unit's `ReadWritePaths` (mirroring the existing `var/` migration) and reloads systemd, so the daemon can persist the pairing key/state. Same root cause + fix shape as the earlier plugin-install `var/` read-only fix. (Applied live to the running unit; this change keeps it across reinstalls/regenerations.)
- **"Initiate pairing" now reaches the hub (fixes "cURL error: URL rejected: No host part in the URL").** After the key-write fix above, pairing got further but then failed: `HubClient::initiatePairing()` / `pollClaimStatus()` passed the operator-entered hub URL only to the *logger*, then called the injected HTTP client with a bare **path** (`/api/v1/server-claims/new`). That injected client is an **empty-base placeholder** (`PHLIX_HUB_URL` is normally unset; the post-enrollment heartbeat/renew loop rebuilds the client from the saved enrollment, but pairing runs *before* enrollment), so cURL received a hostless URL. The two pre-enrollment calls now send the **absolute** `rtrim($hubUrl,'/') . '/api/v1/…'`, and `HttpClient` uses an already-absolute path verbatim (a bare path still resolves against the configured base, and an empty resulting URL now throws instead of reaching cURL). Pairing reaches the hub. Unit tests assert the absolute URL is sent.
- **The "Install plugin" admin form now actually installs a plugin (fixes the silent no-op).** Even after the repo-URL fix, pasting a plugin's GitHub URL still failed: the source downloaded + extracted fine, but the plugin's `plugin.json` was rejected by the manifest schema with *"manifest is invalid: 9 errors"*. `detain/phlix-shared`'s settings schema used `additionalProperties: false` allowing only `type`/`required`/`secret`/`default`, so every real plugin's per-setting **`label`/`description`** (and `type: "boolean"`) failed validation — the install returned a 422 that the SPA surfaced as a generic failure, perceived as "nothing happening". Bumped `detain/phlix-shared` to **v0.9.1**, whose manifest schema permits per-setting `label`/`description` and accepts `integer`/`boolean` as aliases of `int`/`bool`. Verified the anidb + myanimelist manifests now validate against the vendored schema. (`install.sh --update` / `composer install` pulls the new schema; no application code changed.)
- **Cast / actors now populate on movie & TV detail pages, and the actor filter actually matches (fixes empty cast lists).** TMDB cast was fetched but lost or mis-shaped before it reached the UI: the bulk "Match metadata" path (`MovieMetadataResolver::merge()`) dropped `actors` entirely, the interactive "apply match" path stored them as TMDB *objects* (`{name, role, order}`), and TV details never requested credits at all — while the API shaper, the SPA cast chips and the `actors[]` filter all expect a flat list of name strings (so cast lists rendered empty and "filter by actor" matched nothing). A shared `MetadataValue::actorNames()` reduces either shape to a de-duplicated list of names; both movie paths persist names, the TV path now appends `aggregate_credits` (`TmdbProvider::getTvDetails`) and carries the series cast (`formatSeriesDetails`), `MediaItemShaper` normalises on read (so already-stored object data renders without a re-match), and the `ItemRepository` actor filter matches **both** `$.actors[*]` (names) and `$.actors[*].name` (legacy objects). The director is also now carried through the bulk movie path. Re-run "Match metadata" to backfill cast on existing items.
- **HTTP worker no longer segfaults under the Swoole coroutine runtime (`worker[phlix-server-http] exit with status 139`).** `start.php` enabled `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`, which hooks *every* native call — file IO (io_uring), `proc_open`/`shell_exec`/`exec` (the on-demand HLS/CMAF transcode spawns a detached `ffmpeg`) and native curl — and re-drives each on the coroutine scheduler. On the production stack (**PHP 8.5 + Swoole 6.2.1 + kernel 7 with `io_uring` enabled**) those hooks crashed the worker with recurring general-protection faults **inside `swoole.so`** (175 such exits observed in `workerman.log`; `dmesg` traps point at `swoole.so`, and the crashes correlate with transcode spawns in the request path). New `Phlix\Server\Runtime\SwooleRuntime` applies a **curated allowlist** of just the network + sleep hooks (`TCP/UDP/UNIX/UDG/SSL/TLS/STREAM_FUNCTION/SLEEP/SOCKETS`); every other hook — file IO/io_uring, `proc_open`, curl, stdio, and the unnamed blocking-function hook that re-drives `exec`/`shell_exec` (the ffmpeg spawn, the exact trigger) — is excluded **by construction** and runs as an ordinary blocking syscall, while the coroutine MySQL pool + network IO still yield. (An allowlist is used rather than `SWOOLE_HOOK_ALL` minus a blocklist because `SWOOLE_HOOK_BLOCKING_FUNCTION` is not a named constant in Swoole 6.2.1 yet its bit is set in `SWOOLE_HOOK_ALL`, so it can't be subtracted.) Configurable in `config/server.php`: `coroutine.enabled => false` hard-disables the hook (Swoole stays the event loop), and `coroutine.hook_flags` takes an explicit `SWOOLE_HOOK_*` bitmask override. NOTE — the durable fix is to align the runtime: PHP 8.5 + Swoole 6.2.1 + io_uring is bleeding-edge; also consider `swoole.enable_iouring=0` and a Swoole build verified against the installed PHP.
- **The admin "Install plugin" form now accepts a GitHub repository URL (fixes "install failed" on a pasted repo URL).** `HttpInstaller` could only fetch a *direct archive* (`.zip`/`.tar.gz`/`.tgz`) or a `.json` stub, so pasting a repository URL like `https://github.com/detain/phlix-plugin-anidb` — which the admin UI and `plugin:install` CLI both invite — had no recognised extension: the installer downloaded the repo's HTML landing page and threw *"Unsupported plugin source extension"*, surfaced as a generic 422 "install failed". A new `Phlix\Plugins\Installer\SourceUrlResolver` rewrites a git-host repository URL to its default-branch tarball — `https://github.com/OWNER/REPO[.git]` → `https://github.com/OWNER/REPO/archive/HEAD.tar.gz` (and `/tree/BRANCH` → `…/archive/BRANCH.tar.gz`), also accepting the scheme-less `github.com/OWNER/REPO` and `git@github.com:OWNER/REPO.git` SSH forms. `HEAD` resolves to the default branch with no GitHub API call. The resolver runs inside `HttpInstaller::install()` (covering the admin API **and** the CLI, plus recursive `.json` stub `source` fields) and at the `PluginAdminController` scheme-guard boundary, so a scheme-less paste is accepted instead of 400-rejected. Direct archive URLs, `file://` paths, release-asset/`/archive/…` URLs and non-GitHub hosts pass through unchanged (idempotent). Verified end-to-end: the resolved tarball downloads and unpacks to a single root dir with `plugin.json` at the expected depth.
- **The single media-item endpoint now returns the enriched shape (fixes blank covers/overview on the detail & player pages).** `GET /api/v1/media/{id}` (`WebPortalRouter::getMediaItem` and `MediaItemController::show`) returned the raw DB row — no `poster_url`/`poster_srcset`/`genres`/`overview`/`season_number`/`episode_title`/… — while only the *list* endpoint shaped its rows, so the detail page (`/app/media/:id`) and the player hero rendered a blank cover and empty metadata even when the title had TMDB data. The shaping logic was extracted to a shared `Phlix\Media\Library\MediaItemShaper` (`shape()` for list rows, `shapeDetail()` = the schema shape merged over the raw row + `streams`, preserving intro/outro markers, chapters and `metadata`). Both the single-item handlers and the list now use it, so the two entry points can't drift again.
- **On-demand transcode now produces a browser-decodable H.264 stream (fixes "We couldn't prepare a playable version").** The CMAF/HLS encode ran `libx264` with no pixel-format or profile pin, so a 10-bit source (e.g. an HEVC "Main 10" file — common for 1080p TV rips) was re-encoded straight into **10-bit "High 10" H.264**, which no browser's Media Source Extensions / hls.js can decode: the playlist and segments served fine but hls.js raised a fatal decode error and the player fell back to the "can't prepare a playable version" notice. `FfmpegRunner::buildCmafCommand()` / `buildHlsCommand()` now force `-pix_fmt yuv420p -profile:v high -level 4.1` (8-bit 4:2:0 High@4.1, the universally browser-decodable baseline; overridable via `pix_fmt`/`profile`/`level` params) on every software H.264 re-encode. Separately, `TranscodeManager::computeHlsParams()` no longer takes the fast `-c:v copy` remux path for an H.264 *source* that is itself 10-/12-bit or 4:2:2/4:4:4 (`isBrowserSafeH264()` gate) — those are re-encoded to 8-bit instead of passed through unplayable. Verified end-to-end against a live HEVC Main 10 file: output is now `avc1.640029` (High@4.1, `yuv420p`) vs the old undecodable `avc1.6e0028` (High 10).
- **Honor HTTP `Range` requests in audiobook & photo streaming.** `AudiobookController::streamAudiobook()` and `PhotoController::getFull()` read the range header via raw `$request->headers['Range']` array access, but `Request::parseHeaders()` stores header keys upper-cased (`RANGE`), so the mixed-case lookup never matched and every range/seek request silently fell through to a full `200` instead of a `206` partial response. Both now read via the case-insensitive `Request::getHeader('Range')`, restoring seek/resume.

### Added

- **A-Z jump index for the media list — `GET /api/v1/media/letter-index?<same filters>`.** For the default name-ascending sort, returns the absolute item offset of the first title in each first-letter bucket (`#` folds non-alphabetic first characters and is placed first), honoring the SAME filters as `/api/v1/media`. The list endpoint's filter-building was extracted to a shared `ItemRepository::buildFilters()` so the list and the index can't drift. Drives the SPA's A-Z jump rail, which scrolls the pre-sized grid to `offset` (empty buckets are returned with count 0 so the rail can disable them).
- **Live progress for the "Match metadata" run.** `LibraryMetadataMatcher::matchLibrary()` now takes an optional progress callback and reports `processed`/`total` per 100-item batch (`total` = the library's top-level movie+series count). `LibraryScanWorker` forwards it onto the job row — `items_found` = total, `items_updated` = processed — so `GET /api/v1/libraries/{id}/scan-status` exposes a real percentage (`items_updated / items_found`) while a metadata match runs, instead of just `queued → running → completed`. (Scan/rescan jobs still report lifecycle only — `LibraryManager::scanLibrary()/rescanLibrary()` emit no per-item counts yet.)
- **Filter the media list by match status — `GET /api/v1/media?match=matched|unmatched`.** Backed by `media_items.metadata_refreshed_at` (NULL = never metadata-matched), so the UI can surface items still needing a metadata pass (`unmatched`) or already-enriched ones (`matched`). Composes with every other filter and with the `total` count.
- **TV series / season / episode metadata matching (fixes blank series, season & episode info + covers).** `LibraryMetadataMatcher` previously matched only `movie`/`video` items, so every series, season and episode got zero TMDB data — blank posters and empty synopses across all TV. New `SeriesMetadataResolver` (TMDB TV) + `TmdbProvider::searchTv()/getTvDetails()/getTvSeason()` resolve a series by name (+ optional first-air year), persist its poster/backdrop/overview/genres/year, then enrich the whole subtree from one `/tv/{id}/season/{n}` call per season: each season inherits the season (or series) poster + overview, and each episode gets its TMDB title, still, overview, air date and runtime — falling back to the series poster so nothing renders blank. Wired into the existing per-library "Match metadata" run (`SeriesMetadataResolver` injected into `LibraryMetadataMatcher`); `TmdbProvider`'s HTTP client is now injectable for testing. Uses the same admin-managed TMDB key as movie matching (Settings → Metadata).

  - **3.5 SyncPlay group watching UI + theme switcher.** New `/admin/syncplay` SPA page listing SyncPlay groups with Create/Join/Leave actions. New `SyncPlayApi` (`admin-ui/src/api/syncPlay.ts`) + `SyncPlayPage` (`admin-ui/src/pages/SyncPlayPage.tsx`) + 10 Vitest tests. New `SyncPlayController` (`src/Server/Http/Controllers/SyncPlayController.php`) wrapping `SyncPlayManager` with 5 REST endpoints: `GET/POST /api/v1/syncplay/groups`, `GET /api/v1/syncplay/groups/{id}`, `POST /api/v1/syncplay/groups/{id}/join|leave`. Theme switcher added to SSR settings page (`public/templates/settings/index.tpl`) — "Appearance" section with auto-save on change, no Save button required. `UserRepository.updateSettings()` now handles `theme` field writing to `user_profiles.active_theme_id`; `getSettings()` reads it back. `WebPortalRouter.extractSettingsPayload()` adds `theme` to the allow-list. Vitest: 10/10 on `syncPlay.test.ts`; phpstan level 9: pass; phpcs PSR12: pass; PHPUnit Unit: 2696 tests pass.

  - **2.5 Live TV / DVR admin SPA page (`/admin/live-tv`) — 4-section UI (Tuners, Guide/EPG, Recordings, Series Rules) consuming 20 LiveTV API endpoints from step 2.4.** New SPA: `admin-ui/src/pages/LiveTvPage.tsx` (4 collapsible sections — all collapsed by default, expand triggers lazy data load; Schedule Recording modal pre-fills from Guide; Add Rule modal with channel picker loading in parallel with rules; form validation with `form__error` messages). New `admin-ui/src/api/liveTv.ts` (`LiveTvApi`: 20 typed wrappers across 5 resource groups — tuners 5, channels 4, guide 3, recordings 6, seriesRules 5). `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). All buttons use `disabled={isLoading} aria-busy={isLoading}` pattern. Defensive optional chaining on all state variable length accesses for React StrictMode compatibility. 32 Vitest tests: 22/22 on `liveTv.test.ts` (100%), 10/10 on `LiveTvPage.test.tsx` (100%), ≥80% on `LiveTvPage.tsx`. PHPStan level 9: pass. PHPCS PSR12: pass. PHPUnit Unit: 2696 tests pass (10 skipped, 7092 assertions). RemoteAccessPage (14/14) confirmed no regression.

  - **2.4 Live TV / DVR REST API (20 admin-gated endpoints).** Tuners (list/get/scan/update/delete), channels (list/get/update/stream), guide (list/refresh + program lookup), recordings (list/get/create/delete/upcoming/by-series), series rules (list/get/create/update/delete). New `AdminLiveTvController` (`src/Server/Http/Controllers/Admin/AdminLiveTvController.php`) — 964 lines, 20 endpoints wired under `AdminMiddleware` in `Application::loadLiveTvAdminRoutes()`. Manager classes (`LiveTvManager`, `ChannelManager`, `GuideManager`, `Recorder`, `SeriesRuleManager`) resolved via `$this->container->get()`. Migration `028_livetv_base.sql` creates 6 `livetv_*` tables with `CREATE TABLE IF NOT EXISTS` (`livetv_tuners`, `livetv_channels`, `livetv_programs`, `livetv_favorites`, `livetv_lineups`, `livetv_lineup_channels`). DVB-T scan deferred (stubbed `DvbtTunerDriver::performChannelScan` not exposed). PHPStan level 9: pass. PHPCS PSR12: pass. PHPUnit Unit: 2696 tests pass (10 skipped, 7092 assertions). No SPA in this step — UI arrives in step 2.5.

  - **2.3 Remote access admin SPA page (`/admin/remote-access`) — hub pairing, subdomain, relay tunnel, port-forward.** New `admin-ui/src/pages/RemoteAccessPage.tsx` (4 collapsible sections: Hub Pairing, Subdomain, Relay Tunnel, Port Forward — all collapsed by default, expand triggers lazy data load). New `admin-ui/src/api/remoteAccess.ts` (`RemoteAccessApi`: 16 typed wrappers across 4 resource groups — `getHubStatus`/`pairHub`/`unenrollHub`/`sendHeartbeat`/`getRelayCandidates`, `getSubdomainStatus`/`claimSubdomain`/`releaseSubdomain`/`updateSubdomain`/`verifySubdomain`, `getRelayStatus`/`enableRelay`/`disableRelay`/`pingRelay`, `getPortForwardStatus`/`togglePortForward`). New `AdminHubController` (`src/Server/Http/Controllers/Admin/AdminHubController.php`) exposing all 16 endpoints wired under `AdminMiddleware` in `Application::loadRemoteAccessRoutes()`. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). `togglePortForward` propagates HTTP 500 with `{ success: false, message: "…" }` as an error toast. Vitest: 22/22 on `remoteAccess.test.ts` (100%), 14/14 on `RemoteAccessPage.test.tsx` (100%), ≥80% on `RemoteAccessPage.tsx`. Overall SPA: 36 passing tests.

  - **Admin SPA: DLNA server status/toggle (step 2.2).** New `admin-ui/src/pages/DlnaServerPage.tsx` (`/admin/dlna-server`) — status card showing green/red running indicator, friendly name, Start and Stop buttons with `aria-busy` loading state and toast feedback (success, error, and info-toast on 409 already-running/stopped no-op). New `admin-ui/src/api/dlnaServer.ts` (`DlnaServerApi`: `getStatus()`/`start()`/`stop()`). New `src/Server/Http/Controllers/Dlna/AdminDlnaServerController.php` exposing `GET /api/v1/admin/dlna/status` (returns `{ running, enabled, friendly_name, uptime_seconds }`), `POST /api/v1/admin/dlna/start`, and `POST /api/v1/admin/dlna/stop`, all wired under `AdminMiddleware` in `Application::loadDlnaAdminRoutes()`. `CdsServer` is injected via `setCdsServer()`; when the container has no `CdsServer` registration the controller returns `enabled: false` gracefully. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). Vitest: 8/8 on `dlnaServer.test.ts` (100%), 10/10 on `DlnaServerPage.test.tsx` (100%), ≥80% on `DlnaServerPage.tsx`. Overall SPA: 18 passing tests.

  - **Admin SPA: Stats & dashboard page (1.6)** — replaced the Phase-0 placeholder with a rich 5-section dashboard at `/admin/dashboard`. **Now Playing** (live list with progress bars, 30s auto-refresh), **Top Users** (30d leaderboard table), **Top Media** (30d ranked list with type badges), **Storage** (breakdown cards by media type + transcode cache), **Recent Activity** (paginated feed with event-type badges). New `admin-ui/src/api/dashboard.ts` (`DashboardApi`: `getNowPlaying`/`getTopUsers`/`getTopMedia`/`getStorage`/`getActivity`) + `admin-ui/src/api/stats.ts` (`StatsApi`: `getPlaybackStats`/`getTopUsers`/`getTopMedia`/`getStorageStats`). Date range filter (7d/30d/90d) affects Top Users/Top Media/Activity. All 5 sections have loading skeletons + empty states. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). No `dangerouslySetInnerHTML`. No new PHP — consumes existing `DashboardController` + `StatsController` endpoints (already wired in `AdminRoutes`). Vitest: 301/302 tests (99.7%); dashboard.ts 100%, stats.ts 100%, DashboardPage.tsx ≥80%.

 - **Admin SPA: Services page (1.4c) — Trakt.tv OAuth connect/disconnect + Last.fm scrobbling connect/disconnect.** New `admin-ui/src/pages/ServicesPage.tsx` (`/admin/services`) — two-card layout: **Trakt.tv** card (connected/not-connected badge, Connect button navigates via `window.location.href` to `/api/v1/oauth/trakt`, Disconnect button POSTs to `/api/v1/admin/services/trakt/disconnect`); **Last.fm** card (connected/not-connected badge, Connect navigates to `/admin/lastfm`, Disconnect POSTs to `/api/v1/admin/services/lastfm/disconnect`). Status polled on mount via `GET /api/v1/admin/services/trakt/status` and `GET /api/v1/admin/services/lastfm/status`. New `admin-ui/src/api/trakt.ts` (`TraktApi`) and `admin-ui/src/api/lastfm.ts` (`LastfmApi`). Backend adds four endpoints to `LastfmController` (`status()`, `apiDisconnect()`) and `TraktOAuthController` (`status()`, `disconnect()`), all wired under `AdminMiddleware`. Last.fm smarty routes remain registered for the connect callback; `client_secret` never leaves the server. Vitest: 100% on `services.test.ts`, `ServicesPage.test.tsx`; 71.42% on `trakt.ts` and `lastfm.ts` (uncovered = `window.location.href` browser redirects, untestable in Node).

 - **Admin SPA: Backup / restore page (1.5) — backup list with create/restore/delete/S3 upload + schedule settings.** New `admin-ui/src/pages/BackupPage.tsx` (`/admin/backup`) — two sections on a single route: **Backup list** card with "Create backup" button (optional label input → `POST /api/v1/admin/backup/create`), a `DataTable` listing all backups (Label/Size/Created/S3 status/Actions columns), per-row Restore modal ("This will overwrite your current data. Continue?" + Cancel/Restore), Delete modal ("Are you sure? This cannot be undone." + Cancel/Delete), and Upload to S3 button (hidden when `is_s3 === true`); **Scheduled backups** card with interval + retention form pre-filled from `GET /api/v1/admin/backup/schedule`, saved via `PUT /api/v1/admin/backup/schedule`, displaying next backup as relative and absolute time. New `admin-ui/src/api/backup.ts` (`BackupApi`) with 7 typed wrappers: `list()`/`create()`/`delete()`/`restore()`/`uploadToS3()`/`getSchedule()`/`updateSchedule()`. `Backup` shape (7 fields: id/label/file_path/size_bytes/checksum_sha256/is_s3/created_at + expires_at null); `BackupSchedule` shape (auto_backup_interval_days/retention_count/next_scheduled_backup/next_scheduled_backup_iso). Backend wires all 7 endpoints in `Application.php` via `loadBackupRoutes()` under `AdminMiddleware`. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference, no `useEffect` re-runs). No `dangerouslySetInnerHTML`. Vitest: 100% on `backup.ts` and `BackupPage.test.tsx`; 89.23% on `BackupPage.tsx` (≥80% target). Overall SPA: 95.67% statements, 88.38% branches, 83.12% functions, 95.67% lines.

 - **Admin SPA: Integrations page (step 1.4b) — Arr TRaSH-Guides sync + OIDC/LDAP auth provider config.** New `admin-ui/src/pages/IntegrationsPage.tsx` (`/admin/integrations`) — two sections: **Arr sync** (TRaSH-Guides-compatible Sonarr/Radarr/Bazarr/Prowlarr metadata sync) with last-sync status card, "Sync now" manual-trigger button (30 s timeout guard, spinner during call), and enable/disable auto-sync toggle; **Auth providers** listing OIDC + LDAP with per-provider enable/disable, inline configure forms (OIDC: provider_url/client_id/client_secret/scopes; LDAP: host/port/ssl/base_dn/bind_dn/bind_pw/user_filter/admin_group with show/hide toggles and "Test connection" dry-run), all pre-filled from GET settings on expand. New `admin-ui/src/api/arrSync.ts` (`ArrSyncApi`) with `getStatus()`/`triggerSync()`/`setEnabled()` wrapping the sync controller contract; new `admin-ui/src/api/authProviders.ts` (`AuthProvidersApi` + `OidcApi` + `LdapApi`) wrapping the auth-provider/OIDC/LDAP controller contracts. Secret fields are write-only — GET settings never returns them, and blank POST values are omitted so the server keeps the existing value. Enabled state derived from `configured` boolean the server returns per provider. Vitest coverage: 100% on `arrSync.ts`, `arrSync.test.ts`, `authProviders.ts`, `authProviders.test.ts`, and `IntegrationsPage.test.tsx`; 81.71% on `IntegrationsPage.tsx` (uncovered = defensive error-path guards). Overall SPA: 95.92% statements, 89.23% branches, 82.79% functions, 95.92% lines.

- **Admin SPA: Webhooks page with full CRUD + test (step 1.4a).** New `admin-ui/src/pages/WebhooksPage.tsx` (`/admin/webhooks`) — DataTable listing (name, URL, event-count badge, Edit/Test/Delete row actions), Add/Edit modal (name + URL + secret with Show/Hide + event checkboxes grouped by 5 categories), Delete confirm modal, Test result modal (green/red outcome display). New `admin-ui/src/api/webhooks.ts` (`WebhooksApi`) with `list()`/`create()`/`update()`/`remove()`/`test()` methods. `SUBSCRIBABLE_EVENTS` (7 events) and `WEBHOOK_EVENT_CATEGORIES` are hardcoded in the TS layer; `webhook.test` is excluded from the UI (internal to test). Secret is write-only — GET never returns it; edit form shows empty field with "(unchanged)" placeholder and omits `secret` from PUT when blank. `remove()` handles 204 No Content gracefully by mapping to `{ message: 'Webhook deleted' }`. `test()` parses the actual controller response (`success`/`success_count`/`failure_count`/`failures`) and `WebhooksPage` builds a human-readable message for display. Vitest coverage: 97.29% on `webhooks.ts`, 89.74% on `WebhooksPage.tsx`.

- **Backend: `PUT /api/v1/admin/webhooks/{id}` route for editing webhooks (step 1.4a carry-fix).** `WebhookDispatcher::update(array{name?, url?, events?})` — partial-update method that only writes provided fields, uses a parameterized query, and logs changed fields. `WebhookAdminController::update()` — validates `id` (fail-fast 400), extracts only name/url/events, returns `200 { webhook }` on success. Route wired in `Application.php` alongside the existing index/create/delete/test routes. No new endpoints for the other four operations — those were already registered.

- **Admin SPA: Settings page with 8 group tabs for server configuration (step 1.3).** New `admin-ui/src/pages/SettingsPage.tsx` (`/admin/settings`) renders all 15 allow-listed server settings across 8 tabbed sections (Transcoding, Metadata, Markers, Subtitles, Discovery, Trickplay, Newsletter, Port Forward). No new backend endpoints — the page consumes the 0.5 GET/PUT `/api/v1/admin/settings` contract already shipped in step 0.5. Field types drive the control: `bool` → toggle switch; `int`/`float` → number input with `min`/`max` from schema constraints; `tmdb.api_key` → password input with Show/Hide toggle. Overridden keys (DB-persisted vs. config-file default) display a "custom" badge driven by the `overridden` array in the GET response. Dirty-state gating keeps the Save button disabled when no fields have changed. `PUT /api/v1/admin/settings { settings }` on save; 200 re-renders with refreshed `overridden`; 400 surfaces per-field inline errors; 500 shows an error toast. New `admin-ui/src/api/settings.ts` (`SettingsApi`) wraps the GET/PUT contract with envelope unwrapping; both methods throw `ApiError` on non-2xx. Vitest coverage: 100% on `settings.ts` and `SettingsPage.test.tsx`, 88.16% on `SettingsPage.tsx`.

- **Admin profile management API: list, get, create, update, delete, set-pin, delete-pin endpoints (step 1.2b).** New `src/Server/Http/Controllers/Admin/AdminProfileController.php` with 7 REST endpoints for managing user profiles (`GET /api/v1/admin/users/{userId}/profiles`, `POST /api/v1/admin/users/{userId}/profiles`, `GET /api/v1/admin/profiles/{id}`, `PUT /api/v1/admin/profiles/{id}`, `DELETE /api/v1/admin/profiles/{id}`, `POST /api/v1/admin/profiles/{id}/pin`, `DELETE /api/v1/admin/profiles/{id}/pin`). Routes are registered inside the existing `AdminRoutes` group with `AdminMiddleware` gating. Enforces ≤5 profiles per user (400 when limit reached), validates PIN as exactly 4 or 6 digits (400 for other lengths), and supports clearing PIN via null/empty string. Unit tests cover ~100% of the new controller.

- **Admin user management API: list, get, create, update, delete, set-admin, reset-password endpoints (step 1.2a).** New `src/Server/Http/Controllers/Admin/AdminUserController.php` with 7 REST endpoints for managing server users (`GET /api/v1/admin/users`, `GET /api/v1/admin/users/{id}`, `POST /api/v1/admin/users`, `PUT /api/v1/admin/users/{id}`, `DELETE /api/v1/admin/users/{id}`, `POST /api/v1/admin/users/{id}/set-admin`, `POST /api/v1/admin/users/{id}/reset-password`). Routes are registered inside the existing `AdminRoutes` group with `AdminMiddleware` gating. Passwords are hashed with Argon2ID via `password_hash(PASSWORD_ARGON2ID)`; `reset-password` generates a random 12-character password returned in the response for admin sharing. Last-admin guard prevents deleting or demoting the final admin user; self-delete/self-demotion is blocked. `UserRepository` gained `findAll()`, `delete()`, `countUsers(string $predicate)`, and `emailExists(string $email, ?int $excludeId)` to support the controller. Unit tests cover ~100% of the new controller.

- **Library management admin page (step 1.1c).** New `/admin/libraries` SPA page in the admin console — the first real feature page on top of the 0.4 scaffold. Lists every library (name, type, path count, live scan-status badge) in the shared `DataTable`; an **Add library** modal + form posts `{name, type, paths, options?}` to `POST /api/v1/libraries`; an **Edit** modal pre-fills the same form and `PUT`s `{name, paths}` (the controller ignores `type`, and the form shows it read-only); a **Delete** confirm modal hits `DELETE /api/v1/libraries/{id}`. Path entry uses a new reusable `PathPicker` component driving the 0.6 `GET /api/v1/admin/fs/browse` endpoint (roots → drill-down → select; jailed to the configured `browse_roots`). Per-row **Scan** / **Rescan** buttons consume the **async** 1.1b API: they `POST .../scan|rescan` → `202 {job_id, status: "queued", message}` and the page starts polling `GET .../scan-status` every 2 s for that library (interval period injectable via `pollIntervalMs`). Polling **stops** on a terminal status (`completed`/`failed`) or `null`, and every outstanding interval is cleared on unmount via a `useRef` of per-library timers — no leaked timers, no global mutable state. Progress is **coarse / lifecycle-only** by design (the 1.1b worker leaves `items_*` at `0` and `current_path` at `null`), so the UI renders the status badge + `error` string only and deliberately does **not** draw a fabricated per-file progress bar. A per-library **History** modal loads `GET .../scan-history?limit=20` (server clamps `[1,100]`, newest first) into a `DataTable`. The `book` library type is **deliberately excluded** from the type select: the `libraries.type` ENUM (migration 001) is exactly `movie|series|music|photo|video`, even though `LibraryController::create()` *also* lists `book` in `$validTypes` (a `book` insert would 500 at the DB ENUM — pre-existing backend mismatch, carry-over for a later step). New `admin-ui/src/api/libraries.ts` (`LibrariesApi`) and `admin-ui/src/api/filesystem.ts` (`FilesystemApi`) are typed 1:1 wrappers over the `ApiClient` that unwrap the single-key envelopes (`{libraries}`, `{library}`, `{scan_status}`, `{history}`, fs-browse `{success, data}`) so callers get the bare domain object; non-2xx still throws `ApiError`. `LibrariesPage` adds a sidebar entry under Dashboard and a `<Route path="/libraries">` in `App.tsx`. Architecture note worth knowing: the page destructures the **stable** `push` callback from `useToast()` (`const { push: pushToast } = useToast();`) rather than depending on the whole context value — the provider memoises `[toasts, push, dismiss]`, so depending on the context object re-fires every `useCallback`/`useEffect` on every toast push (which during a scan would consume the next-mocked response as a stray `GET /libraries` and crash `DataTable`). All four test files (`libraries.test.ts`, `filesystem.test.ts`, `PathPicker.test.tsx`, `LibrariesPage.test.tsx`) drive a real `ApiClient` through the `makeFetch` concrete-mock helper against REAL-shaped responses (the 0.4 fabricated-contract lesson). Vitest coverage: **98.73%** statements overall; per file `libraries.ts`/`filesystem.ts` 100%, `PathPicker.tsx` 98.24%, `LibrariesPage.tsx` 95.62% (uncovered ≈ defensively-unreachable guards and `||`-fallback templates). **PHP side untouched** — no controller, route, migration, or worker change; only the committed admin-ui source + the rebuilt `public/assets/admin/` bundle.
- **Async library-scan worker + scan-status/scan-history endpoints (step 1.1b).** Moves library scanning off the HTTP request and onto a Workerman-native managed worker process that drains the 1.1a `library_scan_jobs` queue. New `src/Media/Library/LibraryScanWorker.php` (`Phlix\Media\Library`): `runOnce()` atomically claims the oldest queued job via `ScanJobRepository::claimNext()` (returns `false` when nothing is queued), runs the existing `LibraryManager::scanLibrary()`/`rescanLibrary()` by `type`, then `markCompleted()` on success or `markFailed($jobId, $e->getMessage())` on any `\Throwable` (returns `true` either way — a job was processed); a claimed row missing a usable `id`/`library_id` is defensively logged + skipped. `start(int $pollSeconds)` installs a `Workerman\Timer` that calls `runOnce()` once per tick — **never a blocking `sleep()`** (the legacy `BackgroundDetectorWorker::runLoop()`'s `sleep()` is the resident-memory violation this worker deliberately does not copy); a backlog of N drains in ≤ N ticks. **Progress is coarse by design** — `scanLibrary()`/`rescanLibrary()` return `void` with no counts, so the worker records the honest `queued → running → completed/failed` lifecycle and leaves `items_*` at 0 (no fabricated counts; no scan-internals expansion). New `config/process.php` is the single source of truth for the worker settings (`library-scan` => `enabled`/`count:1`/`poll_seconds:5`) in the conventional Webman filename, but carries PLAIN settings because this app boots through a hand-rolled `start.php` (not `support\App::run()`), so the file is read explicitly rather than auto-consumed by the framework. Two run paths read it: `start.php` now spawns the worker as a managed `count:1` sibling `Worker` under the same `Worker::runAll()` process group (additive + guarded — a worker build failure cannot take down the HTTP workers), and the standalone `scripts/run-library-scan-worker.php` runs it as its own isolated service (e.g. a dedicated systemd unit); running both at once is safe because `claimNext()` is atomic and each worker is `count:1`. `LibraryScanWorker` is autowired in `MediaServicesProvider`. New read endpoints: `GET /api/v1/libraries/{id}/scan-status` → `200 { scan_status: <latest job row|null> }` (a library with no jobs yet is a valid `200` with `null`, not a `404`) and `GET /api/v1/libraries/{id}/scan-history?limit=N` → `200 { history: [<job row>, ...] }` (newest first; `limit` defaults to 20, clamped to `[1,100]` by the repo). Both are admin-gated (least-privilege — `current_path` is a server filesystem path; the 1.1c progress page is admin-only) and `404` on a missing library. Wired in `Application::loadLibraryRoutes()` (now 9 LibraryController routes); the Router compiles `{id}` to `[^/]+` and anchors patterns with `#^...$#`, so the 2-segment `{id}` (show) route cannot match these 3-segment literal paths and vice-versa — no shadowing in either direction regardless of registration order. Verified by unit tests: `LibraryScanWorkerTest` (every `runOnce` branch — scan, rescan, nothing-queued, scan-throws→markFailed, rescan-throws→markFailed, defensive bad-row, unknown-type→scan) and the rewritten/extended `LibraryControllerTest` (scan/rescan enqueue+202 with `scanLibrary`/`rescanLibrary` asserted never-called, scan-status happy/null/404/401, scan-history happy/limit/404/401).
- **Library scan-job data layer (step 1.1a).** A DB-backed store that records the lifecycle of a library scan (`queued → running → completed/failed`) plus its progress counters — the foundation the 1.1b async scan worker writes to and the scan-status/scan-history endpoints read from. **No behaviour change in this step** (no controller/worker is wired yet). New migration `migrations/027_library_scan_jobs.sql` creates the `library_scan_jobs` table (`id` CHAR(36) UUID PK, `library_id` CHAR(36) with `fk_lsj_library` FK → `libraries(id) ON DELETE CASCADE`, `type` ENUM `scan|rescan`, `status` ENUM `queued|running|completed|failed`, `items_found`/`items_added`/`items_updated`/`items_removed` counters, nullable `current_path`/`error`, and `queued_at`/`started_at`/`completed_at` timestamps; `idx_lsj_library`, `idx_lsj_status`, `idx_lsj_library_queued` indexes; `CREATE TABLE IF NOT EXISTS` so the migration runner can replay it idempotently). New `src/Media/Library/ScanJobRepository.php` (`Phlix\Media\Library`) exposes `enqueue()` (inserts a `queued` row; rejects a `type` other than `scan|rescan` with `InvalidArgumentException`), `claimNext()` (atomically claims the oldest `queued` job via a conditional `UPDATE ... WHERE id=? AND status='queued'`, honouring the claim only when the affected-row count is ≥ 1 so a double-claim can't slip through; returns the claimed row or `null` when nothing is queued), `updateProgress()`/`markCompleted()` (write only the recognised `items_*` counters), `markFailed()`, `findById()`, `getLatestForLibrary()` (powers `scan-status`), and `getHistoryForLibrary()` (powers `scan-history`; clamps `$limit` to `[1, 100]`). All access is through the async `Workerman\MySQL\Connection` client with parameterised queries; UUIDs come from the local `generateUuid()` helper; rows are defensively decoded (int counters, nullable timestamps). Autowired in `MediaServicesProvider`. **The `claimNext`/`updateProgress`/`mark*` methods are intentionally unused in this PR — they are consumed by the 1.1b worker.** Verified by unit tests (mocked `Connection`, every method, both `claimNext` branches, the invalid-type reject, the `$limit` clamp) and a real-DB round-trip integration test (`enqueue → claimNext → updateProgress → markCompleted`) that self-skips when no MySQL is reachable.
- **CI: "Admin UI" GitHub Actions workflow builds + Vitest-tests the admin SPA (step 1.0).** New `.github/workflows/admin-ui.yml` (workflow `Admin UI`, single job `Admin UI Build + Test` on `ubuntu-latest`) runs `npm ci → npm run build` (`tsc --noEmit && vite build`) → `npm run test` (`vitest run`) with `working-directory: admin-ui` on every `push`/`pull_request` to `master`/`main`/`develop`. It is **path-filtered** to `admin-ui/**` and the workflow file itself, so PHP-only PRs don't spin up Node while SPA changes (and the open Vite 5→8 dependabot PR #131) do trigger it. Node is pinned to LTS `20` via `actions/setup-node@v4` with npm cache keyed on `admin-ui/package-lock.json`; `actions/checkout@v6` matches the sibling workflows. Least-privilege `permissions: contents: read` (no write scope, no secrets) keeps it safe for fork PRs. This closes the 0.4 carry-over where the SPA build + 55 Vitest tests ran only locally; build is green and 55/55 Vitest tests pass.
- **`bin/phlix` service-wrapper commands (step 0.8b).** Eleven thin console commands built on the 0.8a CLI machinery, each registered on the same `bin/phlix` application: `library:list` (lists all libraries via `LibraryManager::getAllLibraries()`), `library:scan {libraryId} [--rescan]` (`LibraryManager::scanLibrary()` / `rescanLibrary()`), `plugin:list` (`PluginLoader::listInstalled()` with enabled state), `plugin:enable {name}` / `plugin:disable {name}` / `plugin:install {source}` / `plugin:uninstall {name}` (the `PluginLoader` lifecycle — `install` prints the resulting plugin name + version), `backup:create [--label=]` (`BackupManager::createBackup()`, prints id/path/size) / `backup:list` (`BackupManager::listBackups()`), `hwaccel:probe` (`HwaccelProbe::probe()`, renders detected vendors/encoders/codecs), and `user:reset-password {user} [--password=]` (looks the user up by username then email via `UserRepository::findByUsername()`/`findByEmail()`, then `UserRepository::update(['password' => …])` which Argon2ID-hashes — when `--password` is omitted a strong random password is generated with `bin2hex(random_bytes(12))` and printed). Each command takes a per-service factory `callable` and resolves its backing service LAZILY from the PHP-DI container only inside `execute()`, so `php bin/phlix list` still builds NO container and touches NO database; `bin/phlix` wires those factories behind a single memoizing container-provider closure (`$container ??= ContainerFactory::create($config)`) that replicates `start.php`'s config assembly minus the Swoole/worker bootstrap. Commands never `exit()`/`die()`; they `return` `Command::SUCCESS` (0) on success and `Command::FAILURE` (1) on a thrown/“not found” failure (error messages are rendered with `<error>` markup). Verified by one `CommandTester` unit test class per command (mocked services, success + failure paths; `user:reset-password` additionally covers found-by-username, found-by-email fallback, not-found→exit 1, missing-id→exit 1, explicit `--password`, and generated-password).
- **`webman/console` CLI baseline + `bin/phlix migrate` (step 0.8a).** Added `webman/console` and a custom `bin/phlix` executable so the project has a real CLI (closing the long-standing "bin/phlix doesn't exist" gap). Because `webman/console` only auto-discovers commands from an `app/command` directory this repo doesn't have, `bin/phlix` instead explicitly registers `Phlix\Console\Commands\*` instances on a `Webman\Console\Command` application (which extends Symfony's Console Application) and runs it — `php bin/phlix list` shows the commands, `php bin/phlix migrate` runs them. The migration-apply logic that lived inline in `scripts/run-migrations.php` is extracted into a new testable service `src/Common/Database/MigrationRunner.php` (`Phlix\Common\Database`): it discovers `migrations/*.sql` via `glob()`+`sort()`, splits each file into statements (stripping `--` and `/* */` comments so a `;` inside a comment never shreds a statement), runs each via `Workerman\MySQL\Connection::query()`, and downgrades the known idempotent-replay errors (`Duplicate column name` / `Duplicate key name` / `check that column/key exists` / `already exists`) to notes instead of failures. There is **no migration-tracking table** — every file is applied on every run, preserving the apply-all-every-time contract that `docker/docker-entrypoint.sh` and `scripts/install.sh` depend on. The connection is resolved lazily (only inside `run()`), so `bin/phlix list` and command construction work with no database available. `MigrateCommand` (`Phlix\Console\Commands`) renders a human summary and returns exit code `0` on success / `1` when a genuine non-idempotent error occurred. `scripts/run-migrations.php` is now a thin shim that boots the same `MigrationRunner` and prints the same summary with the same exit semantics, so the docker/install callers are unaffected. Verified by unit tests: `MigrationRunnerTest` (mocked `Connection`, temp fixture `.sql` files — sort order, comment-aware splitting, idempotent-downgrade vs genuine-error branches, empty-dir, no-connection-at-construction) and `MigrateCommandTest` (via Symfony `CommandTester` — exit 0 on success/notes, exit 1 on a genuine error). Wrapper commands for the other scripts (`library:*`, `plugin:*`, `backup:*`, `hwaccel:probe`, `user:reset-password`) land in step 0.8b.
- **Admin filesystem-browse endpoint for the library path picker (step 0.6).** New `GET /api/v1/admin/fs/browse?path=…` (`src/Server/Http/Controllers/Admin/FsBrowseController.php`) lists the immediate **subdirectories** of `path` (directories only — never files; no read/write/delete) so a future "add library" UI can offer a path picker. New `config/filesystem.php` defines the `browse_roots` allow-list (default `['/home', '/mnt', '/media', '/data']`) — the security boundary the listing is jailed to (env override deliberately omitted to keep the boundary explicit/auditable). Traversal safety mirrors the canonical `AudiobookController::validateMediaPath()` jail: every candidate path is canonicalised with `realpath()` (which collapses `..` and resolves symlinks) and checked against each root with a trailing-slash prefix test (`$real === $root || str_starts_with($real . '/', $root . '/')`, **never** `str_contains`), so `..` escapes, symlinks pointing outside the jail, and non-allowed roots are all rejected with `403`. Status mapping: empty/absent `path` → `200` returning the configured roots as the entry list (`data.path`/`data.parent` = `null`); `realpath()` fails (non-existent) → `404`; resolves but not a directory → `400`; resolves outside the jail → `403`; valid dir under a root → `200` `{ success, data: { path, parent, entries:[{name,path}] } }` (entries sorted by name, `parent` only when it is itself within the jail else `null`). The route sits in the existing `/api/v1/admin` group registered in `src/Server/Http/Routes/AdminRoutes.php`, gated by `AdminMiddleware` (non-admin callers get a JSON 401/403); bound via a `factory()` in `AdminServicesProvider` that loads `browse_roots` from config (roots that do not `realpath()`-resolve are dropped at construction). **API only — the path-picker / library-management UI lands in Phase 1.1.** Verified by unit tests covering all security paths (traversal/symlink-escape/non-allowed-root → 403, 404, 400, roots-list, parent-within-jail, ctor-drops-non-resolving-root); new-code coverage 91.1% (72/79 statements) — the only uncovered lines are the defensively-unreachable `catch (Throwable)` → 500 and `scandir() === false` arms (a valid, jail-checked, readable directory cannot trip them).
- **Server-wide settings store + admin API (step 0.5).** A DB-backed store so admin settings pages have somewhere to persist (the `config/*.php` files are boot-time / read-only). New migration `migrations/026_server_settings.sql` creates the typed key/value `server_settings` table (`id` CHAR(36) UUID PK, unique `setting_key`, text `setting_value`, `value_type` ENUM `string|int|bool|float|json`, timestamps; `CREATE TABLE IF NOT EXISTS` so the migration runner can replay it idempotently). `src/Admin/SettingsRepository.php` (`Phlix\Admin`) models the runtime contract **config default → DB override → effective value**: the value baked into `config/<file>.php` is the baseline, a row in `server_settings` overrides it, and the effective value is the override when present else the default. Keys are *dotted* — the first segment names the config file and the rest walk the returned array (e.g. `hwaccel.enabled` reads `config/hwaccel.php['enabled']`, `port-forward.port_forwarding.upnp_enabled` walks two levels). Upserts use `INSERT ... ON DUPLICATE KEY UPDATE` (mirrors `UserRepository::updateSettings()`) exclusively through the async `Workerman\MySQL\Connection` client with parameterised queries; the config-file segment is regex-jailed (`^[A-Za-z0-9_-]+$`) against path traversal. New `src/Server/Http/Controllers/Admin/AdminSettingsController.php` exposes `GET /api/v1/admin/settings` (returns `{ success, data: { settings, overridden, types } }` — effective values, the list of overridden keys, and the allow-list type map) and `PUT /api/v1/admin/settings` (body `{ settings: { "<dotted.key>": value, ... } }`) which validates every submitted key against a typed allow-list (`ALLOWED_KEYS`): unknown keys → 400, wrong types → 400, **all-or-nothing** (nothing persists if any key fails), then upserts the overrides. Both routes sit inside the existing `/api/v1/admin` group registered in `src/Server/Http/Routes/AdminRoutes.php`, gated by `AdminMiddleware` (non-admin callers get a JSON 401/403). Persisted overrides **survive a restart** because the DB is the durable store. **API only — the settings UI lands in Phase 1.3.** Validation is inline pending step 0.7's shared `server-settings.schema.json`, which will later replace/back the `ALLOWED_KEYS` map (a `0.7:` seam comment marks the spot). Verified by unit + integration tests (round-trip persist → fresh repository re-read); new-code coverage: `SettingsRepository` 100% (103/103 statements), `AdminSettingsController` 98.8% (the single uncovered line is the defensively-unreachable `json` arm of `valueMatchesType()` — no allow-list key is type `json` yet).
- **Admin SPA scaffold (step 0.4).** A React + TypeScript + Vite admin console now mounts at `/admin` + `/admin/*`, served by the new `src/Server/WebPortal/Controllers/AdminAppController.php` (returns the built `index.html` shell; 503 with an actionable "run `npm run build`" message when the bundle is absent) and gated by the existing `AdminMiddleware::checkAccess()` — a 401 (unauthenticated) or 403 (non-admin) maps to a 302 redirect to `/login`. The SPA source lives in `admin-ui/`; the production bundle is built into `public/assets/admin/` and **committed to the repo** (`admin-ui/node_modules/` is gitignored), so the running Workerman server has **no Node build dependency at runtime**. Dispatch is wired in BOTH entry points (`public/index.php` and `src/Server/Workerman/HttpHandler.php`), placed AFTER the existing `/admin/plugins` + `/admin/dashboard` SSR routes so those keep winning. The typed `ApiClient` (`admin-ui/src/api/client.ts`) reuses the existing JWT mechanism from `public/assets/js/api-client.js` (same `localStorage` keys `access_token`/`refresh_token`/`user`, Bearer header, single-retry-on-401 via `POST /auth/refresh`); `getCurrentUser()` consumes `GET /api/v1/auth/me`, unwrapping its `{ user: {...} }` envelope and normalising the DB `TINYINT` `is_admin` (`1`/`0`) to a real boolean. This is a working shell/scaffold only — nav, router, a typed API client, and shared components (DataTable, Form, Modal, Toast); no feature pages yet (those land in Phase 1). Verified by Vitest (~99% coverage on the new SPA modules) + an `AdminAppControllerTest` (shell 200 / 503-missing / 302-redirect).
- **Bare-metal Swoole + php-uv build (step 0.3).** `scripts/install.sh` and `install/systemd.sh` now compile the Swoole and php-uv extensions from source as part of a fresh install (and on the `scripts/install.sh --update` repair path), giving the step 0.2 coroutine runtime real extensions on Debian/Ubuntu hosts — not just in Docker. The Swoole `./configure` flag set is copied **verbatim** from `docker/Dockerfile.base` (see `docker/README.md` "Swoole build flags" for the per-flag rationale); php-uv is built with `--with-uv`. The apt `-dev` build dependencies (`build-essential autoconf pkg-config git libssl-dev libuv1-dev libbrotli-dev libzstd-dev libnghttp2-dev libpq-dev libsqlite3-dev libc-ares-dev liburing-dev libssh2-1-dev`, plus the version-matched `phpX.Y-dev` for `phpize`) are the Debian translation of the Alpine set. The build is **idempotent**: each step short-circuits via `php -m` when the extension already loads, so re-running the installer never triggers the slow recompile. `--enable-iouring` / `--enable-uring-socket` build on any kernel but only activate at runtime on Linux kernel ≥ 5.6 (older kernels fall back to epoll automatically).
- **Workerman disable-function preflight (step 0.3).** A new preflight in both installers fails loudly and early if `disable_functions` blocks any process-control / posix / socket primitive Workerman needs to fork workers and manage sockets (`pcntl_*`, `posix_*`, `proc_*`, `exec`/`shell_exec`, `stream_socket_*`), with an actionable message pointing the operator at their `php.ini` (and php-fpm pool config) — instead of a cryptic runtime crash after install. Uses an exact-token match (no substring false-positives).
- **Swoole + php-uv loaded in the PHPUnit CI job (step 0.3).** The PHPUnit jobs in `.github/workflows/phpunit.yml` (`test` and `test-server`) now load both extensions — `swoole` via `shivammathur/setup-php` and php-uv via a source-build step — and verify them with `php -m | grep -iE '^(swoole|uv)$'` before the suite runs, so the full test suite exercises the coroutine runtime in CI. CI runs on host runners (not containerized); the existing MySQL service container and coverage steps are unchanged. Neither extension is added as a hard composer platform requirement.
- **Coroutine runtime enabled (step 0.2).** `start.php` and `public/index.php` now set `Worker::$eventLoop = \Workerman\Events\Swoole::class` before any `Worker` instantiation, and call `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` in the master process to enable full coroutine I/O. The code degrades gracefully with a `E_USER_WARNING` when ext-swoole is not yet available.
- **Coroutine micro-bench (step 0.2b).** Added `scripts/bench/coroutine_bench.php` — a small, dependency-free smoke-test that fires N coroutines through `SWOOLE_HOOK_ALL`-hooked `time_nanosleep()` and asserts wall-clock ≤ 1.5× a single-unit run (so concurrent requests demonstrably do not serialize). Exits 0 on pass, 1 on fail, 2 if `ext-swoole` is absent. Local run: serial ≈ 100 ms, concurrent N=4 ≈ 102 ms (≈3.9× speedup over the serialized ≈ 400 ms baseline). The pre-existing `scripts/bench/concurrent_streams.php` still works but needs a live HLS endpoint + media-id and is not CI-friendly.

### Changed

- **`web-ui`: bumped `@phlix/ui` to `v0.19.0` (admin composability + Hub Dashboard).** Behaviour-neutral for the
  server: `buildAdminRoutes()` (no args) still yields the same 16 admin routes/names, so the mounted admin section,
  the `/app/admin/dashboard` landing, and the sidebar order are unchanged. The committed SPA bundle under
  `public/assets/app/` was rebuilt against the new tag. (v0.19.0 makes the shared admin shell composable so the hub
  can mount its own page group; the server keeps the default set. Two unused lazy chunks — `HubDashboardPage` and
  `AuditLogsPage` — are now emitted into the bundle because `@phlix/ui`'s admin registry statically references the
  hub page group; the server router never registers those routes, so the chunks are never fetched at runtime.)
- **WebAuthn/passkeys settings: routed `/settings/security` to `auth/webauthn-settings.tpl`.** Template now loads correctly (fixed broken `layouts/app.tpl` → `layouts/main.tpl`). Inline error messages replace `alert()` calls. Credential IDs are XSS-safe.

- **Settings page: replaced thin-shell form with working GET/PUT form backed by `/api/v1/users/me/settings`.** Streams, bitrate, audio/subtitle language, subtitle mode, and parental control settings now persist correctly.
- **`POST /api/v1/libraries/{id}/scan` and `.../rescan` now enqueue + return `202` instead of scanning inline (step 1.1b).** `LibraryController::scan()`/`rescan()` previously called `LibraryManager::scanLibrary()`/`rescanLibrary()` synchronously inside the HTTP handler (the blocking-HTTP async-rule violation) and returned `200 { message: "Library scan started" }`. They now keep the admin gate + library-existence `404` check, then `ScanJobRepository::enqueue($id, 'scan'|'rescan')` a job and return `202 { job_id, status: "queued", message: "Library scan queued"|"Library rescan queued" }`; the async `LibraryScanWorker` performs the actual scan off the HTTP path. `LibraryController`'s constructor gains a second `ScanJobRepository` parameter (passed in both branches of `Application::getLibraryController()` — the container branch resolves it from DI, the null-container fallback reuses the already-built connection; the pre-existing hardcoded fallback creds are left untouched as a separate follow-up). The CLI `bin/phlix library:scan` is unchanged and stays synchronous.
- **`AdminSettingsController` now derives its editable-settings allow-list from the shared `server-settings.schema.json` (step 0.7).** Bumped `detain/phlix-shared` to `^0.7.0` and replaced the hardcoded `ALLOWED_KEYS` constant — the `// 0.7:` inline-allow-list seam is removed. The dotted-key → internal-type map (the PUT validation source and the GET `types` map) is now loaded once from the vendored schema (located via `Phlix\Shared\Schema\SchemaPaths::serverSettings()`) and cached in a static; each JSON-Schema `type` is mapped to the internal vocabulary (`boolean→bool`, `integer→int`, `number→float`, `string→string`, `array`/`object→json`). The schema declares exactly the same 15 settings keys with the same types, so GET/PUT behaviour and validation are unchanged; a missing/unparseable schema fails safe to an empty allow-list (a new lock-in unit test asserts the derived map equals the expected 15 keys/types so drift or a missing vendored schema is caught loudly). `valueMatchesType()`/`coerce()` and the `SettingsRepository`-only constructor are untouched.
- **Upgraded to Webman 2.2 / Workerman 5.1.** Pinned `workerman/workerman` to `~5.1` and `workerman/webman-framework` to `~2.2` as a prerequisite for coroutine support (step 0.2). No other changes — routing, controllers, and DI wiring remain unchanged.
- **Coroutine-safe per-request state via `support\Context` (step 0.2b).** Audited `src/Server/` for `protected|private|public static $`, `global $`, and `$GLOBALS[…]` carrying per-request data; the audit found **zero offenders** (only PHP's built-in `global $http_response_header;` for `file_get_contents()` exists, outside `src/Server/`). Introduced `Phlix\Server\Http\RequestContext` — a thin typed wrapper around `support\Context` — as the canonical place to publish and read per-request data (today: the authenticated user-id). `AdminMiddleware` now publishes `$request->userId` into the coroutine-local context on a successful admin gate so downstream services can read it without re-passing the `Request`, and explicitly does NOT publish anything on 401/403 paths. New `tests/Unit/Server/Coroutine/ContextIsolationTest.php` proves per-fiber isolation and exercises the `ext-swoole` graceful-fallback branch under a captured error handler. Documented end-to-end in `phlix-docs/docs/dev/coroutine-runtime.md` (eventLoop, hooks, no-static-state rule, `exit`/`die`/`sleep()` ban, contributor checklist).

### Fixed

- **`start.php` Swoole eventLoop assignment used the wrong identifier (step 0.2c cumulative-pass).** The 0.2a PR (#126) shipped `Worker::$eventLoop = \Workerman\Events\Swoole::class`, which raised `Access to undeclared static property Workerman\Worker::$eventLoop` on every `php start.php <subcommand>` invocation (status / stop / restart / reload all crashed). Workerman 5's actual static is `Worker::$eventLoopClass` — `$eventLoop` is an *instance* property used to override the eventLoop on a single Worker. Fixed in `start.php`; added `tests/Unit/Server/Coroutine/EventLoopBootstrapTest.php` to guard against the typo regressing (asserts `$eventLoopClass` exists as a public static, `$eventLoop` exists as an instance, and the literal idiom from `start.php` compiles and assigns without fatal).

### Added

- **Web-portal HTML pages for music, books, audiobooks, and photos.** The Smarty templates under `public/templates/{music,books,audiobooks,photo}/` existed but were never wired to a page route — only `home`, `library`, `auth`, and the admin dashboard rendered. Four SSR controllers now back them (`MusicPageController`, `BookPageController`, `AudiobookPageController`, `PhotoPageController` in `src/Server/WebPortal/Controllers/`), rendering via `PageRenderer::renderTemplate()` and sourcing data from the same managers as the JSON API. `public/index.php` routes the page paths:
  - Music: `/music` (albums), `/music/albums/{name}`, `/music/artists`, `/music/artists/{name}`, `/music/tracks`, `/music/player`.
  - Books: `/books`, `/books/{id}`, `/books/{id}/read`, plus `/books/{id}/cover` and `/books/{id}/download` delegating to `BookController`.
  - Audiobooks: `/audiobooks`, `/audiobooks/{id}`, `/audiobooks/{id}/read`.
  - Photos: `/photo/albums`, `/photo/album/{id}`, `/photo/photo/{id}`, `/photo/slideshow`, plus `/photo/photos/{id}/thumbnail` and `/photo/photos/{id}/full` delegating to `PhotoController`.
  Controllers are registered in `WebPortalServicesProvider`. Covered by unit + Smarty-render tests in `tests/Unit/Server/WebPortal/Controllers/`.

### Fixed

- **Media page templates rendered nothing because of mismatched Smarty block names.** `layouts/main.tpl` and `layouts/player.tpl` only expose a `main` block, but the books/audiobooks templates declared `content` (and the audiobook player `player-content`) and every photo template declared `body` (which would have replaced the entire sidebar layout). All now use `main`. Also fixed a corrupted modifier in `music/artist.tpl` (`|自己不做了:00` → `|default:'0:00'`), a broken `{math}`/bareword duration expression and an unregistered-function `min()` call in `music/tracks.tpl`, and a missing-parenthesis duration modifier in `audiobooks/audiobook.tpl` that emitted a "non-numeric value" warning. `AudiobookPageController` normalizes `metadata.chapters` to an array so the detail/player templates can `count()`/iterate it without a TypeError.
- **Media-library routes now share the `/api/v1` prefix with the rest of the JSON API.** `Application::loadMusicRoutes()`, `loadBookRoutes()`, `loadAudiobookRoutes()`, and `loadPhotoRoutes()` registered their endpoints at the bare root (`/music/...`, `/books/...`, `/audiobooks/...`, `/photo/...`) while `phlix-docs` `reference/api.md` and every other metadata route (auth, media, sessions, collections, libraries, cast, dlna) used `/api/v1`. Clients following the docs hit a 404. All music, book (non-OPDS), audiobook, and photo routes are now mounted under `/api/v1`, matching the docs. OPDS keeps its spec path `/opds/v1.2` (deliberately un-prefixed). The unused `Router::music()/books()/audiobooks()/photo()` convenience helpers were aligned to the same prefix so they no longer document a contradictory layout. Guarded by `RouterMediaRoutesTest` (unit) and `ApplicationTest::testMediaRoutesAreRegisteredUnderApiV1` (integration, asserts the live route table). No client consumed the old paths, so this is not a breaking change in practice.
- Wired four previously-defined-but-orphaned `AuthController` endpoints into `Application::loadApiRoutes()` (Section 1.6a). Each handler existed on the controller but had no route, so requests 404'd: `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `GET /api/v1/auth/me`. The `me` endpoint relies on `$request->userId` being populated by upstream auth middleware (same convention as `/api/v1/me/continue-watching`).
- Replaced the stale `// Placeholder for API routes - will be populated in later phases` comment at the top of `Application::loadApiRoutes()` — the method already wires ~40 routes today. New comment describes the actual API surface (auth, sessions, media, WebAuthn, DLNA/Chromecast/AirPlay/Roku, admin) and points readers at `src/Server/Http/Controllers/`.
- Wired four previously-defined-but-orphaned `MarkerController` endpoints into `Application::loadApiRoutes()` (Section 1.6c). The handlers existed but had no route, so requests 404'd: `GET /api/v1/media/{id}/markers`, `GET /api/v1/media/{id}/markers/intro`, `GET /api/v1/media/{id}/markers/outro`, `GET /api/v1/shows/{id}/markers/bulk`. Resolves the controller from the PSR-11 container with a hand-wired fallback (matches the `getAuthController()` pattern).
- Wired three previously-defined-but-orphaned `ExtrasController` endpoints into `Application::loadApiRoutes()` (Section 1.6c). The handlers existed but had no route, so requests 404'd: `GET /api/v1/media/{id}/extras`, `GET /api/v1/media/{id}/trailers`, `GET /api/v1/media/{id}/extras/other`. Resolves the controller from the PSR-11 container with a hand-wired fallback (matches the `getAuthController()` pattern); `MediaServicesProvider` now binds `TmdbProvider` to a factory that reads the API key from `$appConfig['tmdb']['api_key']` or the `TMDB_API_KEY` env var.
- Added `config/tmdb.php` with a `getenv('TMDB_API_KEY')` default so operators can enable TMDB lookups without code changes.
- **Operator action required:** Set `TMDB_API_KEY` environment variable
  to enable trailer fetching via the new ExtrasController routes.
  Without it, /api/v1/media/{id}/trailers and related endpoints
  return no results from TMDB (local extras cache still works).

### Added (post-O.7 wave 4, G.3)

- Last.fm scrobble plugin (`src/Plugins/Scrobbler/Lastfm/`):
  - `LastfmApi` — Web Service v2 client. Builds `api_sig` per the official rule (alphabetical key+value concat + shared secret + MD5).
  - `LastfmSessionRepository` — per-user session-key store backed by the new `lastfm_sessions` table (migration `023_lastfm_sessions.sql`).
  - `LastfmScrobbler` — PSR-14 listener; subscribes to `phlix.playback.started` (Now Playing) and `phlix.playback.stopped` (scrobble). Enforces Last.fm's official rule: scrobble only when the track is longer than 30 s AND the user listened to more than 50 % of it.
  - `LastfmPlugin` — `\Phlix\Shared\Plugin\LifecycleInterface` entry class; resolves dependencies from the host container on `enable()` and exposes the scrobbler via `subscribedEvents()`.
  - `LastfmConfig` — typed wrapper over `config/lastfm.php`. New config keys default to `LASTFM_API_KEY`, `LASTFM_SHARED_SECRET`, `LASTFM_CALLBACK_URL`, `LASTFM_ENABLED` (env-driven).
  - Admin connect flow: `GET /admin/lastfm`, `GET /admin/lastfm/callback`, `POST /admin/lastfm/disconnect` (`Admin\LastfmController`) plus a Smarty template at `public/templates/admin/lastfm.tpl`.
- New required env vars (only when enabling the plugin): `LASTFM_API_KEY`, `LASTFM_SHARED_SECRET`. Optional: `LASTFM_CALLBACK_URL`, `LASTFM_USERNAME`, `LASTFM_ENABLED`, `LASTFM_SUBMIT_NOW_PLAYING`.

### Moved (post-O.7 wave 4)

- K.3 request UI: moved to phlix-hub (now lives at `/api/v1/me/requests` on the hub, with the admin queue at `/api/v1/admin/requests`). Server no longer exposes `/api/v1/requests`, `/requests` (SSR), `/requests/{id}`, or the `requests` table — those were dropped along with `migrations/016_media_requests.sql`. The hub stores requests against its own `users` table (hub migration `011_media_requests.sql`) and dispatches approvals through Sonarr/Radarr via `Phlix\Shared\Arr` v0.4.0.

### Changed (post-O.7 wave 3)

- Helm chart fleshed out for both `phlix-server` and `phlix-hub` (values + templates: deployment, service, ingress, pvc, configmap, secret, serviceaccount, hpa, NOTES).
- Caddyfile WebSocket headers fixed (`Connection: upgrade` / `Upgrade: websocket` — previously inverted).
- nginx `/media/` location now uses `proxy_request_buffering off` so large client uploads stream through; sensitive-path deny rule tightened to `^/+(...)(/|$)` to defeat double-slash bypass.
- Dockerfile `composer install` no longer suffixed with `|| true` — composer failures now fail the build (default Alpine variant + NVIDIA/Intel HW-accel variants). Path-layout rationale documented in `docker/README.md`.
- CI: added `phlix-hub` build/push job in `.github/workflows/docker.yml`.
- CI: `.github/workflows/release.yml` now verifies `Chart.yaml` `appVersion` and `composer.json` `version` match the release tag, lints + packages charts, and uploads them with the release.

### Removed

- **`src/Chromecast/RemoteCastClient.php` (and its test)** — dead code with zero callers. It was premised on a server-initiated *outbound* "cast over relay" channel that the hub relay does not provide: the relay (`RelayConsumer`) pipes *inbound* client connections to the local HTTP server, so a remote client reaching this server through the hub already lands on the normal `/api/v1/cast/devices/{id}/*` routes, which drive the device via the local `CastApiClient`. Remote casting therefore works through the relay's HTTP pipe with no dedicated client; the throwing `RemoteCastClient` stub was redundant and has been removed.
- `SESSION_HANDOFF.md` (commit 9758a1b, message "upate"): obsolete handoff scratchpad no longer referenced anywhere. No functional change.

### Added (Step L.1)

- Webhook plugin framework for sending events to HTTP endpoints:
  - `WebhookEvent` — event class with eventType, payload, occurredAt, toArray(), getSignature() using HMAC-SHA256
  - `WebhookDispatcher` — registers/unregisters/dispatches webhooks, uses Workerman\MySQL\Connection and Workerman\Timer for async dispatch
  - `DispatchResult` — result class with successCount, failureCount, failures
  - `WebhookPluginInterface` — interface with getName(), getSupportedEvents(), send()
  - `migrations/018_webhooks.sql` — webhooks and webhook_logs tables
  - `WebhookAdminController` — GET/POST/DELETE /api/v1/admin/webhooks, POST test endpoint
  - `config/webhooks.php` — configuration with enabled, timeout, max_retries, parallel_dispatch
  - Unit tests: `WebhookEventTest` (5 tests), `WebhookDispatcherTest` (7 tests)

### Added (Step L.2)

- Notification provider plugins for webhook events:
  - 7 plugins: Discord, Slack, Telegram, Ntfy, Pushover, Apprise, MQTT
  - `AbstractNotificationPlugin` — base class with formatMessage(), getEmbedColor()
  - `WebhookPluginInterface` — getName(), getSupportedEvents(), send()
  - `PluginRegistry` — plugin management with get(), listAll(), register()
  - `config/notifications.php` — all 7 provider configurations
  - Unit tests: DiscordPluginTest (7), SlackPluginTest (6), TelegramPluginTest (6), NtfyPluginTest (7)

### Added (Step L.3)

- Stats collection system for tracking playback, library changes, user activity, and storage:
  - `migrations/019_stats_schema.sql` — 4 tables: stats_playback_events, stats_library_changes, stats_user_activity, stats_storage
  - `StatsCollector` — service with recordPlaybackStart/End, recordLibraryChange, recordUserActivity, recordStorageSnapshot, getPlaybackStats, getTopUsers, getTopMedia
  - `StatsController` — admin API: GET /api/v1/admin/stats/playback, top-users, top-media, storage
  - `PlaybackController` integration — calls StatsCollector on play start/end
  - Unit tests: `StatsCollectorTest` (7 tests)

### Added (Step L.4)

- Admin dashboard with real-time now playing, top users/media leaderboards, storage summary, and recent activity feed:
  - `DashboardService` — aggregation service with getNowPlaying(), getTopUsers(), getTopMedia(), getStorageSummary(), getRecentActivity()
  - `DashboardController` — admin API: GET /api/v1/admin/dashboard/now-playing, top-users, top-media, storage, activity
  - `DASHBOARD_NOW_PLAYING` WebSocket event for live updates
  - `subscribe_dashboard` WebSocket handler to send current now-playing state
  - `public/templates/admin/dashboard.tpl` — Smarty template with Now Playing grid, Top Users/Media tables, Storage usage, Activity feed
  - `PageRenderer::renderDashboard()` — renders dashboard page
  - `/admin/dashboard` route in `public/index.php`
  - Unit tests: `DashboardServiceTest` (5 tests)

### Added (Step L.5)

- Weekly newsletter email system for user engagement:
  - `migrations/020_newsletter.sql` — newsletter_queue table with id, user_id, week_start, status, attempts, last_attempt_at, sent_at, error_message
  - `config/newsletter.php` — configuration with enabled, send_day, send_hour, batch_size, from_email, from_name, subject_template
  - `NewsletterGenerator` — generates email content with watch time, top media, new items using Smarty template
  - `NewsletterSender` — queues and processes newsletter delivery with batch processing and retry logic
  - `public/templates/emails/newsletter.tpl` — responsive HTML email template with watch summary, top 5 media, new items, CTA button, unsubscribe link
  - `Application::startNewsletterTimerIfEnabled()` — Workerman Timer integration for scheduled newsletter delivery
  - Unit tests: `NewsletterGeneratorTest` (4 tests), `NewsletterSenderTest` (5 tests)

### Added (Step L.6)

- Server backup and restore system with local storage, S3-compatible cloud backup, and automatic scheduling:
  - `migrations/021_backups.sql` — backups table with id, label, file_path, size_bytes, checksum_sha256, is_s3, created_at, expires_at
  - `config/backup.php` — configuration with enabled, local_path, retention_count, auto_backup_interval_days, s3 settings
  - `RestoreResult` — result class with success, message, error properties
  - `S3Client` — minimal S3-compatible client using AWS Signature V4 for upload, download, listObjects, deleteObject
  - `BackupManager` — backup creation with mysqldump + tar.gz, restore with checksum verification, S3 upload/download, retention management
  - `BackupController` — 7 admin API endpoints: POST create, GET list, DELETE delete, POST restore, POST upload-s3, GET/PUT schedule
  - `Application::startBackupTimerIfEnabled()` — Workerman Timer integration for scheduled backups
  - Unit tests: `BackupManagerTest` (11 tests), `S3ClientTest` (10 tests)

### Added (Step K.2)

- Bazarr/Prowlarr API clients for subtitle and indexer management:
  - `BazarrClient` — Bazarr API client with getSubtitles(), getSubtitleLanguages(), downloadSubtitle(), getLanguages(), testConnection()
  - `ProwlarrClient` — Prowlarr API client with getIndexers(), getIndexerStats(), getHealth(), triggerReindexerCheck(), testConnection()
  - Extended `config/arr.php` with bazarr and prowlarr sections
  - Unit tests: `BazarrClientTest` (9 tests), `ProwlarrClientTest` (8 tests)

### Added (Step K.1)

- Sonarr/Radarr API clients for media server integration:
  - `ArrClientInterface` — common interface for *arr clients with getQueue(), getQualityProfiles(), getTagList(), testConnection()
  - `SonarrClient` — Sonarr v3 API client with getSeries(), getSeriesById(), getEpisodeFile(), getQueue(), getWantedMissing(), getQualityProfiles(), getTagList(), addSeries(), triggerDownload(), testConnection()
  - `RadarrClient` — Radarr v3 API client with getMovies(), getMovieById(), getQueue(), getQualityProfiles(), getCustomFormats(), getTagList(), addMovie(), triggerDownload(), testConnection()
  - `ArrClientFactory` — factory for creating Sonarr/Radarr clients from config array
  - `config/arr.php` — configuration file for Sonarr/Radarr connection settings
  - Unit tests: `SonarrClientTest` (12 tests), `RadarrClientTest` (11 tests), `ArrClientFactoryTest` (10 tests)

### Added (Step J.6)

- Roku ECP support — send media to Roku devices:
  - `RokuDevice` — Roku device descriptor with deviceId, name, host, port, model, softwareVersion
  - `RokuDiscovery` — discovers Roku devices via mDNS `_ roku-ecnp._tcp.local.` using MdnsDiscovery
  - `RokuEcpClient` — HTTP ECP client with launchChannel(), playMedia(), sendKeypress(), getDeviceInfo(), getPlayerState()
  - `RokuSession` — active Roku session with playMedia()/pause()/play()/stop(), player state polling every 5 seconds via Workerman Timer
  - `RokuManager` — manages Roku sessions, discovers devices, creates sessions, launches media
  - `RemoteRokuClient` — Roku control via relay tunnel (RelayConsumer) for devices behind NAT
  - `RokuController` — HTTP API endpoints:
    - GET /api/v1/roku/devices — list discovered Roku devices
    - POST /api/v1/roku/devices/{id}/send — send media to Roku
    - POST /api/v1/roku/devices/{id}/launch/{channelId} — launch a channel
    - POST /api/v1/roku/devices/{id}/key/{keyName} — send keypress
    - GET /api/v1/roku/devices/{id}/status — get session status
  - `Application` — registered Roku routes in `loadRokuRoutes()`
  - Unit tests: `RokuDeviceTest` (4 tests), `RokuDiscoveryTest` (3 tests), `RokuEcpClientTest` (8 tests), `RokuSessionTest` (7 tests), `RokuManagerTest` (6 tests)

### Added (Step J.5)

- AirPlay 2 support — stream audio to AirPlay 2 devices (Apple TV, HomePod, AirPlay 2-compatible receivers):
  - `AirPlayDevice` — AirPlay device descriptor with deviceId, name, host, port, raopPort, model, supportsVideo
  - `AirPlayDiscovery` — discovers AirPlay devices via mDNS `_airplay._tcp.local.` and `_raop._tcp.local.` using MdnsDiscovery
  - `RaopClient` — RAOP (Real-Time Audio Protocol) client with buildAnnouncePayload(), flush(), getRtpInfo(), getLatency()
  - `AirPlaySession` — active AirPlay session with startStream()/pause()/resume()/stop() and state management
  - `AirPlayManager` — manages AirPlay sessions, discovers devices, creates/retrieves/stops sessions
  - `RemoteAirPlayClient` — AirPlay via relay tunnel (RelayConsumer) for devices behind NAT
  - `AirPlayController` — HTTP API endpoints:
    - GET /api/v1/airplay/devices — list discovered AirPlay devices
    - POST /api/v1/airplay/devices/{id}/stream — start streaming
    - POST /api/v1/airplay/devices/{id}/pause — pause playback
    - POST /api/v1/airplay/devices/{id}/resume — resume playback
    - POST /api/v1/airplay/devices/{id}/stop — stop playback
    - GET /api/v1/airplay/devices/{id}/status — get session status
  - `HlsStreamer` — added `getAirPlayStreamUrl()` for AirPlay-compatible stream URLs
  - `Application` — registered AirPlay routes in `loadAirPlayRoutes()`
  - Unit tests: `AirPlayDeviceTest` (5 tests), `AirPlayDiscoveryTest` (3 tests), `RaopClientTest` (5 tests), `AirPlaySessionTest` (5 tests), `AirPlayManagerTest` (5 tests)

### Added (Step J.4)

- Chromecast support — cast to Chromecast devices via Default Media Receiver:
  - `CastDevice` — Chromecast device descriptor with device ID, name, host, port, model, UUID
  - `CastDiscovery` — discovers Chromecast devices via mDNS `_googlecast._tcp.local.` using MdnsDiscovery
  - `CastApiClient` — HTTP/JSON Cast protocol client with connect(), launchApp(), loadMedia(), sendMediaCommand(), getMediaStatus()
  - `CastSession` — active Chromecast session with play/pause/stop/seek, position polling every 5 seconds via Workerman Timer
  - `CastManager` — manages multiple cast sessions, creates sessions, launches app, loads media
  - `RemoteCastClient` — cast via relay tunnel (RelayConsumer) for Chromecast behind NAT (in progress / not operational — depends on a hub relay-tunnel feature that does not exist yet; the client throws `RuntimeException` rather than silently faking success)
  - `ChromecastController` — HTTP API endpoints:
    - GET /api/v1/cast/devices — list discovered Chromecast devices
    - POST /api/v1/cast/devices/{id}/cast — start casting
    - POST /api/v1/cast/devices/{id}/play — resume playback
    - POST /api/v1/cast/devices/{id}/pause — pause playback
    - POST /api/v1/cast/devices/{id}/stop — stop casting
    - POST /api/v1/cast/devices/{id}/seek — seek to position (ms)
    - GET /api/v1/cast/devices/{id}/status — get session status
  - `HlsStreamer` — added `getCastStreamUrl()` for Chromecast-compatible stream URLs
  - `Application` — registered Chromecast routes in `loadChromecastRoutes()`
  - Default Media Receiver app ID: `CC1AD845`
  - Unit tests: `CastDeviceTest` (4 tests), `CastDiscoveryTest` (4 tests), `CastApiClientTest` (8 tests), `CastSessionTest` (8 tests), `CastManagerTest` (8 tests)

### Added (Step J.3)

- DLNA AVTransport "play to" — send media to DLNA renderers:
  - `RendererDiscovery` — discovers DLNA MediaRenderers via SSDP with `urn:schemas-upnp-org:device:MediaRenderer:1`
  - `RendererControlClient` — HTTP SOAP client for AVTransport control (SetAVTransportURI, Play, Pause, Stop, Seek, GetPositionInfo, GetTransportInfo)
  - `PlayToSession` — active "play to" session with position polling every 5 seconds via Workerman Timer
  - `PlayToManager` — manages multiple play-to sessions, creates RendererControlClient, maps renderer IDs to sessions
  - `RemoteRendererClient` — "play to" via relay tunnel (RelayConsumer) for renderers behind NAT
  - `RendererListController` — HTTP API endpoints:
    - GET /api/v1/dlna/renderers — list discovered renderers
    - POST /api/v1/dlna/renderers/{id}/play — start "play to" session
    - POST /api/v1/dlna/renderers/{id}/pause — pause playback
    - POST /api/v1/dlna/renderers/{id}/stop — stop playback
    - POST /api/v1/dlna/renderers/{id}/seek — seek to position (ticks)
    - GET /api/v1/dlna/renderers/{id}/status — get renderer state
  - `AvTransport` — added `onStateChange()` callbacks and `notifyStateChange()` for observable state changes
  - `PlaybackController` — added `startPlayToSession()` for integrated local + remote playback
  - `Application` — registered DLNA renderer control routes in `loadDlnaRendererRoutes()`
  - Unit tests: `RendererDiscoveryTest` (5 tests), `RendererControlClientTest` (9 tests), `PlayToSessionTest` (11 tests), `PlayToManagerTest` (8 tests)

### Added (Step J.2)

- DLNA ContentDirectory full — browse and search real media library:
  - `LibraryBridge` — bridges `ItemRepository` to `ContentDirectory` for real media data
  - `CdsControlHandler` — HTTP SOAP endpoint for ContentDirectory actions (Browse, Search)
  - `CdsServer` — full DLNA MediaServer with HTTP endpoints: `/description.xml`, `/cds/control`, `/scpd/{service}.xml`
  - `src/Server/Http/Controllers/Dlna/DeviceDescriptionController` — serves `/description.xml`
  - `src/Server/Http/Controllers/Dlna/CdsControlController` — handles CDS SOAP requests
  - `ContentDirectory` — now uses `LibraryBridge` for real library data instead of stubs
  - `DlnaServer` — requires real `ItemRepository` (no stub), supports `setLibraryBridge()`
  - Unit tests: `LibraryBridgeTest` (14 tests), `CdsControlHandlerTest` (10 tests), `CdsServerTest` (13 tests)

### Added (Step J.1)

- SSDP (Simple Service Discovery Protocol) and mDNS (multicast DNS) discovery infrastructure:
  - `SsdpSocket` — raw UDP socket wrapper for SSDP multicast `239.255.255.250:1900`
  - `SsdpDevice` — discovered SSDP device descriptor with `getDeviceId()` and `getBaseUrl()`
  - `SsdpDiscovery` — SSDP discovery service with `discoverDevices()` and `announceServer()`
  - `MdnsSocket` — raw UDP socket wrapper for mDNS multicast `224.0.0.251:5353`
  - `MdnsService` — resolved mDNS service descriptor with `getAddress()`
  - `MdnsDiscovery` — mDNS discovery service with `discoverChromecast()`, `discoverAirPlay()`, `discoverRoku()`
  - `DiscoveryManager` — unified facade combining SSDP and mDNS discovery
  - `DiscoveryServer` — Workerman Timer integration for background discovery
  - `config/discovery.php` — configuration with SSDP/mDNS settings
  - Unit tests: `SsdpSocketTest`, `SsdpDiscoveryTest`, `MdnsSocketTest`, `MdnsDiscoveryTest`, `DiscoveryManagerTest` (12+ tests)
  - `docs/developers/discovery.md` — protocol documentation

### Added (Step I.7)

- Hub relay for remote live TV streams (HLS re-streaming via hub WebSocket tunnel):
  - `HlsRelaySession` — value object for relay session with `sessionId`, `channelId`, `tuneRequestId`, `getMountUrl()`, `getVariantPlaylistUrl()`
  - `HlsRelayManager` — orchestrates relay sessions: `startRelaySession()`, `stopRelaySession()`, `getActiveSessions()`, `getUserSession()`
  - `HlsSegmentPrefetcher` — LRU cache for HLS segments with Workerman Timer-based prefetching (`startPrefetch()`, `stopPrefetch()`, `getSegment()`)
  - `HlsRelaySessionFactory` — factory for building `HlsRelayManager` from config
  - `RelayConsumer` — added `registerMount()` and `unregisterMount()` methods for dynamic path handlers; `dispatchViaMount()` routes `/relay/live/{sessionId}/*` to registered handlers
  - `migrations/015_livetv_relay_sessions.sql` — creates `livetv_relay_sessions` table
  - `config/livetv.php` — added `relay` section with `enabled`, `prefetch_segments`, `max_concurrent_sessions`, `segment_cache_ttl_seconds`, `relay_path_prefix`
  - Unit tests in `tests/Unit/LiveTv/Relay/` (HlsRelaySessionTest, HlsRelayManagerTest, HlsSegmentPrefetcherTest — 26+ tests)
  - `docs/developers/live-relay.md` — architecture docs, session lifecycle, configuration

### Added (Step I.6)

- Comskip commercial detection for live TV recordings with chapter markers:
  - `ComskipIntegration` — wires `ComskipRunner` into recording lifecycle:
    `processRecording()`, `getEdlSegments()`, `markProcessed()`
  - `ComskipLifecycleManager` — queue management with `max_concurrent` enforcement:
    `enqueue()`, `processNext()`, `getPendingCount()`
  - `ChapterMarkerService` — EDL to HLS chapter conversion:
    `toHlsChapters()`, `persistChapters()`, `getChapters()`
  - `migrations/014_livetv_commercials.sql` — adds `commercial_processed_at`,
    `commercial_edl_path`, `commercial_frame_count`, `commercial_duration_seconds`
    to `livetv_recordings`
  - `config/livetv.php` — added `comskip` section with `enabled`, `binary_path`,
    `ini_path`, `output_dir`, `queue_processing`, `max_concurrent`
  - `Recorder` — registers `ComskipLifecycleManager::enqueue()` via `onComplete()`
    callback at construction time
  - Unit tests in `tests/Unit/LiveTv/Recording/` (ComskipIntegrationTest,
    ComskipLifecycleManagerTest, ChapterMarkerServiceTest — 12+ tests)
  - `docs/developers/comskip-live.md` — integration docs, EDL format, config

### Added (Step I.5)

- Scheduled + series DVR recordings. Includes:
  - `SeriesRuleManager` — CRUD for series recording rules; `matchAndSchedule()`
    queries `GuideManager::getUpcomingBySeries()` and schedules unmatched episodes
  - `RecordingDeduplicator` — prevents duplicate recordings via 2-hour window;
    `isDuplicate()`, `getCanonical()`, `resolveDuplicates()`
  - `RecordingScheduler` — priority-based conflict resolution; `processDueRecordings()`
    runs via Workerman timer; `getNextRecording()` for display
  - `RecordingHooksRunner` — async post-recording hook enqueueing
  - `migrations/013_livetv_dvr.sql` — adds `series_rule_id`, `duplicate_group`,
    `pre/post_padding_seconds` to `livetv_recordings`; creates `livetv_series_rules` table
  - `Recorder` — updated `scheduleRecording()` accepts `pre_padding_seconds`,
    `post_padding_seconds`, `series_rule_id`; added `isDuplicate()` method;
    `startRecording()` applies pre-padding (starts recording early)
  - `config/livetv.php` — added `dvr` section with `default_pre_padding_seconds`,
    `default_post_padding_seconds`, `auto_resolution`, `storage_path`,
    `max_storage_bytes`
  - `RecordingHooks` — already wires `ComskipPostProcessor` via `onComplete()` callback
  - Unit tests in `tests/Unit/LiveTv/Recording/` (SeriesRuleManagerTest,
    RecordingDeduplicatorTest, RecordingSchedulerTest — 12+ tests)
  - `docs/developers/dvr.md` — series rules, deduplication, padding,
    conflict resolution, scheduler integration

### Added (Step I.4)

- Schedules Direct EPG integration. Includes:
  - `SdApiClient` — HTTP JSON client for SD API with token auth
    (BASE_URL: https://api.schedulesdirect.tmsglobal.com)
  - `SdLineupHandler` — fetches SD lineups, imports channels via ChannelManager
  - `SdProgramMapper` — maps SD program/schedule data to GuideManager format
  - `SdEpgService` — orchestrates full sync: fetch schedules, programs, upsert to guide
  - `SdEpgServiceFactory` — builds service from config with token caching
  - `config/livetv.php` — added `schedules_direct` section (username,
    password, token_cache_path, lineup_id, sync_hours_ahead, timeout_secs)
  - `LiveTvManager` — wired `SdEpgService` as optional dependency;
    `getSdEpgService()`, `setSdConfig()`, `syncSdEpG()`
  - Unit tests in `tests/Unit/LiveTv/Epg/SchedulesDirect/` (SdApiClientTest,
    SdProgramMapperTest, SdEpgServiceTest — 12 tests total)
  - `docs/developers/schedules-direct.md` — SD API overview, auth, endpoints,
    data model, and config reference

### Added (Step I.3)

- Linux DVB-T USB tuner driver. Includes:
  - `DvbtDevice` — immutable value object for /dev/dvb/ devices
  - `DvbtDeviceScanner` — scans /dev/dvb/ for adapters, reads capabilities
  - `DvbtSignalEngine` — dvbv5-zap integration + FFmpeg ingest URL generation
  - `DvbtTunerDriver` — implements `TunerDriverInterface`
  - `DvbtTunerDriverFactory` — builds driver from `config/livetv.php`
  - `config/livetv.php` — added `dvbt` section
  - `TunerDriverInterface` — updated to accept `DvbtDevice` union type
  - `LiveTvManager` — integrated DvbtTunerDriver via additionalDrivers
  - Unit tests for scanner, signal engine, and driver
  - `docs/developers/dvbt.md` — developer documentation

### Added (Step I.2)

- M3U/XMLTV IPTV tuner driver. Includes:
  - `M3UEntry` — immutable value object for M3U playlist entries
  - `M3UParser` — parses M3U/M3U8 playlists, fetches remote via `parseUrl()`
  - `XmlTvProgramme` — immutable value object for XMLTV programme entries
  - `XmlTvParser` — parses XMLTV format, handles YYYYMMDDHHMMSS times
  - `IptvDevice` — immutable descriptor for IPTV sources
  - `IptvTunerDriver` — implements `TunerDriverInterface` for IPTV
  - `IptvTunerDriverFactory` — builds driver from `config/livetv.php`
  - `config/livetv.php` — added `iptv` section with `sources` array
  - `LiveTvManager` — integrated IPTV alongside HDHomeRun tuners
  - `GuideManager::upsertProgram()` — added `xmltv_id` parameter for IPTV matching
  - Unit tests for `M3UParser`, `XmlTvParser`, `IptvTunerDriver`
  - `docs/developers/iptv.md` — developer documentation

### Added (Step I.1)

- HDHomeRun tuner driver (SSDP discovery + HTTP API). Includes:
  - `TunerDriverInterface` — shared interface for all tuner drivers
  - `HdHomeRunDevice` — immutable value object for discovered devices
  - `HdHomeRunDiscovery` — SSDP M-SEARCH discovery on UDP 1900
  - `HdHomeRunApiClient` — HTTP API client for HDHomeRun devices
  - `HdHomeRunTunerDriver` — concrete driver implementing `TunerDriverInterface`
  - `HdHomeRunTunerDriverFactory` — factory for driver instantiation
  - `LiveTvManager` refactored to use `TunerDriverInterface` (no more `/dev/dvb` references)
  - `config/livetv.php` — LiveTV configuration with HDHomeRun settings
  - Unit tests for `HdHomeRunDiscovery`, `HdHomeRunApiClient`, `HdHomeRunTunerDriver`
  - `docs/developers/hdhomerun.md` — developer documentation

### Added (Step H.6)

- Theme music + theme video auto-play on browse. Includes:
  - `ThemeAudio` — readonly DTO (path, url, duration, format) for audio themes
  - `ThemeVideo` — readonly DTO (path, url, duration, width, height, format) for video backdrops
  - `ThemeMedia` — readonly DTO containing libraryId, audio, video, scannedAt
  - `ThemeMediaFinder` — filesystem scanner for theme.mp3/theme.ogg and backdrop.mp4/backdrop.webm
  - `ThemeMediaRepository` — cache operations (upsert, findByLibraryId, delete)
  - `ThemeMediaController` — 3 REST endpoints:
    - `GET /api/v1/libraries/{id}/theme-media` — get theme media
    - `POST /api/v1/libraries/{id}/theme-media/scan` — trigger rescan
    - `DELETE /api/v1/libraries/{id}/theme-media` — clear cached entry
  - `ThemeMediaStreamController` — 2 streaming endpoints:
    - `GET /stream/theme-media/{libraryId}/audio` — stream theme audio
    - `GET /stream/theme-media/{libraryId}/video` — stream theme video
  - `Migration 008_theme_media.sql` — creates theme_media table
  - `Router::themeMedia()` — registers all theme media routes
  - `library-header.tpl` — theme media player partial with toggle button
  - `theme-media.js` — autoplay handling with browser policy fallback
  - `LibraryManager::scanThemeMedia()` — scans and caches after library scan
  - `PageRenderer::setThemeMediaRepository()` + `renderLibrary()` passes themeMedia to template
  - Unit tests in `tests/Unit/Theming/` (10+ tests)
  - Integration test `tests/Integration/Theming/ThemeMediaScanTest.php`
  - `docs/developers/theme-media.md` — file naming, scanning, streaming, autoplay policy

### Added (Step H.5)

- Trailers and extras with local `Trailers/` folder support. Includes:
  - `Trailer` — readonly DTO (id, mediaItemId, title, source, url, duration, quality, isLocal, filePath)
  - `Extra` — readonly DTO for non-trailer extras (featurette|behind_the_scenes|interview|clip|deleted_scene|trailer)
  - `TrailerFinder` — filesystem scanner for local trailers (same-level and Trailers/ subfolder)
  - `TrailerResolver` — merges local + TMDB trailers, caches in media_extras with 24h TTL
  - `ExtrasRepository` — data access for media_extras table
  - `ExtrasController` — 3 REST endpoints:
    - `GET /api/v1/media/{id}/extras` — full merged list
    - `GET /api/v1/media/{id}/trailers` — trailers only
    - `GET /api/v1/media/{id}/extras/other` — non-trailer extras
  - `Migration 007_media_extras.sql` — creates media_extras table
  - `TmdbProvider::getTrailers()` — fetches trailers from TMDB API
  - `Router::extras()` — registers ExtrasController routes
  - `MediaScanner::hasTrailers()` — detects Trailers/ folders at scan time
  - `FolderWatcher::shouldRescanExtras()` — triggers extras rescan on change
  - Unit tests in `tests/Unit/Media/Extras/` (15 tests)
  - Integration test `tests/Integration/Media/Extras/TrailerScannerTest.php`
  - `docs/developers/trailers-and-extras.md` — naming conventions, API reference, architecture

### Added (Step H.4)

- Trakt.tv scrobble plugin with two-way history sync. Includes:
  - `TraktApi` — OAuth2 PKCE client, scrobble start/pause/stop, history sync
  - `TraktSettings` — per-user settings (tokens, sync prefs, username)
  - `TraktPlugin` — LifecycleInterface entry, subscribes to PlaybackStarted/Stopped/ProgressUpdated
  - `TraktHistorySync` — syncTraktToPhlix() (pull on schedule) and syncPhlixToTrakt() (push on ≥90% completion)
  - `TraktOAuthController` — OAuth callback at GET /api/v1/oauth/trakt/callback
  - `config/scrobblers/trakt.php` — client_id, client_secret, redirect_uri, sync_interval
  - `phlix-plugin-trakt/plugin.json` — scrobbler plugin manifest
  - Unit tests (19 tests across TraktApi, TraktSettings, TraktHistorySync, TraktPlugin)
  - `docs/developers/scrobbler-plugins.md` — scrobbler plugin author guide
- New Router method `traktAuth()` for Trakt OAuth routes

### Added (Step H.3)

- Custom CSS / themes with `ui-theme` plugin type. Includes:
  - `Theme` — readonly theme descriptor (id, name, type, cssUrl, jsUrl,
    thumbnailUrl, version, pluginName, dark).
  - `ThemeRegistry` — central registry with registerBuiltIn(), registerFromPlugin(),
    getTheme(), getAllThemes(), getActiveThemeForUser(), setActiveThemeForUser().
  - `ThemeMiddleware` — HTTP middleware that injects theme CSS/JS into WebPortal
    responses via str_replace on Smarty placeholders.
  - `ThemePluginInterface` — marker interface for ui-theme plugin entry classes.
  - `ThemePreviewController` — renders live theme preview in iframe sandbox at
    GET /portal/theme-preview?id={themeId}.
  - `config/themes.php` — 4 built-in themes (phlix-dark, phlix-light,
    phlix-amoled, phlix-contrast) with CSS and thumbnail assets.
  - Migration `migrations/006_user_theme_settings.sql` — adds active_theme_id
    to user_profiles.
  - UserProfileManager::getActiveThemeId() / setActiveThemeId() for per-profile
    theme preferences.
  - `{$theme_css|raw}` and `{$theme_js|raw}` Smarty placeholders in base.tpl.
  - `var/themes/` runtime directory for extracted plugin themes (gitignored).
  - Unit tests in `tests/Unit/Theming/` (ThemeRegistryTest, ThemeMiddlewareTest — 11 tests).
  - `docs/developers/ui-themes.md` — plugin author guide with CSS variable reference.

### Added (Step H.2)

- Collections — named groups of media items for manual curation
  (bulk-add from search) and rule-based auto-population via smart playlists.
  Includes:
  - `Collection` — readonly entity with id, name, libraryId, smartPlaylistId,
    parentId, sortOrder, timestamps.
  - `CollectionWithItems` — hydrated DTO with collection + hydrated media items.
  - `CollectionRepository` — full CRUD for collections table with parameterized
    Workerman\MySQL\Connection queries.
  - `CollectionItemRepository` — membership CRUD for collection_items with
    sort order support.
  - `CollectionManager` — orchestrator with addItem(), removeItem(),
    bulkAddFromSearch(), getCollectionWithItems(), refreshSmartCollection().
  - `CollectionController` — 9 REST API endpoints:
    GET/POST /api/v1/collections, GET/PUT/DELETE /api/v1/collections/{id},
    POST/DELETE /api/v1/collections/{id}/items/{mediaItemId},
    POST /api/v1/collections/{id}/bulk-add,
    POST /api/v1/collections/{id}/refresh,
    GET /api/v1/libraries/{libraryId}/collections.
  - Migration `migrations/005_collections.sql` — creates collections and
    collection_items tables with proper indexes.
  - Unit tests in `tests/Unit/Collections/` (CollectionRepositoryTest,
    CollectionItemRepositoryTest, CollectionManagerTest — 14 tests).
  - Integration test `tests/Integration/Collections/CollectionCrudTest.php`.
  - `docs/developers/collections.md` — model, API reference, smart sync
    algorithm, integration guide.
  - `Router::collections()` — registers collection routes.
  - `SmartPlaylistRefreshHandler` now calls CollectionManager::refreshSmartCollection()
    for any collection linked to a changed smart playlist.

### Added (Step H.1)

- Smart-playlist rule engine with JSON DSL evaluation at scan time and
  on folder-watch events. Includes:
  - `RuleNode` — immutable AST node (TYPE_AND/OR/NOT/RULE) for rule trees.
  - `RuleOperators` — 11 static operator methods (equals, notEquals, contains,
    notContains, greaterThan, lessThan, between, in, notIn, startsWith, endsWith).
  - `SmartPlaylistEngine` — buildFromDsl(), evaluate(), evaluateOnScan(), toJson()
    for parsing JSON DSL and evaluating media items against rules.
  - `SmartPlaylist` — readonly entity with id, name, libraryId, rulesJson, limit,
    sortBy, sortDesc, timestamps.
  - `SmartPlaylistRepository` — full CRUD for smart_playlists table with
    parameterized Workerman\MySQL\Connection queries.
  - `SmartPlaylistRefreshHandler` — listens to LibraryUpdated events and
    re-evaluates all smart playlists for the changed library.
  - `SmartPlaylistController` — REST API endpoints:
    GET/POST/PUT/DELETE /api/v1/smart-playlists, POST /api/v1/smart-playlists/{id}/preview.
  - `LibraryUpdated` event dispatched by FolderWatcher on content changes.
  - Migration `migrations/004_smart_playlists.sql` — creates smart_playlists table
    with JSON rules column, limit, sort_by, sort_desc fields.
  - Unit tests in `tests/Unit/Playlists/` (RuleNodeTest, RuleOperatorsTest,
    SmartPlaylistEngineTest, SmartPlaylistRepositoryTest, SmartPlaylistTest).
  - Integration test `tests/Integration/Playlists/SmartPlaylistRefreshTest.php`.
  - `docs/developers/smart-playlists.md` — DSL reference, operator list,
    evaluation algorithm, extension guide.
  - `Router::smartPlaylists()` — registers smart playlist routes.
  - `FolderWatcher` now injects EventDispatcherInterface and dispatches
    LibraryUpdated events when changes are detected.
  - MediaServicesProvider registers SmartPlaylistEngine, SmartPlaylistRepository,
    SmartPlaylistRefreshHandler, SmartPlaylistController.

### Added (Step G.6)

- `AudiobookProgress` — Value object for per-user audiobook progress tracking.
  Immutable with position_ms, current_chapter_index, completed_chapters array,
  percent_complete, and last_position_ms for chapter-resume support.
- `AudiobookProgressStore` — Persistence layer using Workerman MySQL for
  audiobook_progress table. Supports getProgress(), saveProgress(), and
  markChapterComplete() operations with composite PK (user_id, audiobook_id).
- `AudiobookScanner` — Extends BookScanner for audiobook-specific scanning.
  - `harvestChapters()` — Pure-PHP M4B chapter extraction via MP4 chpl atom
    parsing (binary string scanning, no external dependencies). Handles 64-bit
    duration values.
  - Returns chapters as metadata_json array with title, start_ms, end_ms,
    and duration_ms fields.
- `AudiobookLibraryManager` — Extends BookLibraryManager for audiobook
  libraries. Orchestrates scanning and progress management. Methods:
  getProgress(), saveProgress(), markChapterComplete(), chapterDuration().
- `AudiobookController` — REST API endpoints for audiobooks:
  - `GET /api/v1/audiobooks` — List audiobooks with pagination
  - `GET /api/v1/audiobooks/{id}` — Get audiobook details with chapters
  - `GET /api/v1/audiobooks/{id}/chapters` — List chapters for an audiobook
  - `GET /api/v1/audiobooks/{id}/progress` — Get user's progress for an audiobook
  - `POST /api/v1/audiobooks/{id}/progress` — Save progress (position, chapter)
  - `GET /api/v1/audiobooks/{id}/stream` — Stream audiobook (chapter + offset)
- `AudiobookLibraryType` — Library type plugin with type `'audiobook'`.
  Returns AudiobookScanner and AudiobookLibraryManager instances.
- Migration `012_audiobook_progress.sql` — Creates audiobook_progress table
  with user_id, audiobook_id, position_ms, current_chapter_index,
  completed_chapters (JSON), percent_complete, last_position_ms, created_at,
  updated_at.
- Smarty templates: `audiobooks/audiobooks.tpl`, `audiobooks/audiobook.tpl`,
  `player/player.tpl`, `audiobooks/partials/audiobook_card.tpl`,
  `audiobooks/partials/chapter_row.tpl` — Audiobook grid view, detail with
  chapter navigation, audio player UI, and chapter list component.
- `public/assets/css/audiobooks.css` — Player styles (play/pause, seek bar,
  volume, chapter list) and grid layout with cover cards.
- `public/assets/js/audiobook-player.js` — Chapter navigation, progress
  persistence every 10 seconds, chapter completion tracking, play/pause controls.
- `docs/libraries/audiobooks.md` — Documentation for supported formats (M4B,
  M4A, MP3), chapter navigation, progress persistence, and streaming.
- Unit tests: AudiobookScannerTest (8 tests), AudiobookProgressStoreTest
  (4 tests), AudiobookLibraryManagerTest (4 tests), AudiobookControllerTest
  (9 tests).
- Router now registers `/api/v1/audiobooks/*` routes.
- LibraryManager routes `'audiobook'` type libraries through AudiobookScanner.

### Added (Step G.5)

- `BookScanner` — Pure-PHP book file scanner for EPUB, PDF, and CBZ formats.
  - `harvestEpub()` — parses EPUB container.xml and content.opf for Dublin Core
    metadata (title, author, publisher, ISBN, language, pub_date, description) and
    extracts cover images.
  - `harvestPdf()` — uses `exif_read_data()` for XMP/EXIF metadata and pure-PHP
    page count extraction.
  - `harvestCbz()` — parses ComicInfo.xml for extended metadata (series, volume,
    authors, page_count) and extracts cover images from ZIP archive.
  - `scanBookLibrary()` — generator that yields book item arrays with metadata.
- `BookLibraryManager` — orchestrates book library scanning, metadata extraction,
  and upsert. Implements `rescanLibrary()` for full pipeline and `upsertBook()`
  for single-file processing.
- `BookLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'book'`. Returns `BookScanner` and `BookLibraryManager` instances.
- `OpdsFeedBuilder` — builds OPDS 1.2 compliant XML feeds using `DOMDocument`.
  - `buildRootFeed()` — root catalog with links to libraries.
  - `buildNavigationFeed()` — navigation feed listing book libraries.
  - `buildAcquisitionFeed()` — acquisition feed with pagination (?offset=N&limit=N).
  - `buildEntry()` — individual book entries with dc:title, dc:creator,
    opds:link rel=acquisition.
- `BookController` — REST API endpoints for books and OPDS:
  - OPDS: `GET /opds/v1.2`, `GET /opds/v1.2/libraries`, `GET /opds/v1.2/libraries/{id}`
  - Web portal: `GET /books`, `GET /books/{id}`, `GET /books/{id}/cover`,
    `GET /books/{id}/read`, `GET /books/{id}/download`
- Smarty templates: `books/books.tpl`, `books/book.tpl`, `books/reader.tpl`,
  `books/partials/book_card.tpl` — book grid view, book detail with cover
  and metadata, minimal reader stub, book card component.
- `public/assets/css/books.css` — styles for book grid, cover cards,
  reader layout, and theme support (light/sepia/dark).
- `public/assets/js/reader.js` — reader controller with font size controls,
  theme switching, keyboard navigation (←/→).
- `docs/libraries/books.md` — documentation for supported formats, OPDS feed URL,
  third-party client setup (Uboiquity, Komga, Kore, Moon+ Reader), naming
  conventions, metadata fields, reader stub limitations.
- `docs/reference/api.md` — updated with OPDS endpoints and Books API.
- Unit tests: `BookScannerTest` (8 tests), `BookLibraryManagerTest` (2 tests),
  `OpdsFeedBuilderTest` (5 tests), `BookControllerTest` (7 tests).
- Router now registers `/opds/*` and `/books/*` routes.
- LibraryManager routes `'book'` type libraries through BookScanner.
- WebPortalRouter now registers `/books` and `/books/{id}` routes.
- `public/templates/partials/header.tpl` — Added Books nav link.
- LibraryController accepts `'book'` as a valid library type.

### Added (Step G.4)

- `PhotoScanner` — Pure-PHP photo file scanner with EXIF metadata extraction.
  Uses PHP's built-in `exif_read_data()` for JPEG files; graceful fallback
  for PNG/TIFF/WebP/HEIC. Extracts camera_make, camera_model, lens,
  aperture, iso, shutter_speed, focal_length, width, height, orientation,
  date_taken_unix, gps_lat, gps_lng, gps_alt.
- `PhotoLibraryManager` — Orchestrates photo library scanning, EXIF extraction,
  and metadata upsert. Implements `rescanLibrary()` for full pipeline and
  `upsertPhoto()` for single-file processing.
- `PhotoLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'photo'`. Returns `PhotoScanner` and `PhotoLibraryManager` instances.
- `ExifProvider` — Local EXIF metadata provider that reads from `metadata_json`
  stored on media items. Implements `MetadataProviderInterface`.
- `PhotoController` — REST API endpoints for photo browsing and slideshow:
  - `GET /photo/albums` — list all albums (grouped by date)
  - `GET /photo/albums/{id}` — get specific album with photos
  - `GET /photo/photos` — list all photos
  - `GET /photo/photos/{id}` — photo with full EXIF data
  - `GET /photo/photos/{id}/thumbnail?w=300&h=300&fit=cover` — resized thumbnail
  - `GET /photo/photos/{id}/full` — full-resolution photo
  - `GET /photo/slideshow?album_id=xxx&interval=5` — slideshow data
- Smarty templates: `photo/albums.tpl`, `photo/album.tpl`, `photo/photo.tpl`,
  `photo/slideshow.tpl`, `photo/partials/exif_panel.tpl`,
  `photo/partials/photo_card.tpl` — album grid, photo grid, lightbox view,
  fullscreen slideshow player, EXIF data sidebar.
- `public/assets/css/photo.css` — Styles for album grid, photo grid,
  lightbox, EXIF sidebar, slideshow player.
- `public/assets/js/slideshow.js` — Slideshow controller with auto-advance
  interval, keyboard nav (←/→/Space/Escape), touch/swipe support.
- `docs/libraries/photos.md` — Documentation for supported formats, EXIF
  fields, album organization, API endpoints, thumbnail generation,
  slideshow features, and deferred geotag clustering note.
- Unit tests: `PhotoScannerTest` (12 tests), `PhotoLibraryManagerTest`
  (6 tests), `PhotoControllerTest` (11 tests).
- Router now registers `/photo/*` routes pointing to `PhotoController`.
- LibraryManager routes `'photo'` type libraries through `PhotoLibraryManager`.
- `public/templates/layouts/main.tpl` — Added Photos nav link.

### Added (Step G.3)

- `Phlix\Plugins\Lastfm\Plugin` — In-core Last.fm scrobbler plugin
  implementing the `scrobbler` plugin type. Subscribes to
  `phlix.playback.started` (Now Playing updates) and
  `phlix.playback.stopped` (scrobble submission). Off by default;
  configure `config/lastfm.php` with API credentials to enable.
- `Phlix\Plugins\Lastfm\LastfmApiClient` — Last.fm API v1.2 client
  with HMAC-MD5 signing. Supports `auth.getMobileSession`,
  `track.scrobble`, and `track.updateNowPlaying` endpoints.
- `Phlix\Plugins\Lastfm\ScrobbleData` — Immutable value object for
  scrobble submission (artist, track, timestamp, album, duration,
  MusicBrainz ID).
- `Phlix\Plugins\Lastfm\NowPlayingData` — Immutable value object for
  Now Playing notifications.
- `Phlix\Plugins\Lastfm\LastfmPluginNotConfiguredException` — Thrown
  when API key, secret, or session key is missing.
- `Phlix\Plugins\Lastfm\LastfmScrobbleFailedException` — Thrown when
  Last.fm API returns an error on scrobble/Now Playing.
- `config/lastfm.php` — Default configuration with `enabled` (default
  false), `api_key`, `api_secret`, `session_key`, `username`,
  `submit_now_playing` (default true), and `scrobble_threshold`
  (default 0.5 — scrobble after 50% of track).
- `docs/plugins/developer-guide.md` — Added §14 documenting the
  `scrobbler` plugin type with Last.fm as the reference example.
- `docs/developers/lastfm-plugin.md` — New developer guide covering
  Last.fm API protocol, HMAC-MD5 signing, mobile auth flow,
  scrobble threshold semantics, and full configuration reference.
- Unit tests: `LastfmApiClientTest` (11 tests), `PluginTest` (9 tests).

### Added (Step G.2)

- `AudioScanner` — Pure-PHP audio file scanner with ID3v2 (MP3), Vorbis
  Comment (FLAC/OGG), and MP4 atom (M4A/AAC) tag harvesting. No external
  dependencies required. Never throws; returns partial results on best
  effort.
- `MusicLibraryManager` — Orchestrates music library scanning, tag harvest,
  and metadata enrichment via `MetadataManager`. Implements `rescanLibrary()` for
  full pipeline and `upsertTrack()` for single-file processing.
- `MusicLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'music'`. Returns `AudioScanner` and `MusicLibraryManager` instances.
- `LibraryTypeInterface` — New interface for library type plugins, allowing
  type-specific scanner and manager instances.
- `MusicController` — REST API endpoints for music browsing:
  - `GET /music/artists` — list all artists
  - `GET /music/artists/{mbid}` — artist detail with albums
  - `GET /music/albums` — list all albums
  - `GET /music/albums/{mbid}` — album detail with tracks
  - `GET /music/tracks` — list all tracks (paginated)
  - `GET /music/tracks/{id}` — single track
  - `GET /music/now-playing` — current playback state
- `Router::music()` — Registers `/music/*` routes pointing to `MusicController`.
- `WebPortalRouter` — Added `/music`, `/music/artists`, `/music/albums`,
  `/music/tracks`, `/music/player` web portal routes.
- Smarty templates — `music/artists.tpl`, `music/artist.tpl`,
  `music/albums.tpl`, `music/album.tpl`, `music/tracks.tpl`,
  `music/player.tpl`, `music/partials/music_card.tpl`.
- `public/assets/css/music.css` — Styles for artist grid, album grid,
  track list, and player bar.
- `public/assets/js/music-player.js` — Music player JavaScript with play,
  pause, seek, next/prev, shuffle, repeat, and queue management.
- `migrations/011_music_library.sql` — Adds 'track' to media_items type enum,
  adds indexes for library_type, artist, album, and genre queries.
- `docs/libraries/music.md` — Developer documentation covering supported
  formats, tag field mapping, naming conventions, scan behavior, and API.
- Unit tests: `AudioScannerTest` (8 tests), `MusicLibraryManagerTest` (8 tests),
  `MusicControllerTest` (13 tests).

### Added (Step G.1)

- `MusicBrainzProvider` — MusicBrainz API v2 metadata provider implementing
  `MetadataProviderInterface`. Supports artist, album, and track search and
  detail retrieval with MusicBrainz-required User-Agent headers and 1 req/sec
  rate limiting via `MusicMetadataProviderTrait`.
- `AudioDbProvider` — AudioDB API v1 metadata provider implementing
  `MetadataProviderInterface`. Supports artist, album, and track search and
  detail retrieval. Degrades gracefully when no API key is configured.
- `MusicMetadataProviderTrait` — shared trait for music providers with
  `rateLimit()` for enforcing request delays and `mbHeaders()` for
  MusicBrainz-required headers.
- `MetadataProviderInterface` — added `MEDIA_TYPE_ALBUM`, `MEDIA_TYPE_ARTIST`,
  `MEDIA_TYPE_TRACK` constants and `getSourceName()` method.
- `MetadataHttpClient` — extended `get()` method to accept optional `$headers`
  parameter for custom request headers.
- `MetadataManager` — updated provider priority to include `audiodb` as fallback
  for music types; added `track` media type support.
- `config/music_providers.php` — new config file with MusicBrainz and AudioDB
  provider settings (rate limits, user-agent, API key, fallback behavior).
- `docs/developers/music-providers.md` — developer documentation covering
  provider architecture, configuration keys, MusicBrainz rate-limit requirements,
  and guide for adding third-party providers.
- Unit tests: `MusicBrainzProviderTest` (10 tests), `AudioDbProviderTest`
  (11 tests) with ≥85% coverage on both providers.

### Added (Step F.5)

- `ComskipRunner` — detects and runs the comskip binary on Live TV recordings;
  `isAvailable()` checks if the binary exists and is executable, `run()` executes
  comskip with a 5-minute timeout and returns the path to the generated .edl file.
- `ComskipEdlParser` — parses comskip EDL (Edit Decision List) files with 3-column
  tab-separated format (start_seconds, end_seconds, scene_type); filters segments
  shorter than `min_commercial_length`; converts to `ChapterMarker[]` DTOs.
- `ComskipPostProcessor` — orchestrator that runs comskip after a recording
  completes, parses the EDL, and stores chapters via `MarkerService::storeChapters()`.
  Idempotent — skips recordings that already have chapters.
- `RecordingHooks::register()` — wires `ComskipPostProcessor` into the `Recorder`
  via the new `onComplete()` callback hook.
- `Recorder::onComplete()` — registers callbacks to fire after a recording stops
  with status COMPLETED; callbacks receive `(string $mediaItemId, string $recordingPath)`.
- `MarkerService::storeChapters()` — persists `ChapterMarker[]` arrays to
  `chapters_json` column via `ItemRepository::updateMarkers()`.
- `config/comskip.php` — comskip binary path, `min_commercial_length` (30s),
  `require_confidence` (0.7), `post_process_immediately` flag, and `edl_output_dir`.
- `docs/advanced/live-tv-comskip.md` — user-facing documentation covering
  comskip installation, configuration, EDL format, and troubleshooting.
- Unit tests: `ComskipRunnerTest` (6 tests), `ComskipEdlParserTest` (12 tests),
  `ComskipPostProcessorTest` (6 tests).

### Added (Step F.4)

- `SkipButtonSpec` — immutable value object with `toArray()` serialization and
  `fromMarkerSet()` factory for client-facing JSON.
- `PlaybackMarkerService` — provides `getFullSpec()` and `getSkipSpec(id, position_ticks)`
  to return position-aware skip button specs.
- `WebPortalRouter::getPlaybackInfo()` — embeds `markers` key with
  `skip_intro_start`, `skip_intro_end`, `skip_outro_start`, `skip_outro_end`
  in the playback info response.
- `docs/reference/skip-button-protocol.md` — full protocol specification for
  client teams implementing skip button UI.
- `docs/clients/skip-button-integration-brief.md` — concise hand-off brief
  for Phase M client integration.
- `docs/reference/api.md` — updated with `GET /api/v1/media/{id}/playback`
  endpoint documentation including `markers` key.
- Unit tests: `SkipButtonSpecTest` (4 tests), `PlaybackMarkerServiceTest` (4 tests).

### Added (Step F.3)

- Marker storage columns and GET API for chapters, intro, and outro markers.
- `migrations/003_marker_columns.sql` — adds `intro_start_seconds`,
  `intro_end_seconds`, `outro_start_seconds`, `outro_end_seconds`,
  `chapters_json` columns to `media_items` table.
- `IntroMarker` / `OutroMarker` / `ChapterMarker` — immutable DTOs for marker
  segments with start/end times, confidence, and optional title.
- `MarkerSet` — aggregate DTO containing intro, outro, and chapters array with
  `hasMarkers()` and `toArray()` methods.
- `MarkerService` — service for reading/promoting markers; reads formal columns
  first, falls back to `metadata_json` candidates; exposes `getMarkers()`,
  `promoteCandidates()`, `promoteShowMarkers()`, and `getShowMarkers()`.
- `MarkerController` — HTTP controller with 4 GET endpoints:
  - `GET /api/v1/media/{id}/markers` — all markers for an item
  - `GET /api/v1/media/{id}/markers/intro` — intro marker only
  - `GET /api/v1/media/{id}/markers/outro` — outro marker only
  - `GET /api/v1/shows/{id}/markers/bulk` — all episode markers for a show
- `Router::markers()` — registers the 4 marker routes.
- `ItemRepository` — added `getIntroMarker()`, `getOutroMarker()`,
  `getChapters()`, and `updateMarkers()` methods for marker column access.
- `docs/reference/api.md` — API reference documentation for marker endpoints.
- Unit tests: `MarkerSetTest` (10 tests), `MarkerServiceTest` (9 tests),
  `MarkerControllerTest` (10 tests).

### Added (Step F.2)

- Intro/outro detection background job system using audio fingerprint clustering.
- `FingerprintClusterer` — Jaccard similarity-based clustering to detect shared
  intro/outro segments across episodes using audio fingerprints.
- `IntroDetectionJob` — orchestrates detection for all episodes of a TV show,
  clusters fingerprints, returns marker candidates.
- `IntroMarkerCandidate` / `OutroMarkerCandidate` — immutable DTOs for detected
  intro/outro segments with start/end times, fingerprint, and confidence score.
- `IntroDetectionResult` — result container for show-level detection results.
- `ClusteringResult` — result container for fingerprint clustering output.
- `StoredMarkers` — parses stored marker candidates from episode metadata.
- `MarkerCandidateRepository` — persists intro/outro candidates to
  `media_items.metadata_json` for consumption by F.3 API.
- `MarkerCandidateStore` — file-based job queue (`/tmp/phlix_marker_jobs/`)
  with one lock file per show being processed.
- `BackgroundDetectorWorker` — queue consumer loop that processes detection
  jobs continuously.
- `scripts/run-marker-detection-worker.php` — CLI entry point for running
  the background worker.
- `config/marker_detection.php` — configuration for intro/max duration,
  similarity threshold (0.85), minimum episodes (3), worker interval.
- `docs/developers/intro-outro-detection.md` — developer documentation
  covering the clustering algorithm, configuration, and usage.
- Unit tests: `IntroDetectionJobTest` (5 tests), `FingerprintClustererTest`
  (12 tests), `MarkerCandidateStoreTest` (10 tests),
  `MarkerCandidateRepositoryTest` (5 tests).

### Added (Step E.6)

- Subtitle burn-in (hardsubbing) pipeline for embedding subtitles directly
  in the video stream — required for players/devices that don't support
  external subtitle tracks (many smart TVs, game consoles, some mobile browsers).
- `SubtitleFormat` — enum with SRT, ASS, SSA, VTT, HDMV formats plus
  `getFfmpegFormat()` and `supportsFontstyle()` methods.
- `SubtitleTrack` — immutable value object with stream index, language code,
  display label, format, and file path.
- `SubtitleStyleOptions` — value object for burn-in styling (font, size,
  primary/outline colors, outline thickness, position, margin) with
  `toAssStyle()` and `toSrtStyle()` methods.
- `SubtitleBurner` — core class for subtitle stream detection, extraction,
  and FFmpeg filter graph generation for burn-in across all vendors.
- `SubtitleBurnerFactory` — factory for creating vendor-specific burners.
- `HwaccelCommandBuilder` — added `setSubtitleTrack()`, `setSubtitleStyle()`,
  and `setSubtitleBurner()` methods; integrates subtitle burn-in filter
  args into hardware transcoding commands.
- `StreamManager` — added `setSubtitleBurnIn()` and `getSubtitleBurnInConfig()`
  methods for configuring subtitle burn-in per streaming session.
- `StreamState` — added `subtitleBurnInIndex` and `forceSubtitleBurnIn` properties.
- `config/subtitles.php` — subtitle configuration with `enabled`, `default_language`,
  `burn_in_by_default`, `extract_to_dir`, and `style` options.
- `config/ffmpeg.php` — added `subtitles` key referencing `config/subtitles.php`.
- `docs/developers/subtitle-processing.md` — developer documentation covering
  soft vs. hard subtitling, vendor burn-in support matrix, styling reference,
  and usage examples.
- Unit tests: `SubtitleFormatTest` (11 tests), `SubtitleTrackTest` (4 tests),
  `SubtitleStyleOptionsTest` (6 tests), `SubtitleBurnerTest` (13 tests).

### Added (Step E.5)

- Trickplay (thumbnail seek / scrub preview) support for video progress bar
  hover preview using DASH-IF / HLS spec-compliant "BIF" (Bitmap Image Format)
  thumbnail grids.
- `TrickplayConfig` — value object with grid dimensions (8×4), thumbnail size
  (160×90px), interval (10s), image format (JPEG/PNG), and quality settings.
- `TrickplayResult` — result container with job ID, interval, grid dimensions,
  image file metadata (byte offsets for byte-range requests), and BIF index XML
  path.
- `TrickplayGenerator` — extracts frames at fixed intervals using FFmpeg batch
  extraction (`generateThumbnailBatch`), assembles frames into grid images via
  FFmpeg `tile` filter, generates BIF index XML with offset/length per thumbnail.
- `TrickplayController` — HTTP handler serving thumbnail grid images and BIF
  index XML with correct `Content-Type` headers.
- `StreamManager` — added `setTrickplay()` and `generateTrickplay()` methods,
  `TrickplayGenerator` and `TrickplayController` properties, and
  `getTrickplayController()` getter.
- `FfmpegRunner` — extended `generateThumbnail()` to accept `int|array` for
  batch extraction, added `generateThumbnailBatch()` for multiple timestamps in
  one command, added `getFfmpegPath()` accessor.
- `Router` — added `trickplay()` route registration for
  `GET /trickplay/{jobId}/thumb-{index}.jpg` and `GET /trickplay/{jobId}/index.xml`.
- `config/trickplay.php` — trickplay configuration with `enabled`, `interval_seconds`,
  `grid_columns`, `grid_rows`, `thumb_width`, `thumb_height`, `image_format`,
  `jpeg_quality`, `storage_dir`.
- `docs/developers/streaming-protocols.md` — added "Trickplay / Thumbnail Seek"
  section documenting BIF format, generation pipeline, configuration, and
  client-side usage.
- Unit tests: `TrickplayConfigTest` (15 tests), `TrickplayResultTest` (9 tests),
  `TrickplayGeneratorTest` (8 tests), `TrickplayControllerTest` (10 tests).

### Added (Step E.4)

- DASH (Dynamic Adaptive Streaming over HTTP) streaming support alongside
  existing HLS implementation.
- `DashStreamer` — DASH manifest generator and segment manager producing
  DASH-IF compliant MPD manifests with SegmentTemplate elements.
- `SegmentTemplate` — value object for DASH segment template handling
  (SegmentTemplate vs. SegmentList for efficient live streaming).
- `AdaptationSet` — value object representing DASH adaptation sets
  (video, audio, text) with codec/bandwidth metadata.
- `DashController` — HTTP endpoints for DASH streaming:
  `GET /dash/{jobId}/manifest.mpd`, `GET /dash/{jobId}/{setId}/manifest.mpd`,
  `GET /dash/{jobId}/{setId}/segment_{n}.m4s`.
- `config/dash.php` — DASH-specific configuration with `enabled`,
  `manifest_refresh_seconds`, `min_buffer_time`, `min_buffer_time_live`,
  `time_shift_buffer_depth`, `default_codecs`.
- `config/ffmpeg.php` — added `dash` key with `enabled`, `segment_dir`,
  `default_codecs`.
- `HlsStreamer` — added `setSegmentContent()` method so segment writer
  can store once and both HLS and DASH streamers reference the same files.
- `StreamManager` — added `DashStreamer` property and `getManifestUrl()`
  method returning HLS or DASH manifest URL based on `$protocol` parameter.
- `Router` — added `dashStreaming()` route registration method.
- `docs/developers/streaming-protocols.md` — documentation covering HLS
  vs. DASH tradeoffs, manifest structure, client-side selection, and usage.
- Unit tests: `DashStreamerTest` (11 tests), `SegmentTemplateTest` (7 tests),
  `AdaptationSetTest` (8 tests).

### Added (Step E.1)

- Hardware acceleration probe system for detecting GPU encoders (NVENC,
  VAAPI, QSV, VideoToolbox, AMF, V4L2) at startup.
- `HwaccelCapability` — immutable value object representing hardware
  encoder capabilities (vendor, encoder/decoder names, supported codecs,
  HDR tone mapping support, resolution/bitrate limits).
- `HwaccelProbe` — runs vendor-specific probes via `ffmpeg -encoders`
  and `ffmpeg -decoders`, aggregates results into a capability map.
- `HwaccelRegistry` — lazy singleton holding probed capabilities;
  `getEncoder()` / `getDecoder()` use vendor priority for best-match
  selection.
- `VendorProbeInterface` + 7 concrete implementations:
  `NvencProbe`, `VaapiProbe`, `QsvProbe`, `VideoToolboxProbe`,
  `AmfProbe`, `V4L2Probe`, `SoftwareProbe` (always-available fallback).
- `config/hwaccel.php` — `enabled`, `prefer_hardware`,
  `vendor_priority`, `probe_timeout`, `test_clip_path`,
  `fallback_to_software` configuration.
- `config/ffmpeg.php` — added `hwaccel` key with `enabled`,
  `prefer_hardware`, `vendor_priority`.
- `FfmpegRunner` — added `HwaccelRegistry` property and
  `probeHardwareAcceleration()` + `buildHwaccelCommand()` methods.
- `docs/developers/hardware-acceleration.md` — architecture overview,
  capability fields, usage examples, and guide for adding new vendors.
- Unit tests: `HwaccelCapabilityTest` (6 tests),
  `HwaccelProbeTest` (9 tests), `HwaccelRegistryTest` (8 tests).
- No user-visible behavior change yet — transcode remains software-only
  until Step E.2 integrates hardware encoding into TranscodeManager.

### Added (Step D.5)

- Hub-side invite-link sharing (D.5). Invite links are generated on
  the hub and grant library access to recipients. Server-side is unchanged;
  library shares are synced via the existing hub heartbeat mechanism.

### Added (Step D.4)

- First-class passkey / WebAuthn support for passwordless login.
  Supports platform authenticators (Touch ID, Windows Hello, Face ID)
  and roaming FIDO2 tokens (YubiKey, etc.).
- `src/Auth/WebAuthn/WebAuthnManager` — orchestrates registration and
  authentication ceremonies; generates cryptographically random
  challenges; validates attestation and assertions.
- `src/Auth/WebAuthn/WebAuthnCredential` — entity for stored credentials
  with VARBINARY credential ID, sign counter, and device metadata.
- `src/Auth/WebAuthn/WebAuthnSettings` — RP configuration (ID, name,
  origin, attestation requirement).
- `src/Auth/WebAuthn/WebAuthnCredentialRepository` — data access for
  `webauthn_credentials` table; implements replay attack detection via
  sign counter validation.
- `src/Auth/WebAuthnProvider` — implements `ProviderInterface` for
  WebAuthn as an auth provider alongside OIDC/LDAP.
- `src/Server/Http/Controllers/WebAuthnController` — HTTP API with
  6 endpoints for registration, authentication, and credential
  management.
- Database migration `migrations/010_webauthn_credentials.sql` —
  creates `webauthn_credentials` table with VARBINARY credential_id
  and foreign key to users.
- Smarty template `public/templates/auth/webauthn-settings.tpl` —
  user-facing passkey management UI.
- Routes wired in `Application::loadApiRoutes()`:
  `POST /api/v1/auth/webauthn/register/options`,
  `POST /api/v1/auth/webauthn/register/verify`,
  `POST /api/v1/auth/webauthn/login/options`,
  `POST /api/v1/auth/webauthn/login/verify`,
  `GET /api/v1/me/webauthn/credentials`,
  `DELETE /api/v1/me/webauthn/credentials/{id}`.
- Composer dependency added: `web-auth/webauthn-lib: ^4.0`.
- Unit tests in `tests/Unit/Auth/WebAuthn/`: `WebAuthnManagerTest`,
  `WebAuthnCredentialTest`, `WebAuthnControllerTest`,
  `WebAuthnProviderTest`.
- Documentation:
  - `docs/plugins/auth-providers.md` — passkeys section added.
  - `docs/reference/api/auth-webauthn.md` — new API endpoint reference.
  - `docs/security/passkeys.md` — user-facing passkey guide.

### Added (Step D.3)

- `phlix-plugin-ldap` — LDAP authentication provider plugin.
  Supports OpenLDAP and Active Directory via the LDAP protocol.
  Includes:
  - `LdapProvider` — implements `ProviderInterface` with bind
    authentication and user attribute mapping.
  - `LdapConnection` — wraps `directorytree/ldaprecord` Connection
    with request-scoped caching per host:port:ssl triple.
  - `UserMapper` — maps LDAP attributes to Phlix user fields
    (uid/sAMAccountName → username, mail → email, displayname/cn →
    display name, jpegPhoto/thumbnailPhoto → avatar_url).
  - `LdapUserInfo` — LDAP-specific user info carrier.
  - `LdapAdminController` — admin API for LDAP settings management
    and test-connection action.
  - Smarty settings form at `templates/ldap-settings.tpl`.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/test`,
  `GET /api/v1/admin/auth-providers/ldap/schema`.
- Composer dependency added: `directorytree/ldaprecord: ^3.0`.

### Added (Step D.2)

- `phlix-plugin-oidc` — OIDC/OAuth2 authentication provider plugin.
  Supports any OIDC-compliant identity provider (Authelia, Authentik,
  Keycloak, Google, GitHub). Includes:
  - `OidcProvider` — implements `ProviderInterface` with authorization
    code flow and direct API token authentication.
  - `DiscoveryDocument` — cached OIDC discovery document (24 h TTL).
  - `IdTokenValidator` — RS256/RS384/RS512 token validation with
    cached JWKS.
  - `OidcCallbackController` — handles `/auth/oidc/authorize` and
    `/auth/oidc/callback` routes.
  - `OidcAdminController` — admin API for OIDC settings management.
  - Smarty settings form at `templates/oidc-settings.tpl`.
- Routes wired in `Router::oidcAuth()`:
  `GET /auth/oidc/authorize`, `GET /auth/oidc/callback`.
- Admin routes in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/oidc/config`,
  `POST /api/v1/admin/auth-providers/oidc/config`,
  `GET /api/v1/admin/auth-providers/oidc/schema`.
- Composer dependencies added: `web-token/jwt-framework: ^3.0`,
  `phpseclib/phpseclib: ^3.0`.

### Added (Step D.1)

- `Phlix\Auth\AuthProviderRegistry` — singleton registry holding
  registered {@see \Phlix\Auth\ProviderInterface} instances; resolves
  provider-prefixed usernames to the correct external provider.
- `Phlix\Auth\ProviderManager` — bridges {@see AuthManager} to the
  registry; handles `provider:username` parsing and delegates to either
  an external provider or the standard password-based flow.
- `Phlix\Auth\AuthProviderNotFoundException` — thrown when a
  provider-prefix references an unregistered provider.
- `Phlix\Auth\AuthManager::loginWithProvider()` — authenticates a user
  via an external provider (OIDC, LDAP, SAML, passkey). On first login,
  automatically creates a local user row with `password_hash = NULL`.
- `Phlix\Auth\UserRepository::findByExternalId()`,
  `findOrCreateByExternalId()`, `updateProviderData()` — data access
  for provider-linked accounts.
- `Phlix\Server\Http\Controllers\AuthProviderController` — admin API
  for listing / enabling / disabling providers and retrieving their
  configuration JSON schema.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers`,
  `POST /api/v1/admin/auth-providers/{name}/enable`,
  `POST /api/v1/admin/auth-providers/{name}/disable`,
  `GET /api/v1/admin/auth-providers/{name}/config-schema`.
- Migration `009_auth_provider_schema.sql` adds `provider` (VARCHAR 64),
  `external_id` (VARCHAR 255), `provider_data` (JSON) columns to
  `users` table, with indexes `idx_provider` and `idx_external`.
- `detain/phlix-shared:^0.3.0` — new package version with
  `Phlix\Shared\Auth\ProviderInterface`, `AuthResult`, `UserInfo`.
- `docs/plugins/developer-guide.md` — added "Auth Provider Plugins"
  section (Section 13) covering the interface contract, result types,
  manifest, lifecycle hooks, and admin API.
- Unit tests: `AuthResultTest` (5 tests), `UserInfoTest` (6 tests),
  `AuthProviderRegistryTest` (5 tests), `ProviderManagerTest` (8 tests),
  `UserRepositoryExternalIdTest` (5 tests), `AuthProviderControllerTest` (6 tests).

### Added (Step C.9)

- `Phlix\Hub\HubClient::sendHeartbeat()` — now includes `library_count`,
  `total_size_bytes`, and `library-sharing` capability in heartbeat
  payload to advertise library information to the hub.

### Added (Step C.8)

- `Phlix\Hub\SubdomainResult` — DTO for subdomain allocation result with
  subdomain, fqdn, tlsCertPath, and tlsKeyPath fields.
- `Phlix\Hub\SubdomainClient` — client for claiming/releasing subdomains
  from the hub and storing TLS configuration locally.
- `Phlix\Hub\HttpClientInterface::delete()` — added DELETE method for
  subdomain release.
- `Phlix\Hub\HttpClient::delete()` — implements DELETE method.
- `Phlix\Hub\HubClient::getHttpClient()` — exposes HTTP client for use
  by SubdomainClient.
- `scripts/claim-subdomain.php` — CLI script for claiming a subdomain.
- `config/hub.php` — added `subdomain_auto_claim`, `tls_enabled`,
  `domain` configuration options.
- `docs/dev/tls-certificates.md` — guide covering TLS setup, certificate
  sources (hub-provisioned vs self-signed), and security considerations.
- `docs/reference/env-vars.md` — added `PHLIX_SUBDOMAIN_AUTO_CLAIM`,
  `PHLIX_TLS_ENABLED`, `PHLIX_DOMAIN` environment variables.

### Added (Step C.7)

- `Phlix\Network\UpnpIgdClient` — UPnP-IGD client using raw sockets.
  SSDP M-SEARCH discovery on `239.255.255.250:1900`, SOAP
  `AddPortMapping` / `GetExternalIPAddress` / `DeletePortMapping`
  actions for automatic port forwarding on compatible routers.
- `Phlix\Network\StunClient` — RFC 5389 STUN client for discovering
  the server's public IP address and testing port accessibility via
  TCP connect probe.
- `Phlix\Network\NatPmpClient` — RFC 6886 NAT-PMP client for Apple
  AirPort routers and other NAT-PMP-compatible gateways.
- `Phlix\Network\PortForwardService` — orchestrator that tries UPnP
  first, then NAT-PMP, then STUN for IP detection; falls back to
  manual port-forward instructions; stores result to
  `config/port-forward.json`.
- `scripts/port-forward.php` — CLI with `status`, `enable`,
  `disable`, `info`, and `help` commands.
- `src/Common\Container\Providers\NetworkServicesProvider` — registers
  `UpnpIgdClient`, `StunClient`, `NatPmpClient`, and
  `PortForwardService` in the PHP-DI container.
- `config/port-forward.php` — `PHLIX_PORT_FORWARD_AUTO`,
  `PHLIX_EXTERNAL_PORT`, `PHLIX_EXTERNAL_HTTP_PORT`,
  `PHLIX_EXTERNAL_HTTPS_PORT`, `PHLIX_UPNP_ENABLED`,
  `PHLIX_STUN_SERVER`, `PHLIX_STUN_PORT` configuration.
- `docs/hub/remote-access.md` — end-user guide covering UPnP, NAT-PMP,
  STUN, manual port forwarding setup, and troubleshooting.
- `docs/hub-admin/network.md` — hub admin guide covering port forwarding
  configuration, firewall rules, and network requirements.
- `docs/reference/env-vars.md` — documents port-forwarding and STUN
  environment variables.
- `docs/reference/cli.md` — documents `port-forward.php` CLI commands.
- Unit tests: `UpnpIgdClientTest` (5 tests), `StunClientTest` (8 tests),
  `NatPmpClientTest` (6 tests), `PortForwardServiceTest` (9 tests),
  `PortForwardScriptTest` (5 tests).

### Changed (Step C.7)

- `Phlix\Hub\HubClient` now injects `PortForwardService` and calls
  `discoverHostnameCandidates()` to augment heartbeat hostname
  candidates with LAN IP, mDNS, and public IP endpoints when available.
- `Phlix\Common\Container\ContainerFactory::defaultProviders()` now
  registers `NetworkServicesProvider`.

### Added (Step C.6)

- `Phlix\Hub\RelayMessageFramer` — binary framing for HTTP-over-WebSocket
  tunnel. Wire format: `[1-byte type][4-byte seq][4-byte payload_len][payload]`.
  Types: HTTP_REQUEST (1), HTTP_RESPONSE (2), PING (3), PONG (4).
  All payloads are JSON.
- `Phlix\Hub\RelayFrame` — immutable parsed frame DTO with accessors
  (`isRequest()`, `isResponse()`, `isPing()`, `isPong()`).
- `Phlix\Hub\RelayConfig` — relay tunnel configuration from environment
  variables (`PHLIX_RELAY_ENABLED`, `PHLIX_RELAY_HUB_URL`,
  `PHLIX_RELAY_TUNNEL_HOSTNAME`, etc.).
- `Phlix\Hub\RelayConsumer` — server-side Workerman consumer that opens a
  persistent WSS connection to the hub, receives framed HTTP requests,
  dispatches them to the local router, and sends responses back over the
  tunnel. Implements auto-reconnect with configurable delay and
  keep-alive ping/pong.
- `Phlix\Hub\RelayApplication` — thin Workerman Worker entry point
  (`text://` protocol, timer-driven) wrapping `RelayConsumer`.
- `config/relay.php` — `PHLIX_RELAY_ENABLED`, `PHLIX_RELAY_HUB_URL`,
  `PHLIX_RELAY_TUNNEL_HOSTNAME`, `PHLIX_RELAY_RECONNECT_DELAY`,
  `PHLIX_RELAY_PING_INTERVAL`, `PHLIX_RELAY_PING_TIMEOUT`.
- `config/hub.php` — added `relay` capability to heartbeat payload.
- `docs/dev/relay-protocol.md` — wire protocol reference for the
  HTTP-over-WebSocket relay tunnel.
- `docs/reference/env-vars.md` — documents relay env vars.
- Unit tests: `RelayMessageFramerTest` (13 tests covering frame round-trips,
  ping/pong, invalid/incomplete frames), `RelayConsumerTest` (11 tests
  covering config, routing, connection state).

### Changed (Step C.6)

- `Phlix\Hub\HubClient::sendHeartbeat()` now advertises `relay`
  in the server capabilities list.
- `Phlix\Server\Core\Application` now starts `RelayApplication`
  automatically when `config/hub-enrollment.json` exists and
  `PHLIX_RELAY_ENABLED=true`.
- `Phlix\Common\Container\Providers\HubServicesProvider` now registers
  `RelayConfig`, `RelayMessageFramer`, `RelayConsumer`, and
  `RelayApplication` in the PHP-DI container.

### Added (Step C.2)

- `Phlix\Hub\HubClient` — server-side orchestrator for server↔hub pairing,
  heartbeat loop, re-enrollment, and JWKS exposure. Implements the protocol
  defined in `docs/dev/pairing-protocol.md`.
- `Phlix\Hub\Ed25519KeyManager` — generates, stores, loads, and rotates
  Ed25519 keypairs (libsodium `sodium_crypto_sign_*`). Key stored at
  `config/hub-server-key.pem` (mode 0600). Key ID is SHA-256 first 8 bytes
  of the public key (base64url).
- `Phlix\Hub\HttpClient` — cURL-based HTTP client for hub API communication.
  Always sends `Accept-Phlix-Protocol: v1` header.
- `Phlix\Hub\HubApplication` — thin Workerman Worker wrapper for the
  background heartbeat loop (`text://` protocol, timer-driven).
- `Phlix\Server\Http\Controllers\HubJwksController` — serves
  `GET /.well-known/jwks.json` with the server's Ed25519 JWK(s).
  Cache-Control: public, max-age=3600.
- `scripts/pair-with-hub.php` — CLI pairing script. Initiates claim request,
  displays claim code, polls until claimed, stores enrollment, starts
  heartbeat loop.
- `config/hub.php` — hub subsystem configuration (`PHLIX_HUB_URL`,
  `PHLIX_HUB_HEARTBEAT_INTERVAL`, key/enrollment paths).
- `Phlix\Common\Container\Providers\HubServicesProvider` — registers
  Ed25519KeyManager, HubClient, HubJwksController, HubApplication in
  the PHP-DI container.
- `docs/reference/api/hub-jwks.yaml` — OpenAPI 3.0 spec for
  `/.well-known/jwks.json`.
- `docs/reference/cli.md` — documents `php scripts/pair-with-hub.php`.
- `docs/reference/env-vars.md` — documents `PHLIX_HUB_URL`,
  `PHLIX_HUB_ENROLLMENT_TOKEN`, `PHLIX_HUB_HEARTBEAT_INTERVAL`.

### Changed (Step C.2)

- `src/Server/Core/Application` now starts the hub heartbeat background
  worker automatically when `config/hub-enrollment.json` exists.
- `src/Common\Container\ContainerFactory` now wires `HubServicesProvider`
  into the default provider list.

### Added (Step C.5)

- `Phlix\Hub\HubJwtValidator` — validates JWTs issued by the Phlix Hub
  using the hub's JWKS. Supports Ed25519 signature verification via
  `sodium_crypto_sign_verify_detached`, automatic JWKS caching with TTL,
  and key rotation (refetches JWKS once on unknown `kid`).
- `Phlix\Hub\HubUserClaims` — immutable DTO for extracted hub JWT claims
  (`userId`, `serverId`, `subject`, `issuer`, `expiresAt`, `scope`).
- `Phlix\Hub\JwksCache` — in-memory JWKS cache with TTL support.
- `Phlix\Hub\HttpClientFactory` — factory for creating HTTP clients used
  by `HubJwtValidator` to fetch JWKS (enables testability).
- `Phlix\Server\Http\Middleware\HubJwtMiddleware` — validates hub JWTs on
  routes that support hub-mediated access. Populates `$request->hubUser`
  with `HubUserClaims` on success; returns 401 on invalid/expired tokens.
- `Phlix\Server\Http\Controllers\HubTokenController` — exchanges a hub JWT
  for a server-issued session token via `POST /api/v1/auth/hub-token`.
  Provides backward compatibility for older clients that present a hub
  JWT to get a server session token.
- `Phlix\Server\Http\Request::$hubUser` — new property holding
  `HubUserClaims` when the request was authenticated via hub JWT.
- `config/hub.php` — added `hub_jwks_url` key (`PHLIX_HUB_JWKS_URL`
  env var) for the hub's JWKS endpoint.
- `docs/reference/env-vars.md` — documents `PHLIX_HUB_JWKS_URL`.
- Unit tests: `HubJwtValidatorTest`, `HubUserClaimsTest`,
  `JwksCacheTest`, `HubJwtMiddlewareTest` (18 new tests).

### Changed (Step C.5)

- `Phlix\Common\Container\Providers\HubServicesProvider` now registers
  `HubJwtValidator`, `HubTokenController`, `HubJwtMiddleware`,
  `HttpClientFactory`, and `JwksCache`.
- `Phlix\Server\Core\Application` now registers the
  `POST /api/v1/auth/hub-token` route.

## [0.11.0] — 2026-05-17

### Changed

- Repository moved from `github.com/detain/phlix` to
  `github.com/detain/phlix-server`. The local working directory stays
  `/home/sites/phlix` per the expansion plan; only the `origin` remote
  URL changes. Update your local clone with
  `git remote set-url origin git@github.com:detain/phlix-server.git`.
  The old `detain/phlix` repo is archived (B.4b) with a README pointing
  at the new home.
- Refactored to depend on `detain/phlix-shared:^0.2`. The
  `LifecycleInterface`, manifest DTOs, event DTOs, and `EventNameMap`
  now live in the shared package. Old FQCNs
  (`Phlix\Plugins\Contract\LifecycleInterface`,
  `Phlix\Plugins\Manifest`, `Phlix\Plugins\ManifestType`,
  `Phlix\Plugins\ManifestValidationError`,
  `Phlix\Plugins\EventNameMap`, `Phlix\Common\Events\*`) remain as
  deprecated aliases through 0.11.x; removed in 0.12.0.
- Manifest schema validation extracted to
  `Phlix\Plugins\Manifest\ManifestSchema`.

### Added

- Composer require on `detain/phlix-shared:^0.2.0` via a VCS
  repositories entry.
- `src/Plugins/AliasCompatShim.php` registers the 16 `class_alias`
  entries for the moved classes.
- Three-line interface bridge at
  `src/Plugins/Contract/LifecycleInterface.php` (extends the shared
  interface — `class_alias` doesn't work for interfaces).

- Complete plugin developer documentation
  ([`docs/plugins/developer-guide.md`](docs/plugins/developer-guide.md))
  covering plugin types, manifest, lifecycle, event subscription,
  settings, signing, packaging, local testing, and publishing — plus a
  matching server-internals reference for contributors extending the
  loader ([`docs/dev/plugin-sdk.md`](docs/dev/plugin-sdk.md)). Phase A
  is now functionally complete; the plugin system is ready for
  external authors. `docs/plugins/install-from-catalog.md` rewritten
  to set expectations about the catalog's Phase L delivery; README
  promotes the developer guide and the reference plugin.
- Plugin manifest specification (`docs/plugins/manifest.md`,
  `docs/plugins/manifest.schema.json`) and the
  `Phlix\Plugins\Manifest` value object that parses and validates
  `plugin.json` files. The eleven plugin types from
  `PHLIX_EXPANSION_PLAN.md` §5 are codified as the
  `Phlix\Plugins\ManifestType` enum. No loader yet — see Step A.4.
  Adds `justinrainbow/json-schema:^5.2` as a runtime dependency.
- PSR-11 dependency injection container (PHP-DI). Application services are
  now auto-wired; the legacy ConnectionPool / LoggerFactory statics remain
  for backwards compatibility but are wrapped behind container bindings.
- `phpstan/phpstan` (level 9) and `squizlabs/php_codesniffer` (PSR-12) added
  as require-dev so the documented "minimum bar" is actually enforceable.
  A `phpstan-baseline.neon` absorbs pre-existing errors so new code is held
  to the bar without forcing a repo-wide refactor.
- `docs/dev/architecture-server.md` and `docs/reference/env-vars.md`.
- PSR-14 event dispatcher (Crell\Tukio). Playback, library-scan, and
  auth lifecycle events are now published from `PlaybackController`,
  `MediaScanner`, and `AuthManager`; plugins will be able to subscribe in
  Phase A.4. Twelve typed `readonly` event DTOs ship in
  `src/Common/Events/`. New env var `PHLIX_DEBUG_EVENTS` and `events`
  log channel. Canonical catalog in `docs/dev/event-reference.md`.
- Plugin loader (`Phlix\Plugins\PluginLoader`) with the full
  install / enable / disable / uninstall lifecycle. Plugins can be
  installed from a URL (HTTPS + `file://` by default; HTTP behind
  `PHLIX_PLUGINS_ALLOW_HTTP=1`) or from a local directory; each plugin
  gets its own Composer-resolved `vendor/` tree under
  `var/plugins/<name>/`. The lifecycle contract lives in
  `Phlix\Plugins\Contract\LifecycleInterface` (temporary home — moves to
  `Phlix\Shared\Plugin` in B.1). New table `plugins` (migration
  `migrations/003_plugins.sql`). New `plugins` log channel and config
  key. New env vars: `PHLIX_PLUGINS_ALLOW_HTTP`,
  `PHLIX_PLUGINS_REQUIRE_SIGNATURE`, `PHLIX_PLUGINS_COMPOSER_TIMEOUT`.
  Adds `symfony/process:^7.0`.
  See `docs/plugins/developer-guide.md` for the lifecycle diagram and
  a sample `LifecycleInterface` implementation.
- Plugin admin UI at `/admin/plugins` and JSON API under
  `/api/v1/admin/plugins/*` (list / install / enable / disable /
  uninstall). All routes gated by a new `AdminMiddleware` that reads
  the new `users.is_admin` flag (migration `004_admin_user_flag.sql`).
  The first user registered after the migration is auto-promoted to
  admin; subsequent users default to `is_admin = 0`. Adds runtime
  Composer dep `smarty/smarty:^4.0` (already used at runtime; now
  declared). OpenAPI spec at `docs/reference/api/admin-plugins.yaml`;
  end-user docs at `docs/plugins/install-from-url.md`. Editable
  settings UI deferred to a later phase — A.5 renders settings
  read-only with `secret: true` fields masked.
- Reference plugin
  [`phlix-plugin-example`](https://github.com/detain/phlix-plugin-example)
  — the first community-shaped Phlix plugin, published as its own
  public GitHub repo. Implements
  `Phlix\Plugins\Contract\LifecycleInterface` as a
  `metadata-provider` that returns `['title' => 'Hello, World']` for a
  fixed fixture path, and ships unsigned by design as the canonical
  fork-as-starter template for plugin authors. Installable through the
  A.5 admin UI by pasting
  `https://raw.githubusercontent.com/detain/phlix-plugin-example/main/plugin.json`
  into **Install from URL**. Server-side wiring: new fixture
  `tests/fixtures/plugins/example-manifest.json` mirrors the published
  manifest so the loader's URL-install test can use a `file://` URL,
  and `docs/plugins/install-from-url.md` /
  `docs/plugins/trusted-plugin-list.md` now reference the live
  example URL.

### Deprecated

- `Phlix\Server\Core\Application::getInstance()` — resolve services from
  the PSR-11 container instead. Slated for removal in Phase B.

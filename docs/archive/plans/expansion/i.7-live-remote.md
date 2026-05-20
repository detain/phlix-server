# Step I.7 — Re-stream HLS to remote via hub relay

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.7
**Depends on:** I.5, C.6
**Review:** Yes — see `i.7-live-remote-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Enable remote clients to watch Live TV by relaying HLS streams through the
hub's `RelayConsumer` (from Phase C.6). When a remote client connects to a
hub URL, the hub fetches the local HLS stream (variant playlist + .ts segments)
and proxies it over the WebSocket tunnel to the remote client. This step builds
the server-side relay mount and the HLS segment prefetching layer.

## 2. Context (what already exists)

- `src/Hub/RelayConsumer.php` (Phase C.6) — already maintains a persistent
  WSS tunnel to the hub, receives HTTP request frames, dispatches locally,
  and returns responses. The tunnel is bidirectional.
- `src/Media/Streaming/HlsStreamer.php` — already produces HLS variant
  playlists and segment URLs. I.7 modifies segment serving to support
  remote relay mode.
- `src/LiveTv/LiveTvManager.php` — after I.1–I.6, provides `tuneToChannel()`
  which returns a stream URL. I.7 bridges this to the hub relay.
- `config/livetv.php` — already has all tuner and DVR config. I.7 adds
  `relay` section.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.7 is the hub relay step.

## 3. Scope — files to create / modify

### Create

#### HLS relay

- `src/LiveTv/Relay/HlsRelaySession.php` — manages a single remote relay
  session for a live TV stream:

  ```php
  class HlsRelaySession
  {
      public function __construct(
          public readonly string $sessionId,
          public readonly string $channelId,
          public readonly string $tuneRequestId,
          public readonly int $createdAt,
      ) {}

      /** Get the relay mount URL for this session (used by remote clients). */
      public function getMountUrl(): string {}

      /** Get the local HLS variant playlist URL. */
      public function getVariantPlaylistUrl(): string {}
  }
  ```

- `src/LiveTv/Relay/HlsRelayManager.php` — orchestrates relay sessions and
  the hub WebSocket tunnel:

  ```php
  class HlsRelayManager
  {
      public function __construct(
          private readonly LiveTvManager $liveTvManager,
          private readonly HlsStreamer $hlsStreamer,
          private readonly RelayConsumer $relayConsumer,
          private readonly Connection $db,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Start a relay session for a channel. Creates tune request + session. */
      public function startRelaySession(string $channelId, string $userId): HlsRelaySession {}

      /** Stop a relay session and release the tuner. */
      public function stopRelaySession(string $sessionId): void {}

      /** Get active relay sessions for the hub. */
      public function getActiveSessions(): array {}

      /** Check if a user has an active relay session. */
      public function getUserSession(string $userId): ?HlsRelaySession {}
  }
  ```

- `src/LiveTv/Relay/HlsSegmentPrefetcher.php` — prefetches HLS segments
  ahead of playback for smoother relay performance:

  ```php
  class HlsSegmentPrefetcher
  {
      public function __construct(
          private readonly ?LoggerInterface $logger = null,
          private readonly int $prefetchSegments = 3,
      ) {}

      /** Prefetch the next N segments for a variant playlist. */
      public function prefetch(string $variantPlaylistUrl): void {}

      /** Get the URL for a prefetched segment (cache hit). */
      public function getSegment(string $url): ?string {}

      /** Start prefetching for a channel (background). */
      public function startPrefetch(string $sessionId, string $variantPlaylistUrl): void {}

      /** Stop prefetching for a session. */
      public function stopPrefetch(string $sessionId): void {}
  }
  ```

- `src/LiveTv/Relay/HlsRelaySessionFactory.php`:

  ```php
  final class HlsRelaySessionFactory
  {
      public static function build(
          LiveTvManager $liveTvManager,
          HlsStreamer $hlsStreamer,
          RelayConsumer $relayConsumer,
          Connection $db,
          ?LoggerInterface $logger = null,
      ): HlsRelayManager {}
  }
  ```

#### DB Migration

- `migrations/015_livetv_relay_sessions.sql` — create relay sessions table:
  ```sql
  CREATE TABLE livetv_relay_sessions (
    session_id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    channel_id CHAR(36) NOT NULL,
    tune_request_id CHAR(36) NOT NULL,
    mount_url VARCHAR(512) NOT NULL,
    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    bytes_relayed BIGINT NOT NULL DEFAULT 0,
    INDEX idx_user_id (user_id),
    INDEX idx_started_at (started_at)
  );
  ```

#### Config

- `config/livetv.php` — add `relay` section:
  ```php
  'relay' => [
      'enabled' => true,
      'prefetch_segments' => 3,
      'max_concurrent_sessions' => 10,
      'segment_cache_ttl_seconds' => 30,
      'relay_path_prefix' => '/relay/live',
  ],
  ```

#### Tests

- `tests/Unit/LiveTv/Relay/HlsRelaySessionTest.php`
- `tests/Unit/LiveTv/Relay/HlsRelayManagerTest.php`
- `tests/Unit/LiveTv/Relay/HlsSegmentPrefetcherTest.php`

#### Documentation

- `docs/developers/live-relay.md` — hub relay architecture, relay sessions,
  segment prefetching, config keys.

### Modify

- `src/Hub/RelayConsumer.php` — after I.7, extend to handle HLS segment
  relay: when a `RelayConsumer` receives an HTTP GET for `/relay/live/{sessionId}/*`,
  it serves the segment from `HlsSegmentPrefetcher`'s cache (cache-hit) or
  proxies to the local HLS streamer (cache-miss).
- `src/LiveTv/LiveTvManager.php` — after I.6, wire `HlsRelayManager` as
  a service. `startRelaySession()` creates a tune request via `tuneToChannel()`
  and registers the relay session in the DB.
- `src/Media/Streaming/HlsStreamer.php` — add a method to serve the local
  variant playlist and segments as raw files (not HTTP) for the relay to pick up.
- `composer.json` — no new dependencies.
- `CHANGELOG.md` — add entry: "Added: hub relay for remote live TV streams
  (HLS re-streaming via hub WebSocket tunnel)".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master, branch `i.7-live-remote`.
   Read `RelayConsumer.php` thoroughly before modifying it.
2. **Relay session.** `HlsRelaySession` is a plain value object with a
   generated `sessionId`. `mountUrl` is `/relay/live/{sessionId}/playlist.m3u8`.
3. **Relay manager.** `HlsRelayManager::startRelaySession()` calls
   `$liveTvManager->tuneToChannel($channelId)` → gets tune request →
   creates `HlsRelaySession` → stores in DB → registers with
   `$relayConsumer->registerMount()`. Returns session to the caller.
4. **Segment prefetcher.** `HlsSegmentPrefetcher::startPrefetch()` uses
   a Workerman timer to periodically fetch the next N segments of the
   variant playlist and store them in an in-memory LRU cache (max 10 MB).
   Segments are keyed by their URL hash.
5. **RelayConsumer extension.** `RelayConsumer` gets a new method
   `registerMount(string $path, callable $handler)`. When HTTP GET
   arrives for `/relay/live/{sessionId}/*`, it calls the registered handler.
   The handler checks `HlsSegmentPrefetcher` cache first, then falls back
   to `file_get_contents()` from the local HLS streamer.
6. **DB migration.** `migrations/015_livetv_relay_sessions.sql`.
7. **Tests.** Three test files per §5.
8. **Verification bar.**
9. **Docs.**
10. **Commit + PR + merge.**

## 5. Tests (REQUIRED)

Unit tests (coverage ≥ 85 % on every new class):

1. `HlsRelaySessionTest::test_get_mount_url_formats_correctly`
2. `HlsRelaySessionTest::test_get_variant_playlist_url`
3. `HlsRelaySessionTest::test_session_id_is_uuid`
4. `HlsRelayManagerTest::test_start_relay_session_creates_tune_request`
5. `HlsRelayManagerTest::test_start_relay_session_stores_in_db`
6. `HlsRelayManagerTest::test_stop_relay_session_releases_tuner`
7. `HlsRelayManagerTest::test_get_user_session_returns_active_session`
8. `HlsRelayManagerTest::test_get_active_sessions`
9. `HlsSegmentPrefetcherTest::test_prefetch_fetches_segments`
10. `HlsSegmentPrefetcherTest::test_get_segment_returns_cached`
11. `HlsSegmentPrefetcherTest::test_get_segment_returns_null_on_cache_miss`
12. `HlsSegmentPrefetcherTest::test_start_and_stop_prefetch`
13. `HlsSegmentPrefetcherTest::test_prefetch_respects_prefetch_segments_count`

**Coverage target:** `HlsRelaySession` ≥ 90 %, `HlsRelayManager` ≥ 80 %,
`HlsSegmentPrefetcher` ≥ 85 %.

## 6. Documentation

Matrix rows that apply:
- **"Anything"** → `docs/developers/live-relay.md` covers hub relay architecture,
  session lifecycle, prefetching, client playback URL.
- **"New public class/method"** → all new classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria

- [ ] `HlsRelaySession::getMountUrl()` returns `/relay/live/{sessionId}/playlist.m3u8`.
- [ ] `HlsRelayManager::startRelaySession()` calls `tuneToChannel()` and
      creates a `HlsRelaySession` persisted in DB.
- [ ] `HlsRelayManager::startRelaySession()` registers the mount with
      `RelayConsumer`.
- [ ] `HlsRelayManager::stopRelaySession()` calls `stopTuning()` and deletes
      the DB record.
- [ ] `HlsRelayManager::getActiveSessions()` returns all active relay sessions.
- [ ] `HlsRelayManager::getUserSession()` returns the user's active session
      or `null`.
- [ ] `HlsSegmentPrefetcher::prefetch()` downloads and caches segments from
      the variant playlist URL.
- [ ] `HlsSegmentPrefetcher::getSegment()` returns cached segment data or `null`.
- [ ] `HlsSegmentPrefetcher` uses an LRU cache with a size limit.
- [ ] `RelayConsumer` handles `/relay/live/{sessionId}/*` requests by serving
      from prefetcher cache or proxying to local HLS streamer.
- [ ] `config/livetv.php` has `relay` key with `prefetch_segments` and
      `max_concurrent_sessions`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage targets met.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/live-relay.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b i.7-live-remote
php scripts/run-migrations.php
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HlsRelay'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step I.7: HLS re-streaming via hub relay for remote live TV"
unset GITHUB_TOKEN
gh pr create \
  --title "Step I.7 (Live TV): Hub relay for remote HLS live TV re-streaming" \
  --body  "Adds HlsRelayManager, HlsSegmentPrefetcher, relay sessions for remote live TV via hub WebSocket tunnel. Part of Phase I (Step I.7 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git log --oneline -1 && git branch --list 'i.7-*'
```

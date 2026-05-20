# Step L.3 — Stats Schema + Collectors

**Phase:** L (Notifications, Stats & Admin)
**Step:** L.3
**Depends on:** L.1 (webhook framework for events)
**Review:** Yes — see `l.3-stats-collectors-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a **stats collection system** that tracks:
- Playback events (what, when, who, how long, device).
- Library changes (added/removed items).
- User activity (login/logout, searches).
- Storage usage (library sizes, transcode cache size).

Stats are aggregated into a `stats_*` set of tables and exposed via an admin API. This powers L.4's "now playing + top users/media" dashboard.

## 2. Context (what already exists)

Read first:

- `src/Session/PlaybackController.php` — existing playback controller.
- `src/Media/Library/LibraryManager.php` — library events.
- `src/Auth/AuthManager.php` — auth events.
- `config/` — existing config.

## 3. Scope — files to create / modify

### Create

#### Database schema

- `migrations/019_stats_schema.sql`:
  ```sql
  CREATE TABLE stats_playback_events (
      id CHAR(36) PRIMARY KEY,
      user_id CHAR(36) NOT NULL,
      media_item_id CHAR(36) NOT NULL,
      media_type ENUM('movie','series','music','photo') NOT NULL,
      started_at DATETIME NOT NULL,
      ended_at DATETIME,
      duration_seconds INT DEFAULT 0,
      device_id VARCHAR(255),
      client_ip VARCHAR(45),
      completed BOOLEAN DEFAULT FALSE,
      INDEX idx_user_started (user_id, started_at),
      INDEX idx_media_started (media_item_id, started_at),
      INDEX idx_started_at (started_at)
  );

  CREATE TABLE stats_library_changes (
      id CHAR(36) PRIMARY KEY,
      change_type ENUM('item_added','item_removed','metadata_updated') NOT NULL,
      media_item_id CHAR(36),
      library_id CHAR(36),
      user_id CHAR(36),
      changed_at DATETIME NOT NULL,
      details_json TEXT
  );

  CREATE TABLE stats_user_activity (
      id CHAR(36) PRIMARY KEY,
      user_id CHAR(36) NOT NULL,
      activity_type ENUM('login','logout','search','profile_change') NOT NULL,
      occurred_at DATETIME NOT NULL,
      ip_address VARCHAR(45),
      user_agent TEXT,
      details_json TEXT
  );

  CREATE TABLE stats_storage (
      id CHAR(36) PRIMARY KEY,
      recorded_at DATETIME NOT NULL,
      library_id CHAR(36),
      media_type ENUM('movie','series','music','photo') NOT NULL,
      item_count INT DEFAULT 0,
      total_bytes BIGINT DEFAULT 0,
      transcode_cache_bytes BIGINT DEFAULT 0
  );
  ```

#### Stats collector

- `src/Stats/StatsCollector.php`:
  ```php
  class StatsCollector
  {
      public function __construct(private readonly Connection $db) {}

      /** Record a playback start event. */
      public function recordPlaybackStart(string $userId, string $mediaItemId, string $mediaType, ?string $deviceId = null): string {}

      /** Record a playback end event. */
      public function recordPlaybackEnd(string $eventId, int $durationSeconds, bool $completed): void {}

      /** Record a library change. */
      public function recordLibraryChange(string $changeType, ?string $mediaItemId = null, ?string $libraryId = null, ?string $userId = null, array $details = []): void {}

      /** Record a user activity event. */
      public function recordUserActivity(string $userId, string $activityType, ?string $ipAddress = null, array $details = []): void {}

      /** Record storage snapshot. */
      public function recordStorageSnapshot(string $mediaType, int $itemCount, int $totalBytes, int $transcodeCacheBytes = 0, ?string $libraryId = null): void {}

      /** Get playback stats for a date range. */
      public function getPlaybackStats(\DateTimeInterface $from, \DateTimeInterface $to): array {}

      /** Get top users by watch time. */
      public function getTopUsers(int $limit = 10, ?\DateTimeInterface $since = null): array {}

      /** Get top media items by play count. */
      public function getTopMedia(int $limit = 10, ?\DateTimeInterface $since = null): array {}
  }
  ```

#### Stats API controller

- `src/Server/Http/Controllers/Stats/StatsController.php`:
  - `GET /api/v1/admin/stats/playback` — playback stats summary
  - `GET /api/v1/admin/stats/top-users` — top users by watch time
  - `GET /api/v1/admin/stats/top-media` — top media by play count
  - `GET /api/v1/admin/stats/storage` — storage usage over time

#### Tests

- `tests/Unit/Stats/StatsCollectorTest.php`

### Modify

- `src/Session/PlaybackController.php` — inject `StatsCollector` and call `recordPlaybackStart`/`recordPlaybackEnd`.
- `composer.json` — no new dependencies.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after L.1 merged): `git checkout -b l.3-stats-collectors`.
2. Create migration first.
3. Build `StatsCollector` with DB writes and aggregation queries.
4. Integrate into existing controllers (`PlaybackController`, `LibraryManager`, `AuthManager`) via constructor injection.
5. Write tests using mocks.
6. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
7. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `StatsCollectorTest::test_record_playback_start_creates_event`
2. `StatsCollectorTest::test_record_playback_end_calculates_duration`
3. `StatsCollectorTest::test_record_library_change_stores_change`
4. `StatsCollectorTest::test_record_user_activity_stores_activity`
5. `StatsCollectorTest::test_get_top_users_aggregates_watch_time`
6. `StatsCollectorTest::test_get_top_media_aggregates_play_count`
7. `StatsCollectorTest::test_get_playback_stats_returns_time_series`

## 6. Acceptance Criteria

- [ ] Migration creates 4 stats tables: `stats_playback_events`, `stats_library_changes`, `stats_user_activity`, `stats_storage`.
- [ ] `StatsCollector` records all event types with proper timestamps and JSON details.
- [ ] `recordPlaybackStart` returns event ID for later completion via `recordPlaybackEnd`.
- [ ] `getPlaybackStats()` returns time-series data grouped by day.
- [ ] `getTopUsers()` returns users sorted by total watch time.
- [ ] `getTopMedia()` returns media items sorted by play count.
- [ ] Integration calls in `PlaybackController` fire on play start/end.
- [ ] ≥ 7 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b l.3-stats-collectors
# ... implement ...
./vendor/bin/phpunit tests/Unit/Stats/
./vendor/bin/phpstan analyze src/Stats --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Stats/
git add -A
git commit -m "Step L.3: Stats schema + collectors"
unset GITHUB_TOKEN
gh pr create --title "Step L.3: Stats schema + collectors" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `l.3-stats-collectors-review.md`.

(End of file - total 132 lines)

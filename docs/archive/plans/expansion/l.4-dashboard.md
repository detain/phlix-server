# Step L.4 — Now Playing + Dashboard

**Phase:** L (Notifications, Stats & Admin)
**Step:** L.4
**Depends on:** L.3 (stats collectors for data)
**Review:** Yes — see `l.4-dashboard-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build an **admin dashboard** for Phlex Hub that shows:
- **Now Playing** — currently active playback sessions across all users/devices (live-updating via WebSocket).
- **Top Users** — leaderboard of users by total watch time (from L.3 stats).
- **Top Media** — most-played movies/series (from L.3 stats).
- **Storage** — library sizes and transcode cache usage.
- **Recent Activity** — feed of recent playback events, library changes, user logins.

## 2. Context (what already exists)

Read first:

- `src/Stats/StatsCollector.php` — from L.3.
- `src/Session/SessionManager.php` — existing session management.
- `src/Media/Streaming/StreamManager.php` — active streams.
- `src/Server/WebSocket/WebSocketServer.php` — existing WebSocket.
- `public/templates/` — existing Smarty templates.
- `src/Server/WebPortal/PageRenderer.php` — existing renderer.

## 3. Scope — files to create / modify

### Create

#### Dashboard data service

- `src/Admin/DashboardService.php`:
  ```php
  class DashboardService
  {
      public function __construct(
          private readonly StatsCollector $stats,
          private readonly SessionManager $sessions,
          private readonly StreamManager $streams,
          private readonly Connection $db,
      ) {}

      /** Get all currently active playback sessions. */
      public function getNowPlaying(): array {}

      /** Get top users leaderboard. */
      public function getTopUsers(int $limit = 10, ?int $days = 30): array {}

      /** Get top media items. */
      public function getTopMedia(int $limit = 10, ?int $days = 30): array {}

      /** Get storage usage summary. */
      public function getStorageSummary(): array {}

      /** Get recent activity feed. */
      public function getRecentActivity(int $limit = 20): array {}
  }
  ```

#### WebSocket events for live updates

- `src/Server/WebSocket/Events.php` — add `DASHBOARD_NOW_PLAYING` constant.
- `src/Server/WebSocket/MessageHandler.php` — handle `subscribe_dashboard` event:
  ```php
  case 'subscribe_dashboard':
      // Send current now-playing state immediately
      // Then broadcast updates on playback start/stop
      break;
  ```

#### Dashboard page (Smarty)

- `public/templates/admin/dashboard.tpl`:
  - Now Playing grid (user avatar, media title, poster, progress bar, device).
  - Top Users table (rank, username, total watch time, sessions count).
  - Top Media table (rank, title, poster, play count).
  - Storage pie/bar chart (by media type).
  - Recent Activity feed (timestamp, event type, user, details).
  - Auto-refresh every 30 seconds via `fetch()`.
  - WebSocket live updates for now-playing changes.

#### Dashboard API endpoints

- `src/Server/Http/Controllers/Admin/DashboardController.php`:
  - `GET /api/v1/admin/dashboard/now-playing` — current sessions
  - `GET /api/v1/admin/dashboard/top-users` — leaderboard
  - `GET /api/v1/admin/dashboard/top-media` — popular items
  - `GET /api/v1/admin/dashboard/storage` — storage summary
  - `GET /api/v1/admin/dashboard/activity` — recent activity feed

#### PageRenderer

- `public/templates/admin/dashboard.tpl` — new dashboard template
- `src/Server/WebPortal/PageRenderer.php` — add `renderDashboard(Request $r): Response`

### Modify

- `src/Server/WebSocket/Events.php` — add `DASHBOARD_NOW_PLAYING` event constant.
- `src/Server/WebSocket/MessageHandler.php` — handle `subscribe_dashboard` event.
- `src/Server/Core/Application.php` — register admin dashboard routes.
- `public/index.php` — add `/admin/dashboard` route.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after L.3 merged): `git checkout -b l.4-dashboard`.
2. Build `DashboardService` aggregating data from L.3 stats and active sessions.
3. Add WebSocket `subscribe_dashboard` handler for live now-playing updates.
4. Build Smarty `admin/dashboard.tpl` with real data.
5. Add WebSocket broadcast on `playback.started` and `playback.ended` events.
6. Write backend unit tests for `DashboardService`.
7. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
8. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `DashboardServiceTest::test_get_now_playing_returns_active_sessions`
2. `DashboardServiceTest::test_get_top_users_returns_leaderboard`
3. `DashboardServiceTest::test_get_top_media_returns_popular_items`
4. `DashboardServiceTest::test_get_storage_summary_aggregates_by_type`
5. `DashboardServiceTest::test_get_recent_activity_returns_feed`

## 6. Acceptance Criteria

- [ ] `DashboardService` aggregates from `StatsCollector`, `SessionManager`, `StreamManager`.
- [ ] `getNowPlaying()` returns all active playback sessions with user, media, progress, device.
- [ ] `getTopUsers()` returns top N users sorted by watch time.
- [ ] `getTopMedia()` returns top N media items sorted by play count.
- [ ] `getStorageSummary()` returns per-media-type byte counts.
- [ ] `getRecentActivity()` returns feed of playback + library + auth events.
- [ ] WebSocket `subscribe_dashboard` event sends current state + live updates.
- [ ] Dashboard Smarty template at `public/templates/admin/dashboard.tpl`.
- [ ] Auto-refresh dashboard every 30 seconds via JavaScript `fetch()`.
- [ ] Admin API: 5 endpoints for dashboard data.
- [ ] ≥ 5 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b l.4-dashboard
# ... implement ...
./vendor/bin/phpunit tests/Unit/Admin/
./vendor/bin/phpstan analyze src/Admin --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Admin/
git add -A
git commit -m "Step L.4: Now-playing + top users/media dashboard"
unset GITHUB_TOKEN
gh pr create --title "Step L.4: Now-playing + dashboard" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `l.4-dashboard-review.md`.

(End of file - total 138 lines)

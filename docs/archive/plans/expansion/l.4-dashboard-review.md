# Step L.4 — Now Playing + Dashboard: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/Unit/Admin/
# MUST be green; ≥ 5 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Admin --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Admin/
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Admin -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 5. Template check ─────────────────────────────────────
ls public/templates/admin/dashboard.tpl
# File must exist
```

## Acceptance Criteria

- [ ] `DashboardService` uses `StatsCollector`, `SessionManager`, `StreamManager` via constructor injection.
- [ ] `getNowPlaying()` returns array of active sessions: `{user_id, username, media_id, media_title, media_type, poster_url, progress_seconds, device_name, started_at}`.
- [ ] `getTopUsers($limit, $days)` calls `StatsCollector::getTopUsers()` and formats as leaderboard with rank.
- [ ] `getTopMedia($limit, $days)` calls `StatsCollector::getTopMedia()` and formats with rank and poster.
- [ ] `getStorageSummary()` returns `{movie_bytes, series_bytes, music_bytes, photo_bytes, transcode_cache_bytes}`.
- [ ] `getRecentActivity($limit)` returns feed of `{occurred_at, event_type, user_id, username, details}` sorted by time descending.
- [ ] WebSocket `subscribe_dashboard` event handler sends current `getNowPlaying()` immediately on subscribe.
- [ ] `dashboard.tpl` shows: Now Playing grid, Top Users table, Top Media table, Storage summary, Recent Activity feed.
- [ ] Dashboard auto-refreshes every 30 seconds via `setInterval` + `fetch('/api/v1/admin/dashboard/now-playing')`.
- [ ] WebSocket broadcasts `DASHBOARD_NOW_PLAYING` event when playback starts or ends.
- [ ] Admin API: `GET /api/v1/admin/dashboard/now-playing|top-users|top-media|storage|activity`.
- [ ] ≥ 5 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 45 lines)

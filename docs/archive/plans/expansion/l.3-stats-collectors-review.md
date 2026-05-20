# Step L.3 — Stats Schema + Collectors: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/unit/Stats/
# MUST be green; ≥ 7 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Stats --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Stats/
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Stats -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 5. Migration check ──────────────────────────────────────
ls migrations/019_stats_schema.sql
# File must exist
```

## Acceptance Criteria

- [ ] Migration `migrations/019_stats_schema.sql` creates all 4 tables.
- [ ] `stats_playback_events` has: id, user_id, media_item_id, media_type, started_at, ended_at, duration_seconds, device_id, client_ip, completed.
- [ ] `stats_library_changes` has: id, change_type, media_item_id, library_id, user_id, changed_at, details_json.
- [ ] `stats_user_activity` has: id, user_id, activity_type, occurred_at, ip_address, user_agent, details_json.
- [ ] `stats_storage` has: id, recorded_at, library_id, media_type, item_count, total_bytes, transcode_cache_bytes.
- [ ] `StatsCollector::recordPlaybackStart()` inserts row and returns event ID.
- [ ] `StatsCollector::recordPlaybackEnd()` updates ended_at, duration_seconds, completed.
- [ ] `StatsCollector::recordLibraryChange()` stores change with JSON details.
- [ ] `StatsCollector::recordUserActivity()` stores activity with IP and user agent.
- [ ] `StatsCollector::getPlaybackStats($from, $to)` returns array with daily totals.
- [ ] `StatsCollector::getTopUsers($limit, $since)` returns sorted by SUM(duration_seconds).
- [ ] `StatsCollector::getTopMedia($limit, $since)` returns sorted by COUNT(*).
- [ ] `PlaybackController` calls `statsCollector->recordPlaybackStart()` on play and `recordPlaybackEnd()` on stop.
- [ ] ≥ 7 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 46 lines)

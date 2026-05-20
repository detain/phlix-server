# Step I.7 — Re-stream HLS to remote via hub relay: Review Checklist

## Reviewer: run these commands.

```bash
cd /home/sites/phlex

./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HlsRelay'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
grep -A5 "'relay'" config/livetv.php
ls docs/developers/live-relay.md
```

## Acceptance Criteria:

- [ ] `HlsRelaySession::getMountUrl()` returns `/relay/live/{sessionId}/playlist.m3u8`
- [ ] `HlsRelayManager::startRelaySession()` calls `tuneToChannel()` to get a stream URL
- [ ] `HlsRelayManager` persists session to `livetv_relay_sessions` table
- [ ] `HlsRelayManager::stopRelaySession()` calls `stopTuning()` and deletes DB record
- [ ] `HlsRelayManager::getActiveSessions()` returns all sessions with `last_activity_at` updated
- [ ] `HlsSegmentPrefetcher::prefetch()` downloads at least `prefetch_segments` ahead
- [ ] `HlsSegmentPrefetcher::getSegment()` returns cached content or `null` (cache miss)
- [ ] `HlsSegmentPrefetcher` uses LRU eviction when cache exceeds limit
- [ ] `RelayConsumer::registerMount()` is called by `HlsRelayManager::startRelaySession()`
- [ ] `RelayConsumer` serves `/relay/live/{sessionId}/*` requests via prefetcher cache
- [ ] `config/livetv.php` has `relay.prefetch_segments` and `relay.max_concurrent_sessions`
- [ ] ≥ 13 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS clean
- [ ] `docs/developers/live-relay.md` exists

## Non-obvious points:

- `HlsRelayManager` tracks `last_activity_at` and `bytes_relayed` for session stats.
- Prefetcher uses `Timer::add()` with `milliseconds = 2000` (check every 2s) to fetch ahead.
- LRU cache is implemented as `SplFixedSizeArray` with TTL-based eviction.
- The relay path prefix is configurable via `relay.relay_path_prefix`.
- `RelayConsumer::registerMount()` stores mount handlers in a private `array $mounts`.
- When a remote client disconnects, `HlsRelayManager` receives a notification via
  `RelayConsumer::onDisconnect()` and calls `stopRelaySession()`.

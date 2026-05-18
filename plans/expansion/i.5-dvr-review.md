# Step I.5 — Scheduled & series recordings: Review Checklist

## Reviewer: run these commands.

```bash
cd /home/sites/phlex

./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SeriesRule|RecordingDeduplicator|RecordingScheduler'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
grep -A5 "'dvr'" config/livetv.php
ls docs/developers/dvr.md
```

## Acceptance Criteria:

- [ ] `migrations/013_livetv_dvr.sql` creates `livetv_series_rules` table
- [ ] `livetv_recordings` has `series_rule_id`, `duplicate_group`,
      `pre_padding_seconds`, `post_padding_seconds` columns
- [ ] `SeriesRuleManager::createRule()` stores rule and returns it with rule_id
- [ ] `SeriesRuleManager::matchAndSchedule()` calls `getUpcomingBySeries()`
      and schedules unmatched episodes
- [ ] `RecordingDeduplicator::isDuplicate()` checks 2-hour window around start_time
- [ ] `RecordingScheduler::processDueRecordings()` iterates due recordings and
      starts those with available tuners
- [ ] `RecordingScheduler` skips recordings when no tuner free (no exception)
- [ ] `Recorder::scheduleRecording()` stores `pre/post_padding_seconds`
- [ ] Padding is applied: recording starts `pre_padding_seconds` early
- [ ] `config/livetv.php` has `dvr.default_pre_padding_seconds` = 60
- [ ] ≥ 12 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS clean
- [ ] `docs/developers/dvr.md` exists

## Non-obvious points:

- `SeriesRuleManager::matchAndSchedule()` calls `Recorder::isDuplicate()` before
  scheduling each episode to avoid duplicates during re-runs.
- `RecordingScheduler::processDueRecordings()` is called from a `Timer::add()`
  in `LiveTvManager` every 60 seconds.
- When multiple scheduled recordings start at the same time, priority order is:
  `PRIORITY_HIGH` > `PRIORITY_NORMAL` > `PRIORITY_LOW`. Within same priority,
  earliest `start_time` wins.
- `duplicate_group` is a hash of `program_id + channel_id` for fast lookup.

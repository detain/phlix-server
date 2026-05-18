# Step I.5 — Scheduled & series recordings

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.5
**Depends on:** I.4
**Review:** Yes — see `i.5-dvr-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build out the full scheduled and series DVR recording pipeline on top of the
existing `Recorder.php` framework (already has `scheduleRecording()`,
`startRecording()`, `stopRecording()`). This step adds:
- Series recording rules (record all episodes of a show)
- Recording deduplication (prevent duplicate recordings)
- Auto-recording of new episodes when paired with Schedules Direct EPG
- Pre/post-recording padding configuration
- Priority-based recording scheduler (which runs next when tuners conflict)

## 2. Context (what already exists)

- `src/LiveTv/Recorder.php` — framework with `scheduleRecording()`,
  `startRecording()`, `stopRecording()`. Status flow: `SCHEDULED →
  RECORDING → COMPLETED`. Already has `PRIORITY_*` constants and
  `activeRecordings` map.
- `src/LiveTv/GuideManager.php` — after I.4, EPG data from SD.
  `getUpcomingBySeries()` returns series episodes.
- `src/LiveTv/LiveTvManager.php` — tuner management, channel access.
- `src/Media/Streaming/HlsStreamer.php` — live stream packaging; recordings
  use the same transport stream URL as live TV.
- `config/livetv.php` — already has HDHomeRun, IPTV, DVB-T, SD config.
  I.5 adds `dvr` section.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.5 is the DVR step.
- `migrations/` — existing tables: `livetv_recordings`, `livetv_programs`.
  May need new columns for series rules and deduplication.

## 3. Scope — files to create / modify

### Create

#### New classes — DVR

- `src/LiveTv/Recording/SeriesRuleManager.php` — manages series recording rules:

  ```php
  class SeriesRuleManager
  {
      public function __construct(
          private readonly Connection $db,
          private readonly Recorder $recorder,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Create a series rule (record all future episodes). */
      public function createRule(string $seriesId, string $channelId, array $options = []): array {}

      /** Get all active rules. */
      public function getRules(): array {}

      /** Get rule by seriesId. */
      public function getRuleBySeries(string $seriesId): ?array {}

      /** Update a series rule. */
      public function updateRule(string $ruleId, array $updates): array {}

      /** Delete a series rule. */
      public function deleteRule(string $ruleId): bool {}

      /** Match upcoming EPG programs against all active rules and schedule recordings. */
      public function matchAndSchedule(): array {}
  }
  ```

- `src/LiveTv/Recording/RecordingDeduplicator.php` — prevents duplicate
  recordings within a time window:

  ```php
  class RecordingDeduplicator
  {
      public function __construct(private readonly Connection $db) {}

      /** Check if a recording for this program already exists (or is scheduled). */
      public function isDuplicate(string $programId, string $channelId, int $startTime): bool {}

      /** Find the canonical recording for a program (keeps the earliest). */
      public function getCanonical(string $programId): ?array {}

      /** Resolve duplicates: cancel lower-priority ones. */
      public function resolveDuplicates(string $preferRuleId): int {}
  }
  ```

- `src/LiveTv/Recording/RecordingScheduler.php` — decides which recording
  to start next when multiple are scheduled simultaneously:

  ```php
  class RecordingScheduler
  {
      public function __construct(
          private readonly Connection $db,
          private readonly Recorder $recorder,
          private readonly LiveTvManager $liveTvManager,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Find all due recordings and start them subject to tuner availability. */
      public function processDueRecordings(): array {}

      /** Get the next scheduled recording (for display purposes). */
      public function getNextRecording(): ?array {}

      /** Check if a tuner is available for recording. */
      private function getAvailableTuner(string $channelId): ?array {}
  }
  ```

- `src/LiveTv/Recording/RecordingHooks.php` — already exists; verify it
  calls ComskipPostProcessor after recording completes.

- `src/LiveTv/Recording/RecordingHooksRunner.php` — runs post-recording
  hooks asynchronously:

  ```php
  class RecordingHooksRunner
  {
      public function __construct(
          private readonly string $storagePath,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Enqueue post-recording processing for a completed recording. */
      public function enqueue(string $recordingId, string $filePath): void {}
  }
  ```

#### DB Migration

- `migrations/013_livetv_dvr.sql` — adds columns to `livetv_recordings` and
  creates `livetv_series_rules` table:
  ```sql
  ALTER TABLE livetv_recordings
    ADD COLUMN series_rule_id CHAR(36) NULL,
    ADD COLUMN duplicate_group CHAR(36) NULL,
    ADD COLUMN pre_padding_seconds INT NOT NULL DEFAULT 60,
    ADD COLUMN post_padding_seconds INT NOT NULL DEFAULT 60,
    ADD COLUMN scheduled_by_rule CHAR(36) NULL;

  CREATE TABLE livetv_series_rules (
    rule_id CHAR(36) PRIMARY KEY,
    series_id VARCHAR(255) NOT NULL,
    channel_id CHAR(36) NULL,
    title VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 5,
    pre_padding_seconds INT NOT NULL DEFAULT 60,
    post_padding_seconds INT NOT NULL DEFAULT 60,
    max_recordings INT NULL,  -- NULL = unlimited
    days_ahead INT NOT NULL DEFAULT 14,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_series_id (series_id),
    INDEX idx_is_active (is_active)
  );
  ```

#### Tests

- `tests/unit/LiveTv/Recording/SeriesRuleManagerTest.php`
- `tests/unit/LiveTv/Recording/RecordingDeduplicatorTest.php`
- `tests/unit/LiveTv/Recording/RecordingSchedulerTest.php`

#### Documentation

- `docs/developers/dvr.md` — series rules, deduplication, padding config,
  priority scheduler, tuner conflict resolution.

### Modify

- `src/LiveTv/Recorder.php` — add `scheduleRecording()` accepts
  `pre_padding_seconds` / `post_padding_seconds` and `series_rule_id`.
  Add `isDuplicate()` method that delegates to `RecordingDeduplicator`.
  Change `startRecording()` to consider pre-padding (start recording
  `pre_padding_seconds` early).
- `config/livetv.php` — add `dvr` section:
  ```php
  'dvr' => [
      'enabled' => true,
      'storage_path' => '/var/recordings',
      'max_storage_bytes' => 0,
      'default_pre_padding_seconds' => 60,
      'default_post_padding_seconds' => 60,
      'auto_resolution' => true,  // auto-start scheduled recordings
  ],
  ```
- `CHANGELOG.md` — add entry: "Added: scheduled + series DVR recording
  (rules, deduplication, padding, priority scheduler)".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master, branch `i.5-dvr`.
2. **DB migration.** Create `migrations/013_livetv_dvr.sql` with new
   columns and the series_rules table.
3. **Series rule manager.** CRUD for recording rules; `matchAndSchedule()`
   queries `GuideManager::getUpcomingBySeries()` for each active rule and
   calls `Recorder::scheduleRecording()` for unmatched episodes.
4. **Deduplicator.** `isDuplicate()` queries `livetv_recordings` for
   `duplicate_group` (same program + channel) and checks status. Uses
   2-hour window by default.
5. **Scheduler.** `processDueRecordings()` runs every minute (called from
   a Workerman timer in `LiveTvManager`). Finds all `SCHEDULED` recordings
   where `start_time <= now`. For each, checks tuner availability via
   `LiveTvManager` and calls `Recorder::startRecording()`. If no tuner is
   free, skips and logs.
6. **Padding.** `Recorder::scheduleRecording()` stores `pre_padding_seconds`
   and `post_padding_seconds`. `startRecording()` reads `pre_padding` and
   starts the stream that many seconds early.
7. **Comskip wiring.** `RecordingHooks` already exists; ensure
   `ComskipPostProcessor::process()` is called after `stopRecording()`.
8. **Tests.** Three test files per §5.
9. **Verification bar.**
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED)

Unit tests (coverage ≥ 85 % on every new class):

1. `SeriesRuleManagerTest::test_create_rule_inserts_and_returns_rule`
2. `SeriesRuleManagerTest::test_get_rules_returns_active_rules`
3. `SeriesRuleManagerTest::test_get_rule_by_series_returns_rule`
4. `SeriesRuleManagerTest::test_update_rule_modifies_fields`
5. `SeriesRuleManagerTest::test_delete_rule_removes_rule`
6. `SeriesRuleManagerTest::test_match_and_schedule_creates_recordings`
7. `RecordingDeduplicatorTest::test_is_duplicate_returns_true_for_existing`
8. `RecordingDeduplicatorTest::test_is_duplicate_returns_false_for_new`
9. `RecordingDeduplicatorTest::test_resolve_duplicates_cancels_lower_priority`
10. `RecordingSchedulerTest::test_process_due_recordings_starts_available`
11. `RecordingSchedulerTest::test_process_due_recordings_skips_when_no_tuner`
12. `RecordingSchedulerTest::test_get_next_recording_returns_due`

**Coverage target:** `SeriesRuleManager` ≥ 85 %, `RecordingDeduplicator` ≥ 85 %,
`RecordingScheduler` ≥ 80 %.

## 6. Documentation

Matrix rows that apply:
- **"Anything"** → `docs/developers/dvr.md` covers series rules, padding,
  conflict resolution, deduplication.
- **"New public class/method"** → all new classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria

- [ ] `migrations/013_livetv_dvr.sql` creates `livetv_series_rules` table
      and adds `series_rule_id`, `duplicate_group`, `pre/post_padding_seconds`
      to `livetv_recordings`.
- [ ] `SeriesRuleManager::createRule()` creates a series rule and returns it.
- [ ] `SeriesRuleManager::matchAndSchedule()` finds upcoming episodes for a
      series via `GuideManager::getUpcomingBySeries()` and schedules them.
- [ ] `RecordingDeduplicator::isDuplicate()` returns `true` for a recording
      with the same `program_id` + `channel_id` within a 2-hour window.
- [ ] `RecordingScheduler::processDueRecordings()` starts due recordings
      respecting tuner availability.
- [ ] `RecordingScheduler::processDueRecordings()` skips when no tuner is free.
- [ ] `Recorder::scheduleRecording()` accepts `pre_padding_seconds` and
      `post_padding_seconds` and stores them.
- [ ] `config/livetv.php` has `dvr` key with `default_pre/post_padding_seconds`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage targets met.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/dvr.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b i.5-dvr
php scripts/run-migrations.php
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SeriesRule|RecordingDeduplicator|RecordingScheduler'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step I.5: Scheduled + series DVR recordings"
unset GITHUB_TOKEN
gh pr create \
  --title "Step I.5 (Live TV): Scheduled + series DVR recordings" \
  --body  "Adds DVR series rules, deduplication, pre/post-padding, priority scheduler. Part of Phase I (Step I.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git log --oneline -1 && git branch --list 'i.5-*'
```

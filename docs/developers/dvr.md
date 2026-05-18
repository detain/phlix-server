# DVR Developer Guide

## Overview

The Phlex DVR system provides scheduled and series-based recording capabilities
built on top of the existing `Recorder.php` framework. This guide covers the
architecture, configuration, and integration points.

## Architecture

### Core Components

1. **SeriesRuleManager** (`src/LiveTv/Recording/SeriesRuleManager.php`)
   - CRUD operations for series recording rules
   - `matchAndSchedule()` - queries upcoming EPG data and schedules recordings

2. **RecordingDeduplicator** (`src/LiveTv/Recording/RecordingDeduplicator.php`)
   - Prevents duplicate recordings within a 2-hour time window
   - `isDuplicate()` - checks if program already scheduled/recorded
   - `resolveDuplicates()` - cancels lower-priority duplicates

3. **RecordingScheduler** (`src/LiveTv/Recording/RecordingScheduler.php`)
   - Priority-based conflict resolution for tuner allocation
   - `processDueRecordings()` - runs every minute via Workerman timer
   - `getNextRecording()` - returns upcoming recording for display

4. **RecordingHooksRunner** (`src/LiveTv/Recording/RecordingHooksRunner.php`)
   - Async post-recording hook execution (Comskip, etc.)

## Database Schema

### livetv_series_rules table

```sql
CREATE TABLE livetv_series_rules (
    rule_id CHAR(36) PRIMARY KEY,
    series_id VARCHAR(255) NOT NULL,
    channel_id CHAR(36) NULL,           -- NULL = any channel
    title VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 5,     -- 1=low, 5=normal, 10=high
    pre_padding_seconds INT NOT NULL DEFAULT 60,
    post_padding_seconds INT NOT NULL DEFAULT 60,
    max_recordings INT NULL,            -- NULL = unlimited
    days_ahead INT NOT NULL DEFAULT 14,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_series_id (series_id),
    INDEX idx_is_active (is_active),
    INDEX idx_channel_id (channel_id)
);
```

### livetv_recordings additions

```sql
ALTER TABLE livetv_recordings ADD COLUMN (
    series_rule_id CHAR(36) NULL,
    duplicate_group CHAR(36) NULL,
    pre_padding_seconds INT NOT NULL DEFAULT 60,
    post_padding_seconds INT NOT NULL DEFAULT 60,
    scheduled_by_rule CHAR(36) NULL
);
```

## Configuration

### config/livetv.php - DVR section

```php
'dvr' => [
    'enabled' => true,
    'storage_path' => '/var/recordings',
    'max_storage_bytes' => 0,                    // 0 = unlimited
    'default_pre_padding_seconds' => 60,         // Start 60s early
    'default_post_padding_seconds' => 60,         // End 60s late
    'auto_resolution' => true,                   // Auto-start when tuner free
],
```

## Series Rule Management

### Creating a Series Rule

```php
$ruleManager->createRule('series_tms_id', 'channel_id', [
    'title' => 'My Favorite Show',
    'priority' => Recorder::PRIORITY_NORMAL,
    'pre_padding_seconds' => 120,    // 2 minutes pre-padding
    'post_padding_seconds' => 60,   // 1 minute post-padding
    'max_recordings' => 10,         // Keep last 10 episodes
    'days_ahead' => 14,            // Schedule 14 days ahead
]);
```

### Scheduling Recordings from Rules

```php
// Called periodically (e.g., every hour via Workerman timer)
$guideManager = $container->get(GuideManager::class);
$stats = $ruleManager->matchAndSchedule($guideManager);

echo "Scheduled: {$stats['scheduled']}, "
   . "Skipped: {$stats['skipped']}, "
   . "Errors: {$stats['errors']}";
```

## Conflict Resolution

When multiple recordings are due simultaneously:

1. **Priority sorting** - Higher priority rules record first
2. **Start time** - Earlier start_time wins tiebreaker
3. **Tuner availability** - If no tuner free, recording is skipped

### Tuner Conflict Example

```
Rule A (priority=10): "News at 6" - Channel 4, 6:00 PM
Rule B (priority=5):  "Movie" - Channel 4, 6:00 PM

If only 1 tuner:
  -> Rule A wins, Movie is skipped (or rescheduled)
If 2 tuners:
  -> Both record simultaneously
```

## Pre/Post Padding

Recordings automatically start and end with configurable padding:

```
Actual Program: 6:00 PM - 7:00 PM
Pre-padding: 2 minutes  -> Recording starts at 5:58 PM
Post-padding: 1 minute   -> Recording ends at 7:01 PM
```

The `startRecording()` method applies pre-padding:
```php
$effectiveStart = $recording['start_time'] - $recording['pre_padding_seconds'];
```

## Deduplication

The `RecordingDeduplicator` prevents recording the same episode twice:

- Uses 2-hour time window by default
- Groups recordings via `duplicate_group` hash (MD5 of program_id + channel_id)
- `isDuplicate()` called before scheduling new recordings
- `resolveDuplicates()` cancels lower-priority recordings in same group

### Manual Deduplication

```php
$deduplicator->resolveDuplicates('prefer_rule_id');
```

## Scheduler Integration

The `RecordingScheduler` should be registered with a Workerman timer:

```php
// In your Application bootstrap
use Workerman\Timer;

$scheduler = $container->get(RecordingScheduler::class);

// Run every 60 seconds
Timer::add(60, function () use ($scheduler) {
    $stats = $scheduler->processDueRecordings();
    if ($stats['started'] > 0 || $stats['skipped'] > 0) {
        echo "DVR: {$stats['started']} started, {$stats['skipped']} skipped\n";
    }
});
```

## Comskip Integration

Post-recording hooks are already wired via `RecordingHooks`:

```php
// In your bootstrap
$comskipProcessor = $container->get(ComskipPostProcessor::class);
RecordingHooks::register($recorder, $comskipProcessor);
```

When a recording completes:
1. `Recorder::stopRecording()` fires `onComplete` callbacks
2. `ComskipPostProcessor::processRecording()` is called
3. Comskip runs on the .ts file, generates .edl
4. Commercial chapters stored via `MarkerService`

## API Endpoints (Future)

Planned REST endpoints for series rules:

```
POST   /api/v1/dvr/rules              - Create series rule
GET    /api/v1/dvr/rules              - List all rules
GET    /api/v1/dvr/rules/{id}         - Get specific rule
PUT    /api/v1/dvr/rules/{id}         - Update rule
DELETE /api/v1/dvr/rules/{id}         - Delete rule
POST   /api/v1/dvr/rules/{id}/match   - Trigger manual match
GET    /api/v1/dvr/recordings         - List recordings
GET    /api/v1/dvr/recordings/{id}    - Get recording details
DELETE /api/v1/dvr/recordings/{id}    - Cancel/delete recording
```

## Testing

Run the DVR unit tests:

```bash
./vendor/bin/phpunit tests/unit/LiveTv/Recording/
```

Coverage targets:
- `SeriesRuleManager` ≥ 85%
- `RecordingDeduplicator` ≥ 85%
- `RecordingScheduler` ≥ 80%

## Migration

After updating the codebase, run migrations:

```bash
php scripts/run-migrations.php
```

This applies `migrations/013_livetv_dvr.sql` which:
1. Adds series_rule_id, duplicate_group, pre/post_padding to livetv_recordings
2. Creates livetv_series_rules table

## Error Handling

All components log errors via PSR-3 logger:

```php
$logger->error('Failed to schedule recording', [
    'rule_id' => $ruleId,
    'program_id' => $programId,
    'error' => $e->getMessage(),
]);
```

Common failure scenarios:
- No tuner available (tuner conflict)
- Insufficient storage space
- Program no longer exists in guide
- Database connection failure

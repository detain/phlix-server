# Comskip Commercial Skip for Live TV Recordings

**Since:** 0.12.0
**Phase:** I.6 (Live TV / DVR / IPTV)

## Overview

Comskip is a third-party C application for detecting commercial breaks in video recordings. This integration automatically processes completed Live TV recordings through Comskip after they finish, storing detected commercial segments as chapter markers that clients can use for automatic commercial skipping.

## How It Works

1. A Live TV recording completes via `Recorder::stopRecording()`
2. The `ComskipLifecycleManager::enqueue()` callback is fired via `onComplete()`
3. Comskip is run on the recording file, producing an EDL (Edit Decision List)
4. The EDL is parsed to extract commercial segments
5. Commercial data is stored in `livetv_recordings.commercial_*` columns
6. Chapter markers are persisted to the media item's `metadata_json`

## EDL Format

Comskip outputs EDL files with 3 tab-separated columns:

```
start_seconds  end_seconds  scene_description
```

The `scene_description` field is a type indicator:
- `0` = cut
- `1` = mute
- `2` = scene change
- `3` = commercial (main detection type)

Example EDL content:
```
0.0     30.0    3
60.0    120.0   3
180.0   210.0   3
```

## HLS Chapter Marker Format

EDL segments are converted to HLS chapter markers stored in `media_items.metadata_json`:

```json
{
  "commercial_chapters": [
    {"start": 0, "end": 30, "title": "Commercial @ 00:00:00 (30s)"},
    {"start": 60, "end": 120, "title": "Commercial @ 00:01:00 (60s)"}
  ]
}
```

## Configuration

Add to `config/livetv.php`:

```php
'comskip' => [
    'enabled' => true,
    'binary_path' => '/usr/bin/comskip',
    'ini_path' => '/etc/comskip/comskip.ini',
    'output_dir' => '/var/recordings/edl',
    'queue_processing' => true,
    'max_concurrent' => 2,
],
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `true` | Enable Comskip commercial detection |
| `binary_path` | string | `/usr/bin/comskip` | Path to comskip binary |
| `ini_path` | string | `/etc/comskip/comskip.ini` | Path to comskip.ini config |
| `output_dir` | string | `/var/recordings/edl` | Directory for EDL files |
| `queue_processing` | bool | `true` | Process asynchronously via queue |
| `max_concurrent` | int | `2` | Max concurrent Comskip processes |

## Database Schema

New columns added to `livetv_recordings`:

```sql
ALTER TABLE livetv_recordings
    ADD COLUMN commercial_processed_at DATETIME NULL,
    ADD COLUMN commercial_edl_path VARCHAR(512) NULL,
    ADD COLUMN commercial_frame_count INT NULL,
    ADD COLUMN commercial_duration_seconds INT NULL;
```

## API Classes

### ComskipIntegration

Wires Comskip into the recording lifecycle.

```php
class ComskipIntegration
{
    public function processRecording(string $recordingId, string $filePath): array;
    public function getEdlSegments(string $recordingId): array;
    public function markProcessed(string $recordingId): void;
}
```

### ComskipLifecycleManager

Manages the lifecycle of Comskip processing (queue, retry, completion).

```php
class ComskipLifecycleManager
{
    public function enqueue(string $recordingId, string $filePath): void;
    public function processNext(): bool;
    public function getPendingCount(): int;
}
```

### ChapterMarkerService

Converts EDL segments into HLS chapter markers.

```php
class ChapterMarkerService
{
    public function toHlsChapters(array $edlSegments): array;
    public function persistChapters(string $mediaItemId, array $edlSegments): void;
    public function getChapters(string $mediaItemId): array;
}
```

## Integration with Recorder

The `ComskipLifecycleManager` is wired into `Recorder` via the `onComplete()` callback:

```php
// In Recorder constructor
if ($comskipManager !== null) {
    $this->onCompleteCallbacks[] = function (string $recordingId, string $filePath) use ($comskipManager): void {
        $comskipManager->enqueue($recordingId, $filePath);
    };
}
```

## Requirements

- Comskip binary installed at the configured path
- Sufficient disk space for EDL output directory
- Adequate system resources for concurrent Comskip processing

## Troubleshooting

### Comskip not running

Check that:
1. The `comskip` binary exists at `binary_path`
2. The binary is executable (`chmod +x`)
3. The `enabled` config option is `true`

### EDL file not generated

Check that:
1. The recording file exists and is readable
2. The `output_dir` is writable
3. Comskip has sufficient permissions to run

### Queue not processing

Check that:
1. `queue_processing` is `true` in config
2. `processNext()` is being called periodically
3. `max_concurrent` is not blocking all processing

# Schedules Direct EPG Integration

**Since:** 0.12.0

Schedules Direct (https://www.schedulesdirect.org) provides authoritative TV guide data
including program listings, series info, artwork, and ratings. This integration allows
Phlix to sync EPG data from SD's JSON API.

## Overview

The SD EPG integration is implemented as an optional LiveTV subsystem that can sync
program guide data after channel discovery. It requires a valid SD account subscription.

### Architecture

```
SdApiClient          — HTTP JSON client, token auth, BASE_URL: https://api.schedulesdirect.tmsglobal.com
SdLineupHandler      — Fetches SD lineups, imports channels via ChannelManager
SdProgramMapper      — Maps SD schedule/program data to GuideManager::upsertProgram() format
SdEpgService        — Orchestrates full sync: fetch schedules, programs, upsert to guide
SdEpgServiceFactory — Builds service from config with token caching to filesystem
```

## Authentication

SD uses token-based authentication (Bearer token in Authorization header). Tokens
can be:

1. **Pre-seeded** — Token stored at `token_cache_path` from a previous session
2. **Auto-fetched** — Credentials in config used to obtain token via HTTP Basic Auth

Token caching: Tokens are cached with a 23-hour TTL and refreshed automatically.

### Obtaining Credentials

1. Create an account at https://www.schedulesdirect.org
2. Subscription required (typically ~$25/year)
3. Add username/password to `config/livetv.php` under `schedules_direct`

## Configuration

Add to `config/livetv.php`:

```php
'schedules_direct' => [
    'enabled' => true,
    'username' => 'your_sd_username',
    'password' => 'your_sd_password',
    'token_cache_path' => '/var/phlix/sd_token.json',
    'lineup_id' => null,           // null = auto-detect, or set 'USA-OTA-XXXXX'
    'sync_hours_ahead' => 336,      // 14 days (SD limit)
    'timeout_secs' => 30,
],
```

## API Endpoints Used

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/token` | Validate token |
| POST | `/token` | Obtain token (HTTP Basic Auth) |
| GET | `/lineups` | List available lineups |
| GET | `/headend/{systemId}/station` | Get stations in a lineup |
| POST | `/schedules/md5` | Get schedule MD5 hashes (change detection) |
| POST | `/schedules` | Get full schedule data |
| POST | `/programs` | Get program metadata |

## Data Model

### SD Schedule Entry

```json
{
  "stationID": "station123",
  "programID": "EP0012345678901",
  "airDateTime": "2024-01-15T14:00:00Z",
  "duration": 60,
  "isRepeat": false
}
```

### SD Program Data

```json
{
  "programID": "EP0012345678901",
  "title": "Program Title",
  "description": "Program description",
  "entityType": "episode",
  "seasonNumber": 1,
  "episodeNumber": 5,
  "genres": ["Drama", "Series"],
  "contentRating": ["TV-PG"],
  "originalAirDate": "2024-01-15"
}
```

## Program ID Generation

Program IDs are generated deterministically from channel ID + start time:

```
program_id = md5(channel_id . '_' . start_timestamp)
```

This ensures the same program slot always gets the same ID, enabling
efficient upserts without duplicates.

## Channel Mapping

SD stations are mapped to Phlix channels with:

- `name` → callSign
- `number` → channelNumber / logicalChannelNumber
- `tuner_id` → `sd_` + stationID
- `service_id` → stationID
- `icon_url` → logo.URL

## Usage

### Via LiveTvManager

```php
// After construction, set SD config
$manager->setSdConfig($config['schedules_direct']);

// Run EPG sync (auto-builds SdEpgService)
$stats = $manager->syncSdEpG(daysAhead: 14);
echo "Imported: {$stats['imported']}, Errors: {$stats['errors']}";
```

### Direct Service Usage

```php
use Phlix\LiveTv\Epg\SchedulesDirect\SdEpgServiceFactory;

$service = SdEpgServiceFactory::build(
    $config['schedules_direct'],
    $channelManager,
    $guideManager
);

$stats = $service->syncEpg(['station1', 'station2'], 14);
```

### Lineup Import + Sync

```php
$result = $service->importLineupAndSync('USA-OTA-00000', 14);
// $result = ['channels' => [...], 'stats' => ['imported' => N, 'errors' => M]]
```

## Program Mapping

SD program data is mapped to GuideManager categories:

| SD Genre | GuideManager Category |
|----------|----------------------|
| Movie | CATEGORY_MOVIE |
| Sports | CATEGORY_SPORTS |
| News | CATEGORY_NEWS |
| Children/Kids | CATEGORY_KIDS |
| Music | CATEGORY_MUSIC |
| Education | CATEGORY_EDUCATION |
| Series/Episode | CATEGORY_SERIES |
| (default) | CATEGORY_OTHER |

## Error Handling

- **401 Unauthorized** — Token invalid/expired; factory will attempt re-fetch
- **Empty schedule data** — Returns `['imported' => 0, 'errors' => 0]`
- **Partial failures** — Individual program failures counted in `errors`
- **Network failures** — Logged and returned as errors

## Rate Limiting

SD recommends limiting requests to ~1 per second for bulk operations.
The client does not implement rate limiting; stagger sync calls if needed.

## Token Refresh

Tokens are cached at `token_cache_path` as JSON:

```json
{
  "token": "abcdef...",
  "cached_at": 1705334400,
  "expires_at": 1705420800
}
```

The `expires_at` is set to 23 hours from fetch time. The factory
will automatically re-fetch when the cached token expires.

## Troubleshooting

### "No SD token available"

1. Verify credentials in `config/livetv.php`
2. Check `token_cache_path` is writable
3. Verify network connectivity to `api.schedulesdirect.tmsglobal.com`

### "No stations found for SD lineup"

1. Verify lineup ID is correct for your region
2. Try `null` lineup_id to auto-detect available lineups
3. Check SD account has active subscription

### Empty guide data after sync

1. Verify stations were imported (check livetv_channels table)
2. Confirm `tuner_id` prefix is `sd_` for SD channels
3. Check sync returned `imported > 0`

## Security Considerations

- Store SD password securely (encrypted at rest in production)
- Token cache file should be readable only by the application
- Tokens grant access to SD data; treat similarly to API keys

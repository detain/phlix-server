# IPTV Tuner Driver

## Overview

The IPTV tuner driver enables ingestion of IPTV streams via M3U playlists and optional XMLTV guide data (EPG). It makes IPTV streams available alongside HDHomeRun/DVB-T tuners in the unified `LiveTvManager` pipeline.

## Architecture

```
LiveTvManager
├── HdHomeRunTunerDriver (SSDP discovery + HTTP API)
└── IptvTunerDriver (M3U playlist + XMLTV)
    ├── M3UParser (parses #EXTINF entries)
    ├── XmlTvParser (parses <programme> elements)
    ├── IptvDevice (source descriptor)
    └── IptvTunerDriverFactory (builds from config)
```

## M3U Playlist Format

The driver supports extended M3U format with `#EXTINF` tags:

```
#EXTM3U
#EXTINF:-1 tvg-id="1" tvg-name="Channel Name" tvg-chno="5" group-title="News",Channel Name
http://example.com/stream.m3u8
#EXTINF:-1 radio="1" tvg-id="2" tvg-name="Radio FM" tvg-chno="100",Radio FM
http://example.com/radio.m3u8
```

### Supported Attributes

| Attribute | Description | Example |
|-----------|-------------|---------|
| `tvg-id` | Unique channel identifier | `tvg-id="123"` |
| `tvg-name` | Channel display name | `tvg-name="BBC One"` |
| `tvg-chno` | Channel number | `tvg-chno="1"` |
| `group-title` | Channel category/group | `group-title="News"` |
| `tvg-logo` | Channel logo URL | `tvg-logo="https://..."` |
| `radio` | Radio channel flag | `radio="1"` |

## XMLTV Format

The driver parses XMLTV-ng format for programme guide data:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme channel="bbc_one" start="20240101180000" stop="20240101200000">
    <title>Doctor Who</title>
    <desc>The Doctor returns.</desc>
    <category>Science Fiction</category>
    <episode-num system="onscreen">S01E05</episode-num>
    <rating>
      <value>TV-PG</value>
    </rating>
    <date>20240101</date>
  </programme>
</tv>
```

### Time Format

XMLTV uses `YYYYMMDDHHMMSS` format with optional timezone offset:
- `20240101120000` - No timezone (UTC assumed)
- `20240101120000 +0000` - UTC
- `20240101120000 -0500` - EST

## Configuration

Add IPTV sources to `config/livetv.php`:

```php
'livetv' => [
    // ... existing hdhomerun config ...

    'iptv' => [
        'enabled' => true,
        'sources' => [
            [
                'name' => 'My IPTV',
                'playlist_url' => 'https://example.com/playlist.m3u8',
                'epg_url' => 'https://example.com/epg.xml',
            ],
            // Add more sources as needed
        ],
    ],
],
```

## Class Reference

### M3UEntry

Immutable value object for a single M3U playlist entry.

```php
new M3UEntry(
    url: 'http://example.com/stream.m3u8',
    name: 'Channel Name',
    tvgId: 1,
    tvgChno: 5,
    group: 'News',
    logo: 'http://example.com/logo.png',
    isRadio: false
);
```

### M3UParser

Parses M3U/M3U8 playlist files.

```php
$parser = new M3UParser();

// Parse from string
$entries = $parser->parse($m3uContent);

// Fetch and parse from URL
$entries = $parser->parseUrl('https://example.com/playlist.m3u8');
```

### XmlTvProgramme

Immutable value object for a programme entry.

```php
new XmlTvProgramme(
    channelId: 'bbc_one',
    startTime: 1704124800,
    endTime: 1704132000,
    title: 'Doctor Who',
    description: 'The Doctor returns.',
    category: 'Science Fiction',
    episodeNum: 'S01E05',
    rating: 'TV-PG',
    year: 2024
);
```

### XmlTvParser

Parses XMLTV format guide data.

```php
$parser = new XmlTvParser();

// Parse from string
$programmes = $parser->parse($xmltvContent);

// Fetch and parse from URL
$programmes = $parser->parseUrl('https://example.com/epg.xml');
```

### IptvDevice

Immutable descriptor for an IPTV source.

```php
$device = new IptvDevice(
    sourceId: 'iptv_mysource',
    name: 'My IPTV Service',
    playlistUrl: 'https://example.com/playlist.m3u8',
    epgUrl: 'https://example.com/epg.xml',
    isEnabled: true
);
```

### IptvTunerDriver

Implements `TunerDriverInterface` for IPTV streaming.

```php
$driver = new IptvTunerDriver(
    new M3UParser(),
    new XmlTvParser(),
    $device
);

// Discover devices
$devices = $driver->discoverDevices();

// Get channel lineup
$lineup = $driver->getChannelLineup($device);

// Get stream URL for channel
$url = $driver->getStreamUrl($device, 5);

// Scan channels (also fetches EPG if configured)
$channels = $driver->scanChannels($device);
```

### IptvTunerDriverFactory

Factory for creating drivers from configuration.

```php
// Build single driver from first source
$driver = IptvTunerDriverFactory::build($config);

// Get all configured devices
$devices = IptvTunerDriverFactory::buildDevices($config);
```

## Database Schema

The `livetv_tuners` table stores tuner information with type `iptv`:

| Column | Type | Description |
|---------|------|-------------|
| tuner_id | VARCHAR(36) | Unique identifier (e.g., `iptv_mysource`) |
| name | VARCHAR(255) | Display name |
| type | VARCHAR(20) | Tuner type (`iptv`, `hdhomerun`) |
| status | VARCHAR(20) | Current status |
| capabilities | JSON | Tuner capabilities |
| discovered_at | DATETIME | Discovery timestamp |

## Integration with LiveTvManager

LiveTvManager automatically discovers and registers IPTV tuners when configured:

```php
// In your service provider or bootstrap
$livetvConfig = include 'config/livetv.php';

// Create IPTV driver if enabled
$iptvDriver = IptvTunerDriverFactory::build($livetvConfig, $logger);

// Create LiveTvManager with additional drivers
$manager = new LiveTvManager(
    $db,
    $channelManager,
    $guideManager,
    $recorder,
    $hdhomerunDriver,  // Primary driver
    $logger,
    [$iptvDriver]       // Additional drivers array
);

// Discover all tuners (both HDHomeRun and IPTV)
$tuners = $manager->discoverTuners();
```

## EPG Matching

When `GuideManager::upsertProgram()` is called with `xmltv_id`, it can match programme entries to channels by their XMLTV channel ID:

```php
$guideManager->upsertProgram([
    'channel_id' => 'ch_123',
    'xmltv_id' => 'bbc_one',  // Matches XMLTV programme channel
    'title' => 'Doctor Who',
    'start_time' => 1704124800,
    'end_time' => 1704132000,
]);
```

## Error Handling

The driver handles common error cases:

- **Invalid M3U content**: Returns empty array, logs warning
- **Invalid XMLTV content**: Returns empty array, logs warning
- **Failed HTTP fetch**: Throws `RuntimeException` with details
- **Channel not found**: Falls back to index-based matching
- **No channels available**: Throws `RuntimeException`

## Testing

Run the IPTV tuner tests:

```bash
./vendor/bin/phpunit tests/unit/LiveTv/Tuners/Iptv/
```

## See Also

- [HDHomeRun Tuner Driver](hdhomerun.md)
- [Live TV Configuration](../reference/livetv.md)
- [Channel Manager](../reference/channel-manager.md)
- [Guide Manager](../reference/guide-manager.md)

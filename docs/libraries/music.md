# Music Library Documentation

**Since:** 0.14.0

## Overview

Phlex supports music library browsing with ID3v2/MP4/Vorbis tag harvesting.
Music files are scanned, tagged, and made available via both a REST API and
a web portal interface.

## Supported Audio Formats

| Format | Extension | Tag Format | Notes |
|--------|-----------|------------|-------|
| MPEG-1/2/2.5 Audio Layer III | `.mp3` | ID3v2.3 / ID3v2.4 | Most common lossy format |
| Free Lossless Audio Codec | `.flac` | Vorbis Comment | Lossless, supported |
| MPEG-4 Audio | `.m4a`, `.aac` | MP4 atoms (iTunes-style) | AAC lossy or ALAC lossless |
| Ogg Vorbis | `.ogg`, `.oga` | Vorbis Comment | Open, royalty-free |
| Opus | `.opus` | Vorbis Comment | Interactive web audio |
| Waveform Audio | `.wav` | RIFF fmt chunk | Basic, limited tags |
| Windows Media Audio | `.wma` | ASF | Limited support |

## Tag Field Mapping

The audio scanner harvests the following tag fields:

| Tag Field | ID3v2 Frame | Vorbis Comment | MP4 Atom |
|-----------|-------------|----------------|----------|
| `title` | `TIT2` | `TITLE` | `\xA9nam` |
| `artist` | `TPE1` | `ARTIST` | `\xA9ART` |
| `album` | `TALB` | `ALBUM` | `\xA9alb` |
| `album_artist` | `TPE2` | `ALBUMARTIST` | `\xA9aART` |
| `year` | `TYER` / `TDRC` | `DATE` / `YEAR` | `\xA9day` |
| `genre` | `TCON` | `GENRE` | `\xA9gen` |
| `track_number` | `TRCK` | `TRACKNUMBER` | `\xA9trkn` |
| `disc_number` | `TPOS` | `DISCNUMBER` | `\xA9disk` |
| `composer` | `TCOM` | `COMPOSER` | `\xA9wrt` |
| `comment` | `COMM` | `COMMENT` | `\xA9cmt` |
| `duration_secs` | Calculated | Calculated | Calculated |
| `bitrate` | From frame header | N/A | From stsd atom |
| `sample_rate` | From frame header | N/A | From mp4a atom |
| `channels` | From frame header | N/A | From mp4a atom |

## Naming Conventions

The scanner uses file naming conventions to extract metadata when tags are
missing or incomplete:

### Track Files
- `01 - Track Name.mp3` — Track number and title
- `Track Name.mp3` — Title only
- `Artist - Album - 01 - Track Name.flac` — Full path-based metadata

### Album Organization
```
/music/
└── Artist Name/
    └── Album Name (Year)/
        ├── 01 - Track One.mp3
        ├── 02 - Track Two.mp3
        └── cover.jpg
```

## Scan and Rescan Behavior

### Initial Scan
When a music library is first created or rescanned:

1. **Discovery** — Scanner finds all audio files matching supported extensions
2. **Tag Harvesting** — Each file is parsed for ID3v2/Vorbis/MP4 tags
3. **Metadata Enrichment** — MusicBrainz/AudioDB providers enrich missing data
4. **Upsert** — Items are created or updated in the database

### Rescan
A rescan updates existing items with fresh tag data and re-enriches metadata
from providers. Items that no longer exist on disk are marked as unavailable.

### Generator-based Processing
`AudioScanner::scanMusicLibrary()` uses a PHP Generator to process tracks one
at a time, avoiding memory issues with large libraries (10,000+ tracks).

## Metadata Provider Priority

Music items are enriched using a provider cascade:

1. **MusicBrainz** — Primary source; requires User-Agent header per their
   requirements, rate-limited to 1 request/second.
2. **AudioDB** — Fallback; provides album artwork and additional data.
3. **Local Tags** — Always used as the base; providers add missing data.

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/music/artists` | GET | List all artists |
| `/music/artists/{mbid}` | GET | Get artist details with albums |
| `/music/albums` | GET | List all albums |
| `/music/albums/{mbid}` | GET | Get album details with track listing |
| `/music/tracks` | GET | List all tracks (paginated) |
| `/music/tracks/{id}` | GET | Get single track details |
| `/music/now-playing` | GET | Get current playback state |

## Library Type Plugin

Music is registered as a library type via `MusicLibraryType` implementing
`LibraryTypeInterface`. This allows the system to:

- Return the correct scanner (`AudioScanner`) for music libraries
- Route library operations to `MusicLibraryManager`

```php
// In library type registry
final class MusicLibraryType implements LibraryTypeInterface
{
    public const TYPE = 'music';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getLabel(): string
    {
        return 'Music';
    }

    public function getScanner(
        Connection $db,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): AudioScanner {
        return new AudioScanner($db, $itemRepo, $logger);
    }
}
```

## Database Schema

Music items are stored in the existing `media_items` table with `type = 'track'`.

### Required Indexes

The following indexes improve music library query performance:

```sql
-- Efficient library + type queries
CREATE INDEX idx_media_items_library_type ON media_items (library_id, type);

-- Artist/album lookups from metadata JSON
CREATE INDEX idx_media_items_metadata_artist
    ON media_items ((CAST(metadata_json->>'$.artist' AS CHAR(255))));

CREATE INDEX idx_media_items_metadata_album
    ON media_items ((CAST(metadata_json->>'$.album' AS CHAR(255))));
```

These indexes are created automatically by migration `011_music_library.sql`.

## Web Portal Views

The web portal provides Smarty templates for music browsing:

- `music/artists.tpl` — Artist grid view
- `music/artist.tpl` — Artist detail with album list
- `music/albums.tpl` — Album grid view
- `music/album.tpl` — Album detail with track listing
- `music/tracks.tpl` — Paginated track list
- `music/player.tpl` — Embedded music player

## Configuration

No additional configuration is required. Music scanning uses built-in
pure-PHP tag parsers that work out of the box.

### Optional: Metadata Provider API Keys

For enhanced metadata enrichment:

```php
// config/music_providers.php
return [
    'musicbrainz' => [
        'enabled' => true,
        'rate_limit' => 1, // requests per second
        'user_agent' => 'Phlex/1.0 (https://phlex.example.com)',
    ],
    'audiodb' => [
        'enabled' => true,
        'api_key' => 'YOUR_API_KEY', // Optional
    ],
];
```

## Adding New Audio Formats

To add support for a new audio format:

1. Add extension to `AudioScanner::AUDIO_EXTENSIONS`
2. Implement `harvest{FORMAT}Tags()` method
3. Add case to `harvestTags()` switch statement
4. Add test cases for the new format

## Known Limitations

- WMA/ASF parsing is basic; duration only
- DRM-protected files are not supported
- Album artwork extraction is not yet implemented
- ReplayGain tags are not parsed

# Trailers and Extras

**Phase:** H.5
**Since:** 0.14.0

## Overview

This document describes the trailers and extras support in Phlix, including local `Trailers/` folder support, `-trailer.mkv` naming conventions, and TMDB trailer URL integration.

## Local Trailer Discovery

Phlix scans media directories for trailers in two locations:

### Same-Level Trailers

Trailers can be placed at the same level as the main media file:

```
Movies/
  Avatar (2009)/
    Avatar (2009).mkv
    Avatar (2009)-trailer.mkv        ← same-level trailer
```

### Trailers/ Subfolder

Trailers can also be placed in a `Trailers/` subfolder:

```
Movies/
  Avatar (2009)/
    Avatar (2009).mkv
    Trailers/
      Avatar (2009)-teaser.mkv
      Avatar (2009)-official-trailer.mkv
```

## Naming Conventions

The suffix of the trailer file is extracted as the display title:

| Suffix | Display Title |
|--------|---------------|
| `-trailer` | Trailer |
| `-teaser` | Teaser |
| `-clip` | Clip |
| `-featurette` | Featurette |
| `-behind-the-scenes` | Behind the Scenes |
| `-interview` | Interview |
| `-deleted-scene` | Deleted Scene |

### Supported File Extensions

- mkv, mp4, avi, mov, wmv, flv, webm, m4v, mpg, mpeg, ts

## API Endpoints

### Get All Extras

```
GET /api/v1/media/{id}/extras
```

Returns all trailers and extras (merged, sorted by type priority).

**Response:**
```json
{
  "extras": [
    {
      "id": "...",
      "media_item_id": "...",
      "title": "Official Trailer",
      "source": "local",
      "url": "file:///path/to/trailer.mkv",
      "duration": 120,
      "quality": 1080,
      "is_local": true,
      "file_path": "/path/to/trailer.mkv"
    }
  ],
  "count": 1
}
```

### Get Trailers Only

```
GET /api/v1/media/{id}/trailers
```

Returns only trailers (not other extras).

### Get Non-Trailer Extras

```
GET /api/v1/media/{id}/extras/other
```

Returns extras that are not trailers (featurettes, behind the scenes, etc.).

## Data Model

### Trailer DTO

```php
final readonly class Trailer
{
    public function __construct(
        public string $id,
        public string $mediaItemId,
        public string $title,
        public string $source,     // 'local' | 'tmdb'
        public string $url,
        public int $duration,        // seconds
        public int $quality,        // 480/720/1080/2160
        public bool $isLocal,
        public string $filePath,
    ) {}
}
```

### Extra DTO

```php
final readonly class Extra
{
    public const TYPE_FEATURETTE = 'featurette';
    public const TYPE_BEHIND_THE_SCENES = 'behind_the_scenes';
    public const TYPE_INTERVIEW = 'interview';
    public const TYPE_CLIP = 'clip';
    public const TYPE_DELETED_SCENE = 'deleted_scene';
    public const TYPE_TRAILER = 'trailer';

    public function __construct(
        public string $id,
        public string $mediaItemId,
        public string $title,
        public string $type,
        public string $source,
        public string $url,
        public int $duration,
        public int $quality,
        public bool $isLocal,
        public string $filePath,
    ) {}
}
```

## Database Schema

The `media_extras` table stores cached trailer and extra data:

```sql
CREATE TABLE media_extras (
    id CHAR(36) NOT NULL PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    title VARCHAR(256) NOT NULL,
    extra_type VARCHAR(32) NOT NULL,
    source VARCHAR(16) NOT NULL,
    url VARCHAR(1024) NOT NULL,
    file_path VARCHAR(1024) NULL,
    duration INT NOT NULL DEFAULT 0,
    quality INT NOT NULL DEFAULT 0,
    cached_at DATETIME NOT NULL,
    INDEX idx_me_media (media_item_id),
    INDEX idx_me_type (extra_type)
);
```

## Caching

Trailer and extra data is cached in the `media_extras` table with a **24-hour TTL**. Cache is refreshed:

1. When the TTL expires (on next request)
2. When `FolderWatcher` detects changes in `Trailers/` folders
3. When `MediaScanner` rescans the library

## TMDB Integration

Trailers from TMDB are fetched via the `TmdbProvider::getTrailers()` method. Local trailers take priority over TMDB trailers with the same title.

### TMDB Configuration

Ensure your `config/tmdb.php` has a valid API key:

```php
return [
    'api_key' => 'your_tmdb_api_key',
    // ...
];
```

## Folder Watcher Integration

The `FolderWatcher` detects changes in:

- `Trailers/` directories
- Files with `-trailer`, `-teaser`, `-clip`, `-featurette` suffixes

When detected, it signals that extras should be rescanned for the affected media item.

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                      ExtrasController                        │
│  GET /api/v1/media/{id}/extras                               │
│  GET /api/v1/media/{id}/trailers                            │
│  GET /api/v1/media/{id}/extras/other                        │
└─────────────────────┬──────────────────────────────────────┘
                      │
                      ▼
┌──────────────────────────────────────────────────────────────┐
│                     TrailerResolver                          │
│  - Merges local + TMDB trailers                             │
│  - Local takes priority over TMDB                           │
│  - Caches results in media_extras (24h TTL)                │
└──────┬──────────────────┬──────────────────┬────────────────────┘
       │                  │                  │
       ▼                  ▼                  ▼
┌─────────────┐  ┌─────────────┐  ┌─────────────────┐
│ ExtrasRepo │  │TrailerFinder│  │  TmdbProvider   │
│  (cache)   │  │ (scanning)  │  │  (remote)       │
└─────────────┘  └─────────────┘  └─────────────────┘
```

## Migration

Run the migration to create the `media_extras` table:

```bash
php scripts/run-migrations.php
```

## Testing

Run unit tests:

```bash
./vendor/bin/phpunit tests/unit/Media/Extras/
```

Run integration tests:

```bash
./vendor/bin/phpunit tests/integration/Media/Extras/
```

## See Also

- [Streaming Protocols](../developers/streaming-protocols.md)
- [TMDB Provider](../reference/api.md)
